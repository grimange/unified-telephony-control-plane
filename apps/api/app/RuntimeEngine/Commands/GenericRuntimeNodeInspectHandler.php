<?php

namespace App\RuntimeEngine\Commands;

use App\ControlPlane\RuntimeOperations\FailureClass;

final class GenericRuntimeNodeInspectHandler implements RuntimeOperationHandler
{
    public function operationType(): string
    {
        return 'runtime.node.inspect';
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return 'runtime.observation';
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        if ($adapter === null) {
            return [
                'status' => 'terminal_failure',
                'failure_class' => FailureClass::UnsupportedCapability->value,
                'failure_code' => 'runtime_adapter_missing',
                'failure_message' => 'runtime adapter is not registered',
            ];
        }

        return $adapter->execute($operation);
    }
}
