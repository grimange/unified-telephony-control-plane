<?php

namespace App\RuntimeEngine\Events;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Projection\ProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EventNormalizerWorker
{
    public function __construct(
        private readonly RuntimeEventReceiptRepository $receipts,
        private readonly EventNormalizerRegistry $normalizers,
        private readonly ProjectionService $projection,
    ) {}

    public function workOnce(string $workerId, int $batchSize = 10): int
    {
        $processed = 0;

        foreach ($this->receipts->claimAvailable($workerId, $batchSize) as $claim) {
            $receipt = $this->receipts->find($claim->id);
            if ($receipt === null) {
                continue;
            }

            $normalizer = $this->normalizers->get(
                (string) $receipt->adapter_key,
                (string) $receipt->event_type,
                (int) $receipt->event_version,
            );

            if ($normalizer === null) {
                $this->receipts->markFailed(
                    $claim->id,
                    $claim->leaseToken,
                    FailureClass::UnsupportedCapability->value,
                    'normalizer_not_registered',
                    false,
                );
                Log::warning('runtime event normalization unsupported', [
                    'component' => 'telephony-event-normalizer',
                    'adapter_key' => (string) $receipt->adapter_key,
                    'event_type' => (string) $receipt->event_type,
                    'result' => 'unsupported',
                    'failure_class' => FailureClass::UnsupportedCapability->value,
                ]);

                continue;
            }

            $payload = json_decode((string) $receipt->sanitized_payload, true, flags: JSON_THROW_ON_ERROR);

            try {
                DB::transaction(function () use ($normalizer, $receipt, $payload, $claim): void {
                    $observations = $normalizer->normalize($receipt, is_array($payload) ? $payload : []);
                    $this->projection->apply($receipt, $observations);
                    if (! $this->receipts->markProcessed($claim->id, $claim->leaseToken)) {
                        throw new \RuntimeException('runtime-event receipt fencing token was superseded');
                    }
                });

                Log::info('runtime event normalized', [
                    'component' => 'telephony-event-normalizer',
                    'adapter_key' => (string) $receipt->adapter_key,
                    'event_type' => (string) $receipt->event_type,
                    'result' => 'processed',
                    'attempt' => $claim->attempt,
                ]);
                $processed++;
            } catch (\Throwable $exception) {
                $retryable = $claim->attempt < 3;
                $this->receipts->markFailed(
                    $claim->id,
                    $claim->leaseToken,
                    FailureClass::InternalError->value,
                    'normalization_failed',
                    $retryable,
                );
                Log::warning('runtime event normalization failed', [
                    'component' => 'telephony-event-normalizer',
                    'adapter_key' => (string) $receipt->adapter_key,
                    'event_type' => (string) $receipt->event_type,
                    'result' => $retryable ? 'retry_scheduled' : 'terminal_failed',
                    'failure_class' => FailureClass::InternalError->value,
                    'attempt' => $claim->attempt,
                ]);
            }
        }

        return $processed;
    }
}
