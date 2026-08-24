<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\RuntimeProvisioning\ManagedFreeSwitchProvisioningOperationHandler;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FreeSwitchRuntimeNodeReconciler implements Reconciler
{
    public function __construct(
        private readonly FreeSwitchCatalog $catalog,
        private readonly ?RuntimeRegistryService $registry = null,
        private readonly ?KubernetesWorkloadClient $kubernetes = null,
        private readonly ?ManagedFreeSwitchProvisioningOperationHandler $provisioning = null,
    ) {}

    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function supports(object $target): bool
    {
        $node = DB::table('runtime_nodes')->where('id', $target->target_id)->first();

        return $node !== null && $node->adapter_key === $this->catalog->adapterKey();
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $node = DB::table('runtime_nodes')->where('id', $target->target_id)->first();
        if ($node === null || $node->adapter_key !== $this->catalog->adapterKey()) {
            return ReconciliationResult::unsupported('freeswitch_adapter_not_selected');
        }
        if (in_array($node->desired_state, ['draft', 'retired'], true)) {
            return ReconciliationResult::converged(300);
        }
        if ($node->desired_state === 'disabled') {
            return ReconciliationResult::waiting('runtime_node_disabled', 120);
        }
        if (! $this->hasRequiredShape((string) $node->id)) {
            return ReconciliationResult::blocked('freeswitch_esl_configuration_incomplete');
        }
        if ($this->registry !== null && $this->registry->isManagedNode((string) $node->tenant_id, (string) $node->id)) {
            if (! $this->convergeManagedDeployment($node)) {
                return ReconciliationResult::waiting('managed_deployment_convergence_failed', 30);
            }
            $this->registry->ensureManagedCapabilities(ExecutionContext::system(tenantId: (string) $node->tenant_id, reason: 'managed FreeSWITCH capability convergence', origin: 'runtime-engine'), (string) $node->tenant_id, (string) $node->id, config('runtime_registry.adapter_keys.freeswitch-esl.supported_capabilities', []));
            $node = DB::table('runtime_nodes')->where('id', $node->id)->first();
        }
        $observedGeneration = $node->observed_configuration_version === null ? 0 : (int) $node->observed_configuration_version;
        if ($node->observed_state === 'ready' && $observedGeneration >= (int) $node->configuration_version) {
            return ReconciliationResult::converged(120);
        }

        return ReconciliationResult::operationRequired('runtime.node.inspect', ['runtime_node_id' => $node->id, 'configuration_generation' => (int) $node->configuration_version, 'reason' => 'freeswitch_esl_readiness_missing'], 'freeswitch_esl_readiness_missing');
    }

    private function convergeManagedDeployment(object $node): bool
    {
        if ($this->kubernetes === null || $this->provisioning === null) {
            return true;
        }
        try {
            $this->kubernetes->applyDeployment($this->provisioning->desiredDeployment((string) $node->id, (string) $node->slug), (string) $node->slug);

            return true;
        } catch (KubernetesWorkloadClientException $exception) {
            Log::warning('managed FreeSWITCH Deployment convergence failed', ['runtime_node_id' => (string) $node->id, 'reason' => $exception->reason]);

            return false;
        }
    }

    private function hasRequiredShape(string $nodeId): bool
    {
        return DB::table('runtime_node_endpoints')->where('runtime_node_id', $nodeId)->where('purpose', 'control')->whereIn('transport', ['tcp', 'tls'])->where('enabled', true)->exists()
            && DB::table('runtime_node_endpoints')->where('runtime_node_id', $nodeId)->where('purpose', 'sip')->where('transport', 'udp')->where('enabled', true)->exists()
            && DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $this->catalog->credentialType())->where('status', 'active')->exists();
    }
}
