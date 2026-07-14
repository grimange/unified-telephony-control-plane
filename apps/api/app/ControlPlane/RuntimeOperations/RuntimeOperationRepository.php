<?php

namespace App\ControlPlane\RuntimeOperations;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\ControlPlane\Shared\StableJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RuntimeOperationRepository
{
    public function __construct(
        private readonly OperationStateMachine $stateMachine = new OperationStateMachine,
        private readonly OperationLogger $logger = new OperationLogger,
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
        $id = RuntimeOperationId::new()->value();
        $safePayload = PayloadSafety::assertSafe($payload);

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
    public function claimAvailable(string $leaseOwner, int $batchSize = 1, int $leaseSeconds = 60): array
    {
        if ($batchSize < 1 || $batchSize > 100) {
            throw new \InvalidArgumentException('batch size must be 1-100');
        }

        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
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

                $claimed[] = new ClaimedOperation($row->id, $leaseOwner, $token, $attempt);
            }

            return $claimed;
        });
    }

    public function markRunning(string $id, string $leaseToken): void
    {
        $this->transitionWithFence($id, $leaseToken, OperationStatus::Running, []);
    }

    public function complete(string $id, string $leaseToken, EventEnvelope $event, OutboxRepository $outbox): void
    {
        DB::transaction(function () use ($id, $leaseToken, $event, $outbox): void {
            $this->transitionWithFence($id, $leaseToken, OperationStatus::Succeeded, [
                'completed_at' => now(),
            ]);
            $outbox->append($event);
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
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function transitionWithFence(string $id, string $leaseToken, OperationStatus $to, array $fields): void
    {
        $row = $this->lockedOperation($id);
        $this->assertFence($row, $leaseToken);
        $this->stateMachine->assertTransition(OperationStatus::from($row->status), $to);

        DB::table('runtime_operations')->where('id', $id)->update(array_merge($fields, [
            'status' => $to->value,
            'updated_at' => now(),
        ]));
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
}
