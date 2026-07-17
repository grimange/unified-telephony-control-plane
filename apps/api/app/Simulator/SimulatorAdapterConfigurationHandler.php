<?php

namespace App\Simulator;

use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationHandler;

final class SimulatorAdapterConfigurationHandler implements AdapterConfigurationHandler
{
    public function __construct(
        private readonly SimulatorCatalog $catalog,
        private readonly SimulatorProfileService $profiles,
    ) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    public function supports(object $runtimeNode): bool
    {
        return $runtimeNode->adapter_key === $this->adapterKey();
    }

    public function read(object $runtimeNode, ExecutionContext $context): array
    {
        return $this->profiles->show((string) $runtimeNode->tenant_id, (string) $runtimeNode->id);
    }

    public function validate(object $runtimeNode, array $payload, ExecutionContext $context): array
    {
        return [
            'scenario_key' => (string) ($payload['scenario_key'] ?? ''),
            'scenario_version' => (int) ($payload['scenario_version'] ?? 1),
            'seed' => (string) ($payload['seed'] ?? 'local'),
            'parameters' => is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [],
        ];
    }

    public function update(object $runtimeNode, array $payload, ExecutionContext $context): array
    {
        return $this->profiles->put($context, (string) $runtimeNode->tenant_id, (string) $runtimeNode->id, $this->validate($runtimeNode, $payload, $context));
    }
}
