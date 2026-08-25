<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\RuntimeProvisioning\ManagedAsteriskProvisioningOperationHandler;
use App\RuntimeRegistry\RuntimeExecutionContract;
use App\RuntimeRegistry\RuntimeRegistryService;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AsteriskRuntimeNodeReconciler implements Reconciler
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly ?RuntimeRegistryService $registry = null,
        private readonly ?KubernetesWorkloadClient $kubernetes = null,
        private readonly ?ManagedAsteriskProvisioningOperationHandler $provisioning = null,
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
            return ReconciliationResult::unsupported('asterisk_adapter_not_selected');
        }
        if ($node->desired_state === 'draft') {
            return ReconciliationResult::converged(300);
        }
        if ($node->desired_state === 'disabled') {
            return in_array($node->observed_state, ['unobserved', 'unavailable', 'stale'], true)
                ? ReconciliationResult::converged(300)
                : ReconciliationResult::waiting('runtime_node_disabled', 120);
        }
        if ($node->desired_state === 'retired') {
            return ReconciliationResult::converged(300);
        }

        if (! $this->hasRequiredShape((string) $node->id)) {
            return ReconciliationResult::blocked('asterisk_ari_configuration_incomplete');
        }

        if ($this->registry !== null && $this->registry->isManagedNode((string) $node->tenant_id, (string) $node->id)) {
            $image = (string) config('asterisk_ari.managed_image', '');
            if (! preg_match('/^[^\/\s]+\/utcp\/asterisk-ari@sha256:[0-9a-f]{64}$/', $image)) {
                return ReconciliationResult::blocked('managed_asterisk_image_invalid');
            }
            $this->registry->ensureManagedExecutionImage(
                ExecutionContext::system(tenantId: (string) $node->tenant_id, reason: 'managed Asterisk execution image convergence', origin: 'runtime-engine'),
                (string) $node->tenant_id,
                (string) $node->id,
                $image,
            );
            if (! $this->convergeManagedDeployment($node)) {
                return ReconciliationResult::waiting('managed_deployment_convergence_failed', 30);
            }

            $this->registry->ensureManagedCapabilities(
                ExecutionContext::system(tenantId: (string) $node->tenant_id, reason: 'managed Asterisk capability convergence', origin: 'runtime-engine'),
                (string) $node->tenant_id,
                (string) $node->id,
                config('runtime_registry.adapter_keys.asterisk-ari.supported_capabilities', []),
            );
            $node = DB::table('runtime_nodes')->where('id', $node->id)->first();
            $this->observeManagedImage($node);
            $node = DB::table('runtime_nodes')->where('id', $node->id)->first();
        }

        $lastOperation = $target->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $target->last_operation_id)->first();
        if ($lastOperation !== null && $lastOperation->status === 'terminal_failed') {
            $class = (string) ($lastOperation->last_failure_class ?? '');
            if ($class === 'authentication_failed') {
                return ReconciliationResult::blocked('asterisk_ari_authentication_failed');
            }

            return ReconciliationResult::waiting('asterisk_ari_inspection_failed', 120);
        }
        if ($lastOperation !== null && ! in_array($lastOperation->status, ['succeeded', 'cancelled', 'expired'], true)) {
            return ReconciliationResult::waiting('runtime_operation_in_progress', 30);
        }

        $observedGeneration = $node->observed_configuration_version === null ? 0 : (int) $node->observed_configuration_version;
        if ($node->observed_state === 'ready'
            && $observedGeneration >= (int) $node->configuration_version
            && ($node->desired_execution_image === null || RuntimeExecutionContract::isCurrent($node->desired_execution_image, $node->observed_execution_image))
        ) {
            return ReconciliationResult::converged(120);
        }

        return ReconciliationResult::operationRequired('runtime.node.inspect', [
            'runtime_node_id' => $node->id,
            'configuration_generation' => (int) $node->configuration_version,
            'reason' => 'asterisk_ari_readiness_missing',
        ], 'asterisk_ari_readiness_missing');
    }

    private function convergeManagedDeployment(object $node): bool
    {
        if ($this->kubernetes === null || $this->provisioning === null) {
            return true;
        }

        try {
            $this->kubernetes->applyDeployment(
                $this->provisioning->desiredDeployment((string) $node->id, (string) $node->slug),
                (string) $node->slug,
            );

            return true;
        } catch (KubernetesWorkloadClientException $exception) {
            Log::warning('managed Asterisk Deployment convergence failed', [
                'component' => 'telephony-reconciler',
                'runtime_node_id' => (string) $node->id,
                'reason' => $exception->reason,
            ]);

            return false;
        }
    }

    private function hasRequiredShape(string $runtimeNodeId): bool
    {
        $hasControl = DB::table('runtime_node_endpoints')->where('runtime_node_id', $runtimeNodeId)->where('purpose', 'control')->whereIn('transport', ['http', 'https'])->where('enabled', true)->exists();
        $hasEvents = DB::table('runtime_node_endpoints')->where('runtime_node_id', $runtimeNodeId)->where('purpose', 'events')->whereIn('transport', ['ws', 'wss'])->where('enabled', true)->exists();
        $hasCredential = DB::table('runtime_node_credentials')->where('runtime_node_id', $runtimeNodeId)->where('credential_type', $this->catalog->credentialType())->where('status', 'active')->exists();
        $hasProfile = DB::table('asterisk_ari_profiles')->where('runtime_node_id', $runtimeNodeId)->exists();
        $hasObservation = DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', 'runtime.observation')->exists();
        $hasEventsCapability = DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', 'event.stream')->exists();

        return $hasControl && $hasEvents && $hasCredential && $hasProfile && $hasObservation && $hasEventsCapability;
    }

    private function observeManagedImage(object $node): void
    {
        if ($this->kubernetes === null) {
            return;
        }

        try {
            $labels = is_string($node->labels)
                ? json_decode($node->labels, true, 512, JSON_THROW_ON_ERROR)
                : ($node->labels ?? []);
            $workload = is_array($labels) ? ($labels['kubernetes_workload'] ?? null) : null;
            if (! is_array($workload) || ! isset($workload['namespace'], $workload['deployment'])) {
                return;
            }
            $pods = $this->kubernetes->listOwnedPods(
                (string) $workload['namespace'],
                new RuntimeNodeWorkloadIdentity((string) $workload['namespace'], (string) $workload['deployment']),
            );
            $image = null;
            foreach ($pods as $pod) {
                foreach (data_get($pod, 'status.containerStatuses', []) as $status) {
                    if (($status['name'] ?? '') === 'asterisk' && is_string($status['imageID'] ?? null) && $status['imageID'] !== '') {
                        $image = RuntimeExecutionContract::digest($status['imageID']);
                        break 2;
                    }
                }
            }
            DB::table('runtime_nodes')->where('id', $node->id)->update([
                'observed_execution_image' => $image,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('managed Asterisk execution image observation failed', [
                'runtime_node_id' => (string) $node->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
