<?php

namespace App\RuntimeEngine\Reconciliation;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\EngineIds;
use Illuminate\Support\Facades\DB;

final class ReconciliationRepository
{
    private const AGGREGATE_TYPE = 'runtime_reconciliation';

    private const EVENT_CREATED = 'runtime_reconciliation.created';

    private const EVENT_DRIFT_DETECTED = 'runtime_reconciliation.drift_detected';

    private const EVENT_RECONCILIATION_STARTED = 'runtime_reconciliation.reconciliation_started';

    private const EVENT_CONVERGED = 'runtime_reconciliation.converged';

    private const EVENT_OPERATION_REQUIRED = 'runtime_reconciliation.operation_required';

    private const EVENT_FAILED = 'runtime_reconciliation.failed';

    private const EVENT_RETRY_SCHEDULED = 'runtime_reconciliation.retry_scheduled';

    public function __construct(private readonly OutboxRepository $outbox = new OutboxRepository) {}

    public function ensureTarget(string $tenantId, string $targetType, string $targetId, int $desiredGeneration, int $nextCheckSeconds = 0): string
    {
        return DB::transaction(function () use ($tenantId, $targetType, $targetId, $desiredGeneration, $nextCheckSeconds): string {
            $existing = DB::table('runtime_reconciliation_states')
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $generationAdvanced = $desiredGeneration > (int) $existing->desired_generation;

                DB::table('runtime_reconciliation_states')->where('id', $existing->id)->update([
                    'tenant_id' => $tenantId,
                    'desired_generation' => max((int) $existing->desired_generation, $desiredGeneration),
                    'status' => $generationAdvanced ? 'waiting' : $existing->status,
                    'last_operation_id' => $generationAdvanced ? null : $existing->last_operation_id,
                    'blocked_reason' => $generationAdvanced ? null : $existing->blocked_reason,
                    'next_check_at' => now()->addSeconds($nextCheckSeconds),
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

                if ($generationAdvanced) {
                    $this->appendReconciliationEvent(
                        self::EVENT_DRIFT_DETECTED,
                        (string) $existing->id,
                        $tenantId,
                        $targetType,
                        $targetId,
                    );
                }

                return (string) $existing->id;
            }

            $id = EngineIds::new();
            DB::table('runtime_reconciliation_states')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'desired_generation' => $desiredGeneration,
                'status' => 'waiting',
                'attempt_count' => 0,
                'next_check_at' => now()->addSeconds($nextCheckSeconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->appendReconciliationEvent(
                self::EVENT_CREATED,
                $id,
                $tenantId,
                $targetType,
                $targetId,
            );

            return $id;
        });
    }

    public function wakeTarget(string $tenantId, string $targetType, string $targetId, int $desiredGeneration, int $nextCheckSeconds = 0): string
    {
        return DB::transaction(function () use ($tenantId, $targetType, $targetId, $desiredGeneration, $nextCheckSeconds): string {
            $existing = DB::table('runtime_reconciliation_states')
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return $this->ensureTarget($tenantId, $targetType, $targetId, $desiredGeneration, $nextCheckSeconds);
            }

            $priorFingerprint = $this->transitionFingerprint($existing);
            $nextFingerprint = [
                'status' => 'waiting',
                'desired_generation' => max((int) $existing->desired_generation, $desiredGeneration),
                'observed_generation' => $existing->observed_generation === null ? null : (int) $existing->observed_generation,
                'last_operation_id' => null,
                'blocked_reason' => null,
            ];

            DB::table('runtime_reconciliation_states')->where('id', $existing->id)->update([
                'tenant_id' => $tenantId,
                'desired_generation' => $nextFingerprint['desired_generation'],
                'status' => $nextFingerprint['status'],
                'last_operation_id' => $nextFingerprint['last_operation_id'],
                'blocked_reason' => $nextFingerprint['blocked_reason'],
                'next_check_at' => now()->addSeconds($nextCheckSeconds),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

            if ($priorFingerprint !== $nextFingerprint) {
                $this->appendReconciliationEvent(
                    self::EVENT_DRIFT_DETECTED,
                    (string) $existing->id,
                    $tenantId,
                    $targetType,
                    $targetId,
                );
            }

            return (string) $existing->id;
        });
    }

    /**
     * @return list<object>
     */
    public function claimDue(string $leaseOwner, int $batchSize = 10, int $leaseSeconds = 60): array
    {
        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
            $query = DB::table('runtime_reconciliation_states')
                ->where('next_check_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', now());
                })
                ->orderByRaw("CASE status WHEN 'waiting' THEN 0 WHEN 'operation_required' THEN 1 WHEN 'blocked' THEN 2 WHEN 'converged' THEN 3 ELSE 2 END")
                ->orderByDesc('updated_at')
                ->orderBy('next_check_at')
                ->orderBy('created_at')
                ->limit($batchSize);

            if (DB::getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $row->lease_token = EngineIds::token();
                DB::table('runtime_reconciliation_states')->where('id', $row->id)->update([
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $row->lease_token,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'updated_at' => now(),
                ]);
            }

            return $rows->all();
        });
    }

    public function markResult(string $id, string $leaseToken, ReconciliationResult $result, ?string $operationId = null): bool
    {
        return DB::transaction(function () use ($id, $leaseToken, $result, $operationId): bool {
            $row = DB::table('runtime_reconciliation_states')
                ->where('id', $id)
                ->where('lease_token', $leaseToken)
                ->where('lease_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $priorFingerprint = $this->transitionFingerprint($row);
            $blockedReason = $result->status === 'blocked' ? mb_substr((string) $result->reasonCode, 0, 120) : null;
            $lastOperationId = $operationId ?? (is_string($row->last_operation_id) ? $row->last_operation_id : null);
            $nextFingerprint = [
                'status' => $result->status,
                'desired_generation' => (int) $row->desired_generation,
                'observed_generation' => $row->observed_generation === null ? null : (int) $row->observed_generation,
                'last_operation_id' => $lastOperationId,
                'blocked_reason' => $blockedReason,
            ];

            $updated = DB::table('runtime_reconciliation_states')
                ->where('id', $id)
                ->where('lease_token', $leaseToken)
                ->where('lease_expires_at', '>', now())
                ->update([
                    'status' => $result->status,
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addSeconds($result->nextCheckSeconds),
                    'last_operation_id' => $lastOperationId,
                    'blocked_reason' => $blockedReason,
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]) === 1;

            if (! $updated) {
                return false;
            }

            if ($priorFingerprint !== $nextFingerprint) {
                $this->appendReconciliationEvent(
                    $this->eventTypeForTransition($priorFingerprint, $nextFingerprint),
                    $id,
                    (string) $row->tenant_id,
                    (string) $row->target_type,
                    (string) $row->target_id,
                    $lastOperationId,
                );
            }

            return true;
        });
    }

    /**
     * @param  array{status:string,desired_generation:int,observed_generation:int|null,last_operation_id:string|null,blocked_reason:string|null}  $prior
     * @param  array{status:string,desired_generation:int,observed_generation:int|null,last_operation_id:string|null,blocked_reason:string|null}  $next
     */
    private function eventTypeForTransition(array $prior, array $next): string
    {
        if ($next['status'] === 'converged') {
            return self::EVENT_CONVERGED;
        }

        if ($next['status'] === 'operation_required' || $prior['last_operation_id'] !== $next['last_operation_id']) {
            return self::EVENT_OPERATION_REQUIRED;
        }

        if ($next['status'] === 'waiting' || $next['status'] === 'retry_scheduled') {
            return self::EVENT_RETRY_SCHEDULED;
        }

        if (in_array($next['status'], ['blocked', 'unsupported'], true) || $prior['blocked_reason'] !== $next['blocked_reason']) {
            return self::EVENT_FAILED;
        }

        return self::EVENT_DRIFT_DETECTED;
    }

    /**
     * @return array{status:string,desired_generation:int,observed_generation:int|null,last_operation_id:string|null,blocked_reason:string|null}
     */
    private function transitionFingerprint(object $row): array
    {
        return [
            'status' => (string) $row->status,
            'desired_generation' => (int) $row->desired_generation,
            'observed_generation' => $row->observed_generation === null ? null : (int) $row->observed_generation,
            'last_operation_id' => is_string($row->last_operation_id) ? $row->last_operation_id : null,
            'blocked_reason' => is_string($row->blocked_reason) ? $row->blocked_reason : null,
        ];
    }

    private function appendReconciliationEvent(
        string $eventType,
        string $runtimeReconciliationId,
        string $tenantId,
        string $targetType,
        string $targetId,
        ?string $runtimeOperationId = null,
    ): void {
        if ($tenantId === '') {
            return;
        }

        $payload = [
            'runtime_reconciliation_id' => $runtimeReconciliationId,
        ];
        if ($targetType === 'runtime_node' && $targetId !== '') {
            $payload['runtime_node_id'] = $targetId;
        }
        if ($runtimeOperationId !== null && $runtimeOperationId !== '') {
            $payload['runtime_operation_id'] = $runtimeOperationId;
        }

        $this->outbox->append(EventEnvelope::forAggregate(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: self::AGGREGATE_TYPE,
            aggregateId: $runtimeReconciliationId,
            payload: $payload,
            context: ExecutionContext::system(
                reason: 'runtime reconciliation state changed',
                tenantId: $tenantId,
                origin: 'runtime-reconciliation-repository',
            ),
        ));
    }
}
