<?php

namespace App\Simulator\Commands;

use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\Simulator\SimulatorCatalog;

final class SimulatorApplyConfigurationHandler implements RuntimeOperationHandler
{
    public function __construct(private readonly SimulatorCatalog $catalog) {}

    public function operationType(): string
    {
        return $this->catalog->operationType('apply_configuration');
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return 'runtime.configuration';
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        if (! $adapter instanceof SimulatorRuntimeAdapter) {
            return ['status' => 'terminal_failure', 'failure_class' => 'unsupported_capability', 'failure_code' => 'simulator_adapter_not_registered', 'failure_message' => 'simulator adapter is not registered'];
        }

        return $adapter->execute($operation);
    }
}
