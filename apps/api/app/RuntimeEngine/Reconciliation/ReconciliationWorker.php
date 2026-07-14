<?php

namespace App\RuntimeEngine\Reconciliation;

use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\PayloadSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReconciliationWorker
{
    public function __construct(
        private readonly ReconciliationRepository $repository,
        private readonly ReconcilerRegistry $reconcilers,
        private readonly RuntimeOperationRepository $operations,
    ) {}

    public function workOnce(string $workerId, int $batchSize = 10): int
    {
        $processed = 0;

        foreach ($this->repository->claimDue($workerId, $batchSize) as $claim) {
            $reconciler = $this->reconcilers->find((string) $claim->target_type);
            if ($reconciler === null) {
                $this->repository->markResult($claim->id, $claim->lease_token, ReconciliationResult::unsupported());
                Log::info('runtime reconciliation unsupported', [
                    'component' => 'telephony-reconciler',
                    'result' => 'unsupported',
                ]);

                continue;
            }

            $operationId = null;
            $result = $reconciler->evaluate($claim);

            $lastOperationTerminal = false;
            if ($claim->last_operation_id !== null) {
                $lastOperation = DB::table('runtime_operations')->where('id', $claim->last_operation_id)->first();
                $lastOperationTerminal = $lastOperation !== null && in_array($lastOperation->status, ['succeeded', 'terminal_failed', 'cancelled', 'expired'], true);
            }

            if ($result->status === 'operation_required' && ($claim->last_operation_id === null || $lastOperationTerminal)) {
                $operationId = DB::transaction(function () use ($result, $claim): string {
                    $context = ExecutionContext::system(
                        reason: 'runtime reconciliation',
                        tenantId: $claim->tenant_id,
                        origin: 'telephony-reconciler',
                    );
                    $payload = PayloadSafety::assertSafe($result->operationPayload);
                    $idempotencyKey = new IdempotencyKey(hash('sha256', implode(':', [
                        'reconcile',
                        $claim->target_type,
                        $claim->target_id,
                        (string) $claim->desired_generation,
                        (string) $result->operationType,
                    ])));

                    return $this->operations->create(
                        (string) $result->operationType,
                        (string) $claim->target_type,
                        (string) $claim->target_id,
                        $payload,
                        $context,
                        $idempotencyKey,
                        runtimeNodeId: $claim->target_type === 'runtime_node' ? (string) $claim->target_id : null,
                    );
                });
            } elseif ($result->status === 'operation_required') {
                $operationId = $claim->last_operation_id;
            }

            if (! $this->repository->markResult($claim->id, $claim->lease_token, $result, $operationId)) {
                throw new \RuntimeException('runtime reconciliation fencing token was superseded');
            }

            Log::info('runtime reconciliation completed', [
                'component' => 'telephony-reconciler',
                'result' => $result->status,
                'attempt' => (int) $claim->attempt_count + 1,
            ]);
            $processed++;
        }

        return $processed;
    }
}
