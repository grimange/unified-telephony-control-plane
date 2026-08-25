<?php

namespace App\RuntimeEngine\Outbox;

use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\TelephonyDomain\Projection\T6ProjectionDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxDispatcher
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly InboxRepository $inbox,
        private readonly OperationalBroadcastBridge $operationalBroadcasts = new OperationalBroadcastBridge,
        private readonly T6ProjectionDispatcher $t6Projections = new T6ProjectionDispatcher,
    ) {}

    public function dispatchOnce(string $workerId, int $batchSize = 10, int $leaseSeconds = 60): int
    {
        $count = 0;
        foreach ($this->outbox->claimAvailable($workerId, $batchSize, $leaseSeconds) as $claim) {
            try {
                $this->deliver($claim);
                if (! $this->outbox->markDispatchedWithFence($claim->id, $claim->leaseToken)) {
                    Log::warning('outbox dispatch fencing rejected', [
                        'component' => 'control-plane-outbox-dispatcher',
                        'event_type' => $claim->eventType,
                        'result' => 'fencing_rejected',
                    ]);

                    continue;
                }
                $count++;
                Log::info('outbox message dispatched', [
                    'component' => 'control-plane-outbox-dispatcher',
                    'event_type' => $claim->eventType,
                    'result' => 'dispatched',
                    'attempt' => $claim->attemptCount,
                ]);
            } catch (\Throwable $exception) {
                $this->outbox->markFailedWithFence(
                    $claim->id,
                    $claim->leaseToken,
                    'internal_error',
                    'queue_delivery_failed',
                    $exception->getMessage(),
                    true,
                );
            }
        }

        return $count;
    }

    private function deliver(OutboxClaim $claim): void
    {
        $row = DB::table('control_plane_outbox_messages')->where('id', $claim->id)->first();
        if ($row === null) {
            throw new \RuntimeException('claimed outbox row disappeared');
        }

        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $status = $this->inbox->receive(
            'control-plane-generic-consumer',
            $claim->id,
            (string) $row->event_type,
            is_array($payload) ? $payload : [],
        );

        if ($status === 'accepted' || $status === 'duplicate_pending') {
            $this->operationalBroadcasts->dispatchForOutboxRow($row);
            $this->t6Projections->dispatch((string) $row->aggregate_type, $row->tenant_id === null ? null : (string) $row->tenant_id);
            $this->inbox->markProcessed('control-plane-generic-consumer', $claim->id);
        }
    }
}
