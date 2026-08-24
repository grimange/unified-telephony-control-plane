<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RunsWithoutRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use Illuminate\Support\Facades\DB;

final class ManagedRuntimeDeprovisioningOperationHandler implements RunsWithoutRuntimeAdapter, RuntimeOperationHandler
{
    /** @var array<string, ManagedRuntimeProvisioningProvider> */
    private array $providers = [];

    /** @param iterable<ManagedRuntimeProvisioningProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->adapterKey()] = $provider;
        }
    }

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_deprovision', 'runtime.node.deprovision');
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
        $key = $this->canonicalAdapterKey($operation);
        $provider = $this->providers[$key] ?? null;
        if ($provider === null) {
            return ['status' => 'failed', 'failure_class' => FailureClass::InvalidRequest->value, 'failure_code' => 'managed_deprovisioning_adapter_not_registered', 'failure_message' => 'Managed runtime deprovisioning adapter is not registered.'];
        }

        return $provider->deprovisionOperation($operation, $adapter);
    }

    private function canonicalAdapterKey(array $operation): string
    {
        $nodeId = (string) ($operation['runtime_node_id'] ?? data_get($operation, 'payload.runtime_node_id', ''));
        if ($nodeId === '') {
            return '';
        }

        return (string) (DB::table('runtime_nodes')->where('id', $nodeId)->value('adapter_key') ?? '');
    }
}
