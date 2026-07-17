<?php

namespace App\TelephonyDomain\Reconciliation;

use App\RuntimeEngine\Commands\RuntimeConferenceInspectionService;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Illuminate\Support\Facades\DB;

final class ConferenceParticipantReconciler implements Reconciler
{
    public function __construct(
        private readonly RuntimeConferenceInspectionService $inspections,
    ) {}

    public function targetType(): string
    {
        return 'conference_participant';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $participant = DB::table('conference_participants')->where('id', $target->target_id)->first();
        if ($participant === null || $participant->tenant_id !== $target->tenant_id) {
            return ReconciliationResult::unsupported('conference_participant_not_found');
        }
        $conference = DB::table('conferences')->where('id', $participant->conference_id)->first();
        if ($conference === null) {
            return ReconciliationResult::blocked('conference_runtime_binding_missing');
        }
        $runtimeNodeId = $this->activeRuntimeNodeId((string) $conference->tenant_id, (string) $conference->id);
        if ($runtimeNodeId === null) {
            return ReconciliationResult::blocked('conference_runtime_binding_missing');
        }
        if (! DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', 'conference.participation')->exists()) {
            return ReconciliationResult::blocked('conference_participation_capability_missing');
        }

        if ($participant->desired_state === 'removed' && $participant->observed_state === 'left') {
            return ReconciliationResult::converged(300);
        }

        $last = $target->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $target->last_operation_id)->first();
        if ($last !== null && $last->status === 'terminal_failed') {
            return ReconciliationResult::blocked((string) ($last->last_failure_code ?? 'conference_participant_operation_terminal_failed'));
        }
        if ($last !== null && ! in_array($last->status, ['succeeded', 'cancelled', 'expired'], true)) {
            return ReconciliationResult::waiting('runtime_operation_in_progress', 60);
        }

        if ($participant->desired_state === 'admitted') {
            $inspection = $this->inspections->inspect((string) $participant->tenant_id, $runtimeNodeId, (string) $conference->id, (string) $participant->id);
            if ($inspection->status === 'unavailable' || $inspection->status === 'failed') {
                return ReconciliationResult::waiting('conference_participant_runtime_inspection_unavailable', 30);
            }
            if ($inspection->status === 'unsupported') {
                return ReconciliationResult::waiting('conference_participant_runtime_inspection_unsupported', 300);
            }
            if ($inspection->status === 'observed' && ! (bool) $inspection->participantAttached) {
                return ReconciliationResult::operationRequired((string) config('telephony_domain.operation_types.participant_ensure'), [
                    'conference_id' => $participant->conference_id,
                    'participant_id' => $participant->id,
                    'telephony_session_id' => $participant->telephony_session_id,
                    'runtime_node_id' => $runtimeNodeId,
                    'configuration_generation' => (int) $conference->configuration_generation,
                    'desired_state' => $participant->desired_state,
                ], 'conference_participant_channel_missing', $runtimeNodeId);
            }
            if ($inspection->status === 'observed' && (bool) $inspection->participantAttached && $participant->observed_state !== 'joined') {
                $this->inspections->recordEvidence((string) $participant->tenant_id, $runtimeNodeId, (string) $conference->id, (string) $participant->id);

                return ReconciliationResult::waiting('conference_participant_runtime_evidence_recorded', 10);
            }

            return ReconciliationResult::converged(30);
        }
        if ($participant->desired_state === 'removed') {
            $inspection = $this->inspections->inspect((string) $participant->tenant_id, $runtimeNodeId, (string) $conference->id, (string) $participant->id);
            if ($inspection->status === 'unavailable' || $inspection->status === 'failed') {
                return ReconciliationResult::waiting('conference_participant_runtime_inspection_unavailable', 30);
            }
            if ($inspection->status === 'unsupported') {
                return ReconciliationResult::waiting('conference_participant_runtime_inspection_unsupported', 300);
            }
            if ($inspection->status === 'observed' && ! ((bool) $inspection->participantAttached || (bool) $inspection->participantPresent)) {
                if ($participant->observed_state !== 'left') {
                    $this->inspections->recordEvidence((string) $participant->tenant_id, $runtimeNodeId, (string) $conference->id, (string) $participant->id);

                    return ReconciliationResult::waiting('conference_participant_runtime_absence_recorded', 10);
                }

                return ReconciliationResult::converged(300);
            }
            if ($inspection->status === 'observed' && ((bool) $inspection->participantAttached || (bool) $inspection->participantPresent)) {
                return ReconciliationResult::operationRequired((string) config('telephony_domain.operation_types.participant_remove'), [
                    'conference_id' => $participant->conference_id,
                    'participant_id' => $participant->id,
                    'telephony_session_id' => $participant->telephony_session_id,
                    'runtime_node_id' => $runtimeNodeId,
                    'configuration_generation' => (int) $conference->configuration_generation,
                    'desired_state' => $participant->desired_state,
                ], 'conference_participant_runtime_drift', $runtimeNodeId);
            }
        }
        $operationType = $participant->desired_state === 'removed'
            ? config('telephony_domain.operation_types.participant_remove')
            : config('telephony_domain.operation_types.participant_ensure');

        return ReconciliationResult::operationRequired((string) $operationType, [
            'conference_id' => $participant->conference_id,
            'participant_id' => $participant->id,
            'telephony_session_id' => $participant->telephony_session_id,
            'runtime_node_id' => $runtimeNodeId,
            'configuration_generation' => (int) $conference->configuration_generation,
            'desired_state' => $participant->desired_state,
        ], 'conference_participant_runtime_drift', $runtimeNodeId);
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
