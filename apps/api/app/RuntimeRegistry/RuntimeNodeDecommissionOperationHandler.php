<?php

namespace App\RuntimeRegistry;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Commands\RunsWithoutRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;

final class RuntimeNodeDecommissionOperationHandler implements RunsWithoutRuntimeAdapter, RuntimeOperationHandler
{
    public function __construct(private readonly RuntimeRegistryService $registry) {}

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_decommission', 'runtime.node.decommission');
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return null;
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        unset($adapter);

        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $nodeId = (string) ($operation['runtime_node_id'] ?? '');
        $operationId = (string) ($operation['id'] ?? '');
        if ($tenantId === '' || $nodeId === '' || $operationId === '') {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_decommission_operation', 'runtime decommission operation authority is incomplete');
        }

        try {
            $this->registry->completeDecommission(
                ExecutionContext::system(
                    tenantId: $tenantId,
                    reason: 'runtime node decommission completed',
                    origin: 'runtime-engine',
                ),
                $tenantId,
                $nodeId,
                $operationId,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->failure(FailureClass::Conflict, 'decommission_authority_stale', $exception->getMessage());
        }

        return [
            'status' => 'completed',
            'event_type' => 'runtime_node.decommission.completed',
            'event_payload' => [
                'operation_id' => $operationId,
                'operation_type' => $this->operationType(),
                'runtime_node_id' => $nodeId,
                'utcp_authority_retired' => true,
                'physical_runtime_destroyed' => false,
            ],
        ];
    }

    /**
     * @return array{status:string,failure_class:string,failure_code:string,failure_message:string}
     */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return [
            'status' => 'terminal_failure',
            'failure_class' => $class->value,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }
}
