<?php

namespace App\ControlPlane\RuntimeOperations;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\CausationId;
use App\ControlPlane\Shared\CorrelationId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\RequestId;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\ControlPlane\Shared\StableJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RuntimeOperationRepository
{
    private const EVENT_CREATED = 'runtime_operation.created';

    private const EVENT_STATUS_CHANGED = 'runtime_operation.status_changed';

    public function __construct(
        private readonly OperationStateMachine $stateMachine = new OperationStateMachine,
        private readonly OperationLogger $logger = new OperationLogger,
        private readonly OutboxRepository $outbox = new OutboxRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        string $operationType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ExecutionContext $context,
        ?IdempotencyKey $idempotencyKey = null,
        int $payloadVersion = 1,
        int $priority = 100,
        int $maxAttempts = 3,
        ?string $runtimeNodeId = null,
    ): string {
        if ($idempotencyKey !== null) {
            $existingId = $this->findIdempotent($operationType, $idempotencyKey);
            if ($existingId !== null) {
                return $existingId;
            }
        }

        $id = RuntimeOperationId::new()->value();
        $safePayload = PayloadSafety::assertSafe($payload);

        DB::transaction(function () use ($id, $operationType, $aggregateType, $aggregateId, $safePayload, $context, $idempotencyKey, $payloadVersion, $priority, $maxAttempts, $runtimeNodeId): void {
            DB::table('runtime_operations')->insert([
                'id' => $id,
                'tenant_id' => $context->tenantId,
                'runtime_node_id' => $runtimeNodeId,
                'operation_type' => $operationType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload_version' => $payloadVersion,
                'payload' => StableJson::encode($safePayload),
                'status' => OperationStatus::Pending->value,
                'priority' => $priority,
                'idempotency_key' => $idempotencyKey?->value(),
                'correlation_id' => $context->correlationId->value(),
                'causation_id' => $context->causationId?->value(),
                'request_id' => $context->requestId->value(),
                'attempt_count' => 0,
                'max_attempts' => $maxAttempts,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->appendRuntimeOperationEvent(
                $this->outbox,
                self::EVENT_CREATED,
                $id,
                $context->tenantId,
                $runtimeNodeId,
                $context,
            );
        });

        $this->logger->info('runtime operation accepted', [
            'operation_id' => $id,
            'operation_type' => $operationType,
            'status' => OperationStatus::Pending->value,
            'attempt' => 0,
            'correlation_id' => $context->correlationId->value(),
            'request_id' => $context->requestId->value(),
        ]);

        return $id;
    }

    public function findIdempotent(string $operationType, IdempotencyKey $idempotencyKey): ?string
    {
        $row = DB::table('runtime_operations')
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $idempotencyKey->value())
            ->first();

        return $row?->id;
    }

    /**
     * @return list<ClaimedOperation>
     */
    /**
     * @param  list<string>  $includeOperationTypes
     * @param  list<string>  $excludeOperationTypes
     */
    public function claimAvailable(
        string $leaseOwner,
        int $batchSize = 1,
        int $leaseSeconds = 60,
        array $includeOperationTypes = [],
        array $excludeOperationTypes = [],
    ): array {
        if ($batchSize < 1 || $batchSize > 100) {
            throw new \InvalidArgumentException('batch size must be 1-100');
        }
        $includeOperationTypes = $this->normalizeOperationTypes($includeOperationTypes, 'includeOperationTypes');
        $excludeOperationTypes = $this->normalizeOperationTypes($excludeOperationTypes, 'excludeOperationTypes');

        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds, $includeOperationTypes, $excludeOperationTypes): array {
            $query = DB::table('runtime_operations')
                ->where(function ($query): void {
                    $query
                        ->whereIn('status', [OperationStatus::Pending->value, OperationStatus::RetryScheduled->value])
                        ->where('available_at', '<=', now())
                        ->orWhere(function ($query): void {
                            $query
                                ->whereIn('status', [OperationStatus::Leased->value, OperationStatus::Running->value])
                                ->where('lease_expires_at', '<', now());
                        });
                })
                ->when($includeOperationTypes !== [], fn ($query) => $query->whereIn('operation_type', $includeOperationTypes))
                ->when($excludeOperationTypes !== [], fn ($query) => $query->whereNotIn('operation_type', $excludeOperationTypes))
                ->orderBy('priority')
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->limit($batchSize);

            if (DB::getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $rows = $query->get();
            $claimed = [];

            foreach ($rows as $row) {
                $from = OperationStatus::from($row->status);
                $this->stateMachine->assertTransition($from, OperationStatus::Leased);
                $token = bin2hex(random_bytes(16));
                $attempt = (int) $row->attempt_count + 1;

                DB::table('runtime_operations')->where('id', $row->id)->update([
                    'status' => OperationStatus::Leased->value,
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $token,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => $attempt,
                    'started_at' => $row->started_at ?? now(),
                    'updated_at' => now(),
                ]);

                $this->appendRuntimeOperationEvent(
                    $this->outbox,
                    self::EVENT_STATUS_CHANGED,
                    (string) $row->id,
                    is_string($row->tenant_id) ? $row->tenant_id : null,
                    is_string($row->runtime_node_id) ? $row->runtime_node_id : null,
                    $this->contextFromOperationRow($row, 'runtime operation claimed'),
                );

                $claimed[] = new ClaimedOperation($row->id, $leaseOwner, $token, $attempt);
            }

            return $claimed;
        });
    }

    /**
     * @param  list<string>  $operationTypes
     * @return list<string>
     */
    private function normalizeOperationTypes(array $operationTypes, string $argument): array
    {
        $normalized = [];
        foreach ($operationTypes as $operationType) {
            if (! is_string($operationType) || trim($operationType) === '') {
                throw new \InvalidArgumentException($argument.' must contain non-empty operation type strings');
            }
            $normalized[] = trim($operationType);
        }

        return array_values(array_unique($normalized));
    }

    public function markRunning(string $id, string $leaseToken): void
    {
        DB::transaction(function () use ($id, $leaseToken): void {
            $row = $this->transitionWithFence($id, $leaseToken, OperationStatus::Running, []);
            $this->appendStatusChangedEvent($this->outbox, $row, 'runtime operation running');
        });
    }

    public function complete(string $id, string $leaseToken, EventEnvelope $event, OutboxRepository $outbox): void
    {
        DB::transaction(function () use ($id, $leaseToken, $event, $outbox): void {
            $operation = $this->lockedOperation($id);
            $this->transitionWithFence($id, $leaseToken, OperationStatus::Succeeded, [
                'completed_at' => now(),
            ]);
            DB::table('runtime_reconciliation_states')
                ->where(function ($query) use ($id, $operation): void {
                    $query
                        ->where('last_operation_id', $id)
                        ->orWhere(function ($query) use ($operation): void {
                            $query
                                ->where('target_type', $operation->aggregate_type)
                                ->where('target_id', $operation->aggregate_id);
                        });
                })
                ->whereNotIn('status', ['converged'])
                ->update([
                    'status' => 'waiting',
                    'next_check_at' => now(),
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            $outbox->append($event);
            $this->appendStatusChangedEvent($outbox, $operation, 'runtime operation completed');
        });
    }

    public function fail(string $id, string $leaseToken, FailureClass $failureClass, string $code, string $message): OperationStatus
    {
        return DB::transaction(function () use ($id, $leaseToken, $failureClass, $code, $message): OperationStatus {
            $row = $this->lockedOperation($id);
            $this->assertFence($row, $leaseToken);

            $next = $failureClass->retryable() && ((int) $row->attempt_count < (int) $row->max_attempts)
                ? OperationStatus::RetryScheduled
                : OperationStatus::TerminalFailed;

            $this->stateMachine->assertTransition(OperationStatus::from($row->status), $next);

            DB::table('runtime_operations')->where('id', $id)->update([
                'status' => $next->value,
                'available_at' => $next === OperationStatus::RetryScheduled
                    ? now()->addSeconds($failureClass->retryDelaySeconds((int) $row->attempt_count))
                    : $row->available_at,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_failure_class' => $failureClass->value,
                'last_failure_code' => mb_substr($code, 0, 120),
                'last_failure_message' => mb_substr(PayloadSafety::redact(['message' => $message])['message'], 0, 512),
                'completed_at' => $next === OperationStatus::TerminalFailed ? now() : null,
                'updated_at' => now(),
            ]);

            $this->appendStatusChangedEvent($this->outbox, $row, 'runtime operation failed');

            return $next;
        });
    }

    public function cancel(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = $this->lockedOperation($id);
            $this->stateMachine->assertTransition(OperationStatus::from($row->status), OperationStatus::Cancelled);

            DB::table('runtime_operations')->where('id', $id)->update([
                'status' => OperationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

            $this->appendStatusChangedEvent($this->outbox, $row, 'runtime operation cancelled');
        });
    }

    public function expire(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = $this->lockedOperation($id);
            $this->stateMachine->assertTransition(OperationStatus::from($row->status), OperationStatus::Expired);

            DB::table('runtime_operations')->where('id', $id)->update([
                'status' => OperationStatus::Expired->value,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->appendStatusChangedEvent($this->outbox, $row, 'runtime operation expired');
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function transitionWithFence(string $id, string $leaseToken, OperationStatus $to, array $fields): object
    {
        $row = $this->lockedOperation($id);
        $this->assertFence($row, $leaseToken);
        $this->stateMachine->assertTransition(OperationStatus::from($row->status), $to);

        DB::table('runtime_operations')->where('id', $id)->update(array_merge($fields, [
            'status' => $to->value,
            'updated_at' => now(),
        ]));

        return $row;
    }

    private function lockedOperation(string $id): object
    {
        $row = DB::table('runtime_operations')->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            throw new \RuntimeException('runtime operation not found');
        }

        return $row;
    }

    private function assertFence(object $row, string $leaseToken): void
    {
        $leaseExpiresAt = $row->lease_expires_at === null ? null : Carbon::parse($row->lease_expires_at);

        if ($row->lease_token !== $leaseToken || $leaseExpiresAt === null || $leaseExpiresAt->lessThanOrEqualTo(now())) {
            throw new FencingViolation('runtime operation lease token is expired or superseded');
        }
    }

    private function appendStatusChangedEvent(OutboxRepository $outbox, object $row, string $reason): void
    {
        $this->appendRuntimeOperationEvent(
            $outbox,
            self::EVENT_STATUS_CHANGED,
            (string) $row->id,
            is_string($row->tenant_id) ? $row->tenant_id : null,
            is_string($row->runtime_node_id) ? $row->runtime_node_id : null,
            $this->contextFromOperationRow($row, $reason),
        );
    }

    private function appendRuntimeOperationEvent(
        OutboxRepository $outbox,
        string $eventType,
        string $runtimeOperationId,
        ?string $tenantId,
        ?string $runtimeNodeId,
        ExecutionContext $context,
    ): void {
        if ($tenantId === null || $tenantId === '') {
            return;
        }

        $payload = [
            'runtime_operation_id' => $runtimeOperationId,
        ];
        if ($runtimeNodeId !== null && $runtimeNodeId !== '') {
            $payload['runtime_node_id'] = $runtimeNodeId;
        }

        $outbox->append(EventEnvelope::forAggregate(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: 'runtime_operation',
            aggregateId: $runtimeOperationId,
            payload: $payload,
            context: $context,
        ));
    }

    private function contextFromOperationRow(object $row, string $reason): ExecutionContext
    {
        return new ExecutionContext(
            requestId: RequestId::fromString((string) $row->request_id),
            correlationId: CorrelationId::fromString((string) $row->correlation_id),
            causationId: is_string($row->causation_id) ? CausationId::fromString($row->causation_id) : null,
            actorType: 'system',
            actorId: null,
            tenantId: is_string($row->tenant_id) ? $row->tenant_id : null,
            reason: $reason,
            origin: 'runtime-operation-repository',
            occurredAt: CarbonImmutable::now(),
        );
    }
}
