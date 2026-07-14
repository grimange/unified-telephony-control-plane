<?php

namespace App\RuntimeEngine\Commands;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\CausationId;
use App\ControlPlane\Shared\CorrelationId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RequestId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CommandWorker
{
    public function __construct(
        private readonly RuntimeOperationRepository $operations,
        private readonly OutboxRepository $outbox,
        private readonly RuntimeOperationHandlerRegistry $handlers,
        private readonly RuntimeAdapterRegistry $adapters,
    ) {}

    public function workOnce(string $workerId, int $batchSize = 10, int $leaseSeconds = 60): int
    {
        $processed = 0;
        foreach ($this->operations->claimAvailable($workerId, $batchSize, $leaseSeconds) as $claim) {
            $row = DB::table('runtime_operations')->where('id', $claim->id)->first();
            if ($row === null) {
                continue;
            }

            try {
                $this->operations->markRunning($claim->id, $claim->leaseToken);
                $handler = $this->handlers->get((string) $row->operation_type, (int) $row->payload_version);
                if ($handler === null) {
                    $this->operations->fail($claim->id, $claim->leaseToken, FailureClass::UnsupportedCapability, 'handler_not_registered', 'no registered runtime operation handler');

                    continue;
                }

                $adapter = $this->resolveAdapter($row, $handler);
                if ($adapter instanceof FailureClass) {
                    $this->operations->fail($claim->id, $claim->leaseToken, $adapter, 'adapter_not_available', 'runtime adapter was not available');

                    continue;
                }

                $result = $handler->execute($this->operationArray($row), $adapter);
                $status = $result['status'] ?? 'terminal_failure';
                if ($status === 'completed') {
                    $event = EventEnvelope::forAggregate(
                        $result['event_type'] ?? 'runtime_operation.completed',
                        1,
                        (string) $row->aggregate_type,
                        (string) $row->aggregate_id,
                        $result['event_payload'] ?? ['operation_type' => $row->operation_type],
                        $this->contextFromRow($row),
                    );
                    $this->operations->complete($claim->id, $claim->leaseToken, $event, $this->outbox);
                    $processed++;
                    Log::info('runtime operation completed', [
                        'component' => 'telephony-command-worker',
                        'operation_type' => $row->operation_type,
                        'operation_status' => 'succeeded',
                        'result' => 'completed',
                        'attempt' => $claim->attemptCount,
                    ]);

                    continue;
                }

                $failureClass = FailureClass::tryFrom((string) ($result['failure_class'] ?? 'internal_error')) ?? FailureClass::InternalError;
                $this->operations->fail(
                    $claim->id,
                    $claim->leaseToken,
                    $failureClass,
                    (string) ($result['failure_code'] ?? 'handler_failed'),
                    (string) ($result['failure_message'] ?? 'runtime operation handler failed'),
                );
            } catch (\Throwable $exception) {
                $this->operations->fail($claim->id, $claim->leaseToken, FailureClass::InternalError, 'worker_exception', $exception->getMessage());
            }
        }

        return $processed;
    }

    private function resolveAdapter(object $operation, RuntimeOperationHandler $handler): RuntimeAdapter|FailureClass|null
    {
        if ($operation->runtime_node_id === null) {
            return null;
        }

        $node = DB::table('runtime_nodes')->where('id', $operation->runtime_node_id)->first();
        if ($node === null || $node->tenant_id !== $operation->tenant_id) {
            return FailureClass::InvalidRequest;
        }
        if (! in_array($node->desired_state, ['active', 'draining'], true)) {
            return FailureClass::Conflict;
        }
        $requiredCapability = $handler->requiredRuntimeCapability();
        if ($requiredCapability !== null && ! DB::table('runtime_node_capabilities')->where('runtime_node_id', $node->id)->where('capability_key', $requiredCapability)->exists()) {
            return FailureClass::UnsupportedCapability;
        }

        return $this->adapters->get((string) $node->adapter_key) ?? FailureClass::UnsupportedCapability;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationArray(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'operation_type' => $row->operation_type,
            'aggregate_type' => $row->aggregate_type,
            'aggregate_id' => $row->aggregate_id,
            'runtime_node_id' => $row->runtime_node_id,
            'payload_version' => (int) $row->payload_version,
            'payload' => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            'attempt_count' => (int) $row->attempt_count,
        ];
    }

    private function contextFromRow(object $row): ExecutionContext
    {
        return new ExecutionContext(
            requestId: RequestId::fromString((string) $row->request_id),
            correlationId: CorrelationId::fromString((string) $row->correlation_id),
            causationId: is_string($row->causation_id) ? CausationId::fromString($row->causation_id) : null,
            actorType: 'system',
            actorId: null,
            tenantId: $row->tenant_id,
            reason: 'runtime operation execution',
            origin: 'runtime-engine',
            occurredAt: CarbonImmutable::now(),
        );
    }
}
