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
use App\TelephonyDomain\CallDomainService;
use App\RuntimeRegistry\RuntimeExecutionContract;
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
        private readonly CallDomainService $calls,
    ) {}

    /**
     * @param  list<string>  $includeOperationTypes
     * @param  list<string>  $excludeOperationTypes
     */
    public function workOnce(
        string $workerId,
        int $batchSize = 10,
        int $leaseSeconds = 60,
        array $includeOperationTypes = [],
        array $excludeOperationTypes = [],
    ): int {
        $includeOperationTypes = $this->assertKnownOperationTypes($includeOperationTypes, 'includeOperationTypes');
        $excludeOperationTypes = $this->assertKnownOperationTypes($excludeOperationTypes, 'excludeOperationTypes');
        $processed = 0;
        foreach ($this->operations->claimAvailable(
            $workerId,
            $batchSize,
            $leaseSeconds,
            $includeOperationTypes,
            $excludeOperationTypes,
        ) as $claim) {
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
                    $this->calls->applySuccessfulCallOperation(
                        (string) $row->tenant_id,
                        (string) $row->operation_type,
                        (string) $row->aggregate_type,
                        (string) $row->aggregate_id,
                    );
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

    /**
     * @param  list<string>  $operationTypes
     * @return list<string>
     */
    private function assertKnownOperationTypes(array $operationTypes, string $argument): array
    {
        $known = $this->handlers->operationTypes();
        $normalized = [];
        foreach ($operationTypes as $operationType) {
            if (! is_string($operationType) || trim($operationType) === '') {
                throw new \InvalidArgumentException($argument.' must contain non-empty operation type strings');
            }
            $operationType = trim($operationType);
            if (! in_array($operationType, $known, true)) {
                throw new \InvalidArgumentException($argument.' contains an unknown runtime operation type: '.$operationType);
            }
            $normalized[] = $operationType;
        }

        return array_values(array_unique($normalized));
    }

    private function resolveAdapter(object $operation, RuntimeOperationHandler $handler): RuntimeAdapter|FailureClass|null
    {
        if ($handler instanceof RunsWithoutRuntimeAdapter) {
            return null;
        }

        if ($operation->runtime_node_id === null) {
            return null;
        }

        $node = DB::table('runtime_nodes')->where('id', $operation->runtime_node_id)->first();
        if ($node === null || $node->tenant_id !== $operation->tenant_id) {
            return FailureClass::InvalidRequest;
        }
        $allowedDesiredStates = ['active', 'draining'];
        if ($handler instanceof RunsOnDisabledRuntimeNode) {
            $allowedDesiredStates[] = 'disabled';
        }
        if (! in_array($node->desired_state, $allowedDesiredStates, true)) {
            return FailureClass::Conflict;
        }
        if ($this->requiresFreshExecutionContract($operation, $node)
            && ($node->observed_state !== 'ready'
                || $node->observed_configuration_version === null
                || (int) $node->observed_configuration_version < (int) $node->configuration_version
                || $node->desired_execution_image === null
                || $node->observed_execution_image === null
                || ! RuntimeExecutionContract::isCurrent($node->desired_execution_image, $node->observed_execution_image))
        ) {
            return FailureClass::Conflict;
        }
        $requiredCapability = $handler->requiredRuntimeCapability();
        if ($requiredCapability !== null && ! DB::table('runtime_node_capabilities')->where('runtime_node_id', $node->id)->where('capability_key', $requiredCapability)->exists()) {
            return FailureClass::UnsupportedCapability;
        }

        return $this->adapters->get((string) $node->adapter_key) ?? FailureClass::UnsupportedCapability;
    }

    private function requiresFreshExecutionContract(object $operation, object $node): bool
    {
        return (string) $operation->operation_type === 'call.leg.originate'
            && in_array((string) $node->adapter_key, ['asterisk-ari', 'freeswitch-esl'], true)
            && $node->desired_execution_image !== null;
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
            'created_at' => $row->created_at,
            'started_at' => $row->started_at,
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
