<?php

namespace App\TelephonyDomain\Reconciliation;

use App\RuntimeEngine\Commands\RuntimeConferenceInspectionService;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Illuminate\Support\Facades\DB;

final class ConferenceReconciler implements Reconciler
{
    public function __construct(
        private readonly RuntimeConferenceInspectionService $inspections,
    ) {}

    public function targetType(): string
    {
        return 'conference';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $conference = DB::table('conferences')->where('id', $target->target_id)->first();
        if ($conference === null || $conference->tenant_id !== $target->tenant_id) {
            return ReconciliationResult::unsupported('conference_not_found');
        }
        if ($conference->desired_state === 'draft') {
            return $conference->observed_state === 'unobserved'
                ? ReconciliationResult::converged(300)
                : ReconciliationResult::waiting('conference_draft_waiting_for_absence', 300);
        }
        $runtimeNodeId = $this->activeRuntimeNodeId((string) $conference->tenant_id, (string) $conference->id);
        if ($runtimeNodeId === null) {
            return ReconciliationResult::blocked('conference_runtime_binding_missing');
        }
        if (! DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', 'conference.lifecycle')->exists()) {
            return ReconciliationResult::blocked('conference_lifecycle_capability_missing');
        }

        if ($conference->desired_state === 'closed' && $conference->observed_state === 'closed') {
            return ReconciliationResult::converged(300);
        }

        $last = $target->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $target->last_operation_id)->first();
        if ($last !== null && $last->status === 'terminal_failed') {
            return ReconciliationResult::blocked((string) ($last->last_failure_code ?? 'conference_operation_terminal_failed'));
        }
        if ($last !== null && ! in_array($last->status, ['succeeded', 'cancelled', 'expired'], true)) {
            return ReconciliationResult::waiting('runtime_operation_in_progress', 60);
        }

        $generation = (int) $conference->configuration_generation;
        $observedGeneration = $conference->observed_generation === null ? 0 : (int) $conference->observed_generation;
        if ($conference->desired_state === 'open') {
            $inspection = $this->inspections->inspect((string) $conference->tenant_id, $runtimeNodeId, (string) $conference->id);
            if ($inspection->status === 'unavailable' || $inspection->status === 'failed') {
                return ReconciliationResult::waiting('conference_runtime_inspection_unavailable', 30);
            }
            if ($inspection->status === 'unsupported') {
                return ReconciliationResult::waiting('conference_runtime_inspection_unsupported', 300);
            }
            if ($inspection->status === 'observed' && ! $inspection->conferencePresent) {
                return ReconciliationResult::operationRequired((string) config('telephony_domain.operation_types.conference_ensure'), [
                    'conference_id' => $conference->id,
                    'runtime_node_id' => $runtimeNodeId,
                    'configuration_generation' => $generation,
                    'desired_state' => $conference->desired_state,
                ], 'conference_bridge_missing', $runtimeNodeId);
            }
            if ($inspection->status === 'observed' && $inspection->conferencePresent && ($conference->observed_state !== 'ready' || $observedGeneration < $generation)) {
                $this->inspections->recordEvidence((string) $conference->tenant_id, $runtimeNodeId, (string) $conference->id);

                return ReconciliationResult::waiting('conference_runtime_evidence_recorded', 10);
            }

            return ReconciliationResult::converged(30);
        }
        if ($conference->desired_state === 'draining') {
            $remaining = DB::table('conference_participants')->where('conference_id', $conference->id)->where('desired_state', 'admitted')->count();

            return $remaining === 0 ? ReconciliationResult::converged(300) : ReconciliationResult::waiting('conference_draining_participants_remaining', 120);
        }
        $operationType = $conference->desired_state === 'closed'
            ? config('telephony_domain.operation_types.conference_close')
            : config('telephony_domain.operation_types.conference_ensure');

        return ReconciliationResult::operationRequired((string) $operationType, [
            'conference_id' => $conference->id,
            'runtime_node_id' => $runtimeNodeId,
            'configuration_generation' => $generation,
            'desired_state' => $conference->desired_state,
        ], 'conference_runtime_drift', $runtimeNodeId);
    }

    private function activeRuntimeNodeId(string $tenantId, string $conferenceId): ?string
    {
        $runtimeNodeId = DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('status', 'active')
            ->value('runtime_node_id');

        return is_string($runtimeNodeId) && $runtimeNodeId !== '' ? $runtimeNodeId : null;
    }
}
