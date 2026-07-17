<?php

namespace App\RuntimeAdapters\Asterisk;

use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Illuminate\Support\Facades\DB;

final class AsteriskRuntimeNodeReconciler implements Reconciler
{
    public function __construct(private readonly AsteriskCatalog $catalog) {}

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

        if (! $this->hasRequiredShape((string) $node->id)) {
            return ReconciliationResult::blocked('asterisk_ari_configuration_incomplete');
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
        if ($node->observed_state === 'ready' && $observedGeneration >= (int) $node->configuration_version) {
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
