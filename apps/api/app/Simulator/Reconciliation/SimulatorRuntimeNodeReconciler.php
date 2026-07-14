<?php

namespace App\Simulator\Reconciliation;

use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\Simulator\SimulatorCatalog;
use Illuminate\Support\Facades\DB;

final class SimulatorRuntimeNodeReconciler implements Reconciler
{
    public function __construct(private readonly SimulatorCatalog $catalog) {}

    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $node = DB::table('runtime_nodes')->where('id', $target->target_id)->first();
        if ($node === null || $node->adapter_key !== $this->catalog->adapterKey()) {
            return ReconciliationResult::unsupported('simulator_adapter_not_selected');
        }

        $profile = DB::table('simulator_profiles')->where('runtime_node_id', $node->id)->first();
        if ($profile === null) {
            return ReconciliationResult::blocked('simulator_profile_missing');
        }

        if ($node->desired_state === 'draft') {
            return ReconciliationResult::waiting('runtime_node_draft', 300);
        }
        if ($node->desired_state === 'disabled') {
            return in_array($node->observed_state, ['unobserved', 'unavailable', 'stale'], true)
                ? ReconciliationResult::converged(300)
                : ReconciliationResult::waiting('runtime_node_disabled', 300);
        }

        $lastOperation = $target->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $target->last_operation_id)->first();
        if ($lastOperation !== null && $lastOperation->status === 'terminal_failed') {
            return ReconciliationResult::blocked((string) ($lastOperation->last_failure_code ?? 'runtime_operation_terminal_failed'));
        }
        if ($lastOperation !== null && ! in_array($lastOperation->status, ['succeeded', 'cancelled', 'expired'], true)) {
            return ReconciliationResult::waiting('runtime_operation_in_progress', 60);
        }

        $desiredGeneration = (int) $node->configuration_version;
        $observedGeneration = $node->observed_configuration_version === null ? 0 : (int) $node->observed_configuration_version;
        if ($node->observed_state === 'ready' && $observedGeneration >= $desiredGeneration) {
            return ReconciliationResult::converged(300);
        }

        if ($observedGeneration > 0 && $observedGeneration < $desiredGeneration) {
            return ReconciliationResult::operationRequired($this->catalog->operationType('apply_configuration'), [
                'runtime_node_id' => $node->id,
                'configuration_generation' => $desiredGeneration,
                'reason' => 'configuration_drift',
            ], 'configuration_drift');
        }

        return ReconciliationResult::operationRequired($this->catalog->operationType('inspect'), [
            'runtime_node_id' => $node->id,
            'configuration_generation' => $desiredGeneration,
            'reason' => 'missing_readiness_observation',
        ], 'missing_readiness_observation');
    }
}
