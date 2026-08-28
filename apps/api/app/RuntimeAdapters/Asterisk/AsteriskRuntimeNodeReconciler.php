<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\RuntimeRegistry\RuntimeExecutionContract;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;

final class AsteriskRuntimeNodeReconciler implements Reconciler
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly ?RuntimeRegistryService $registry = null,
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

        $lastOperation = $target->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $target->last_operation_id)->first();

        if ($this->registry !== null && $this->registry->isManagedNode((string) $node->tenant_id, (string) $node->id)) {
            $image = (string) config('asterisk_ari.managed_image', '');
            if (! RuntimeExecutionContract::isQualifiedImmutableImageReference($image)) {
                return ReconciliationResult::blocked('managed_asterisk_image_invalid');
            }
            $this->registry->ensureManagedExecutionImage(
                ExecutionContext::system(tenantId: (string) $node->tenant_id, reason: 'managed Asterisk execution image convergence', origin: 'runtime-engine'),
                (string) $node->tenant_id,
                (string) $node->id,
                $image,
            );
            $convergeType = (string) config('telephony_domain.operation_types.runtime_node_workload_converge', 'runtime.node.workload.converge');
            if ($lastOperation === null || (string) $lastOperation->operation_type !== $convergeType || $lastOperation->status === 'terminal_failed') {
                if ($lastOperation !== null && (string) $lastOperation->operation_type === $convergeType && ! in_array($lastOperation->status, ['succeeded', 'cancelled', 'expired', 'terminal_failed'], true)) {
                    return ReconciliationResult::waiting('runtime_operation_in_progress', 30);
                }

                return ReconciliationResult::operationRequired($convergeType, [
                    'runtime_node_id' => $node->id,
                    'configuration_generation' => (int) $node->configuration_version,
                    'reason' => 'managed_deployment_convergence_required',
                ], 'managed_deployment_convergence_required', (string) $node->id);
            }

            $this->registry->ensureManagedCapabilities(
                ExecutionContext::system(tenantId: (string) $node->tenant_id, reason: 'managed Asterisk capability convergence', origin: 'runtime-engine'),
                (string) $node->tenant_id,
                (string) $node->id,
                config('runtime_registry.adapter_keys.asterisk-ari.supported_capabilities', []),
            );
            $node = DB::table('runtime_nodes')->where('id', $node->id)->first();
        }

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

}
