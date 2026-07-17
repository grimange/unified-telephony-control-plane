<?php

namespace App\RuntimeEngine\Projection;

use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use Illuminate\Support\Facades\DB;

final class ProjectionService
{
    /**
     * @param  list<array<string, mixed>>  $observations
     */
    public function apply(object $receipt, array $observations): void
    {
        DB::transaction(function () use ($receipt, $observations): void {
            foreach ($observations as $observation) {
                PayloadSafety::assertSafe($observation['payload'] ?? []);
                $observationId = EngineIds::new();
                $tenantId = (string) ($observation['tenant_id'] ?? $receipt->tenant_id);
                $inserted = DB::table('runtime_observations')->insertOrIgnore([
                    'id' => $observationId,
                    'tenant_id' => $tenantId,
                    'runtime_node_id' => $receipt->runtime_node_id,
                    'observation_type' => $observation['observation_type'],
                    'observation_version' => $observation['observation_version'] ?? 1,
                    'subject_type' => $observation['subject_type'],
                    'subject_id' => $observation['subject_id'],
                    'observed_state' => $observation['observed_state'],
                    'source_event_id' => $receipt->id,
                    'source_connection_epoch' => $receipt->connection_epoch_id,
                    'configuration_version' => $observation['configuration_version'] ?? null,
                    'observed_at' => $observation['observed_at'] ?? now(),
                    'received_at' => now(),
                    'payload' => StableJson::encode($observation['payload'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($observation['subject_type'] === 'runtime_node' && $observation['subject_id'] === $receipt->runtime_node_id) {
                    DB::table('runtime_nodes')->where('id', $receipt->runtime_node_id)->update([
                        'observed_state' => $observation['observed_state'],
                        'observed_at' => $observation['observed_at'] ?? now(),
                        'last_evidence_source' => $receipt->id,
                        'last_observation_id' => $observationId,
                        'observed_configuration_version' => $observation['configuration_version'] ?? null,
                        'updated_at' => now(),
                    ]);
                    if ($inserted === 1) {
                        $this->wakeBoundConferenceRecoveryTargets((string) $receipt->tenant_id, (string) $receipt->runtime_node_id);
                    }
                }

                if ($inserted === 1 && $observation['subject_type'] === 'conference') {
                    $this->projectConferenceObservation($receipt, $observation, $observationId);
                }

                if ($inserted === 1 && $observation['subject_type'] === 'conference_participant') {
                    $this->projectParticipantObservation($receipt, $observation, $observationId);
                }

                if ($inserted === 1 && $observation['subject_type'] === 'signaling_registration') {
                    $this->projectSignalingRegistrationObservation($receipt, $observation, $observationId, $tenantId);
                }

                $this->advanceCheckpoint(
                    'runtime-event-normalizer',
                    ($receipt->event_source_id ?? $receipt->runtime_node_id).':'.$receipt->connection_epoch_id,
                    $receipt->runtime_node_id,
                    $receipt->id,
                    $observation['observed_at'] ?? now(),
                    $receipt->event_source_id ?? null,
                );
            }
        });
    }

    private function wakeBoundConferenceRecoveryTargets(string $tenantId, string $runtimeNodeId): void
    {
        if (! DB::getSchemaBuilder()->hasTable('runtime_reconciliation_states') || ! DB::getSchemaBuilder()->hasTable('conferences') || ! DB::getSchemaBuilder()->hasTable('conference_runtime_bindings')) {
            return;
        }

        $lifecycleCapability = (string) config('telephony_domain.runtime_capabilities.conference_lifecycle', 'conference.lifecycle');
        $participationCapability = (string) config('telephony_domain.runtime_capabilities.conference_participation', 'conference.participation');

        $conferenceIds = DB::table('conferences')
            ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conferences.id')
            ->join('runtime_node_capabilities', 'runtime_node_capabilities.runtime_node_id', '=', 'conference_runtime_bindings.runtime_node_id')
            ->where('conferences.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.runtime_node_id', $runtimeNodeId)
            ->where('conference_runtime_bindings.status', 'active')
            ->where('runtime_node_capabilities.capability_key', $lifecycleCapability)
            ->where(function ($query): void {
                $query
                    ->where('conferences.desired_state', 'open')
                    ->orWhere(function ($query): void {
                        $query
                            ->where('conferences.desired_state', 'closed')
                            ->where('conferences.observed_state', '<>', 'closed');
                    });
            })
            ->pluck('conferences.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($conferenceIds !== []) {
            DB::table('runtime_reconciliation_states')
                ->where('tenant_id', $tenantId)
                ->where('target_type', 'conference')
                ->whereIn('target_id', $conferenceIds)
                ->where('status', 'converged')
                ->update([
                    'status' => 'waiting',
                    'next_check_at' => now(),
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            $participantIds = DB::table('conference_participants')
                ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
                ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conference_participants.conference_id')
                ->join('runtime_node_capabilities', 'runtime_node_capabilities.runtime_node_id', '=', 'conference_runtime_bindings.runtime_node_id')
                ->where('conference_participants.tenant_id', $tenantId)
                ->where('conferences.tenant_id', $tenantId)
                ->where('conference_runtime_bindings.tenant_id', $tenantId)
                ->where('conference_runtime_bindings.runtime_node_id', $runtimeNodeId)
                ->where('conference_runtime_bindings.status', 'active')
                ->where('runtime_node_capabilities.capability_key', $participationCapability)
                ->where(function ($query): void {
                    $query
                        ->where('conference_participants.desired_state', 'admitted')
                        ->orWhere('conference_participants.observed_state', '<>', 'left')
                        ->orWhere('conferences.desired_state', 'open')
                        ->orWhere('conferences.observed_state', '<>', 'closed');
                })
                ->where(function ($query): void {
                    $query
                        ->where('conference_participants.desired_state', '<>', 'removed')
                        ->orWhere('conference_participants.observed_state', '<>', 'left')
                        ->orWhere('conferences.desired_state', '<>', 'closed')
                        ->orWhere('conferences.observed_state', '<>', 'closed');
                })
                ->pluck('conference_participants.id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            if ($participantIds !== []) {
                DB::table('runtime_reconciliation_states')
                    ->where('tenant_id', $tenantId)
                    ->where('target_type', 'conference_participant')
                    ->whereIn('target_id', $participantIds)
                    ->where('status', 'converged')
                    ->update([
                        'status' => 'waiting',
                        'next_check_at' => now(),
                        'lease_owner' => null,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function projectSignalingRegistrationObservation(object $receipt, array $observation, string $observationId, string $tenantId): void
    {
        $payload = is_array($observation['payload'] ?? null) ? $observation['payload'] : [];
        $identity = is_string($payload['signaling_identity'] ?? null) ? (string) $payload['signaling_identity'] : null;
        if ($identity === null || $identity === '') {
            return;
        }

        $fields = [
            'observed_state' => $observation['observed_state'],
            'observed_at' => $observation['observed_at'] ?? now(),
            'observed_expires_at' => is_string($payload['observed_expires_at'] ?? null) ? $payload['observed_expires_at'] : null,
            'source_epoch' => $receipt->connection_epoch_id,
            'last_event_type' => is_string($payload['event_type'] ?? null) ? $payload['event_type'] : null,
            'last_observation_id' => $observationId,
            'failure_class' => is_string($payload['failure_class'] ?? null) ? $payload['failure_class'] : null,
            'updated_at' => now(),
        ];
        if (is_string($payload['ruid'] ?? null)) {
            $fields['contact_ruid'] = $payload['ruid'];
        }

        DB::table('signaling_registration_observations')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $observation['subject_id'])
            ->where('signaling_identity', $identity)
            ->update($fields);

        $row = DB::table('signaling_registration_observations')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $observation['subject_id'])
            ->first();
        if ($row !== null && DB::getSchemaBuilder()->hasTable('runtime_reconciliation_states')) {
            app(ReconciliationRepository::class)
                ->ensureTarget($tenantId, 'signaling_registration', (string) $row->id, (int) ($row->desired_generation ?? 1), 0);

            // A reconciliation target that reached "converged" is never reclaimed by
            // ReconciliationRepository::claimDue() again on its own. If the desired state
            // is still eligible but the client's registration has since dropped out of
            // registered/pending_removal (e.g. an unexpected deregistration or expiry),
            // the target must be reopened explicitly or it stays silently frozen forever.
            if ($row->desired_state === 'eligible' && ! in_array($row->observed_state, ['registered', 'pending_removal'], true)) {
                DB::table('runtime_reconciliation_states')
                    ->where('target_type', 'signaling_registration')
                    ->where('target_id', (string) $row->id)
                    ->where('status', 'converged')
                    ->update(['status' => 'waiting', 'next_check_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function projectConferenceObservation(object $receipt, array $observation, string $observationId): void
    {
        $generation = $observation['configuration_version'] ?? null;
        $conference = DB::table('conferences')
            ->where('id', $observation['subject_id'])
            ->where('tenant_id', $receipt->tenant_id)
            ->where('runtime_node_id', $receipt->runtime_node_id)
            ->first();
        if ($conference === null) {
            return;
        }
        if ($observation['observed_state'] === 'ready' && (string) $conference->desired_state !== 'open') {
            return;
        }
        if ($observation['observed_state'] === 'closed' && (string) $conference->desired_state !== 'closed') {
            return;
        }

        $query = DB::table('conferences')
            ->where('id', $observation['subject_id'])
            ->where('tenant_id', $receipt->tenant_id)
            ->where('runtime_node_id', $receipt->runtime_node_id)
            ->where(function ($query) use ($generation): void {
                $query->whereNull('observed_generation');
                if ($generation !== null) {
                    $query->orWhere('observed_generation', '<=', (int) $generation);
                }
            });

        $updated = $query->update([
            'observed_state' => $observation['observed_state'],
            'observed_generation' => $generation,
            'observed_at' => $observation['observed_at'] ?? now(),
            'last_evidence_source' => $receipt->id,
            'last_observation_id' => $observationId,
            'updated_at' => now(),
        ]);

        if ($updated === 1) {
            if (
                ((string) $conference->desired_state === 'open' && $observation['observed_state'] === 'ready')
                || ((string) $conference->desired_state === 'closed' && $observation['observed_state'] === 'closed')
            ) {
                $this->markProjectedTargetConverged(
                    (string) $receipt->tenant_id,
                    'conference',
                    (string) $observation['subject_id'],
                    max(1, (int) ($generation ?? $conference->configuration_generation ?? 1)),
                );

                return;
            }

            $this->wakeProjectedTarget(
                (string) $receipt->tenant_id,
                'conference',
                (string) $observation['subject_id'],
                max(1, (int) ($generation ?? $conference->configuration_generation ?? 1)),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function projectParticipantObservation(object $receipt, array $observation, string $observationId): void
    {
        $payload = is_array($observation['payload'] ?? null) ? $observation['payload'] : [];
        $conferenceId = is_string($payload['conference_id'] ?? null) ? $payload['conference_id'] : null;
        $sessionId = is_string($payload['telephony_session_id'] ?? null) ? $payload['telephony_session_id'] : null;
        if ($conferenceId === null || $sessionId === null) {
            return;
        }

        $participant = DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->where('conference_participants.id', $observation['subject_id'])
            ->where('conference_participants.tenant_id', $receipt->tenant_id)
            ->where('conference_participants.conference_id', $conferenceId)
            ->where('conference_participants.telephony_session_id', $sessionId)
            ->select([
                'conference_participants.desired_state',
                'conference_participants.tenant_id',
                'conference_participants.id',
                'conferences.configuration_generation',
                'conferences.desired_state as conference_desired_state',
            ])
            ->first();
        if ($participant === null) {
            return;
        }
        if ($observation['observed_state'] === 'joined' && ((string) $participant->conference_desired_state !== 'open' || (string) $participant->desired_state !== 'admitted')) {
            return;
        }
        if (in_array($observation['observed_state'], ['left', 'failed'], true) && (string) $participant->desired_state !== 'removed' && (string) $participant->conference_desired_state !== 'closed') {
            return;
        }

        $fields = [
            'observed_state' => $observation['observed_state'],
            'updated_at' => now(),
        ];
        if ($observation['observed_state'] === 'joined') {
            $fields['joined_at'] = $observation['observed_at'] ?? now();
        }
        if (in_array($observation['observed_state'], ['left', 'failed'], true)) {
            $fields['left_at'] = $observation['observed_at'] ?? now();
        }

        $updated = DB::table('conference_participants')
            ->where('id', $observation['subject_id'])
            ->where('tenant_id', $receipt->tenant_id)
            ->where('conference_id', $conferenceId)
            ->where('telephony_session_id', $sessionId)
            ->update($fields);

        if ($updated === 1) {
            if (
                ((string) $participant->desired_state === 'admitted' && $observation['observed_state'] === 'joined')
                || (
                    in_array($observation['observed_state'], ['left', 'failed'], true)
                    && ((string) $participant->desired_state === 'removed' || (string) $participant->conference_desired_state === 'closed')
                )
            ) {
                $this->markProjectedTargetConverged(
                    (string) $receipt->tenant_id,
                    'conference_participant',
                    (string) $observation['subject_id'],
                    $this->participantDesiredGeneration($participant, (string) $participant->desired_state),
                );

                return;
            }

            $this->wakeProjectedTarget(
                (string) $receipt->tenant_id,
                'conference_participant',
                (string) $observation['subject_id'],
                $this->participantDesiredGeneration($participant, (string) $participant->desired_state),
            );
        }
    }

    private function participantDesiredGeneration(object $participant, string $desiredState): int
    {
        $conferenceGeneration = max(1, (int) ($participant->configuration_generation ?? 1));

        return ($conferenceGeneration * 2) + ($desiredState === 'removed' ? 1 : 0);
    }

    private function wakeProjectedTarget(string $tenantId, string $targetType, string $targetId, int $desiredGeneration): void
    {
        if (! DB::getSchemaBuilder()->hasTable('runtime_reconciliation_states')) {
            return;
        }

        $existing = DB::table('runtime_reconciliation_states')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing === null) {
            app(ReconciliationRepository::class)->ensureTarget($tenantId, $targetType, $targetId, $desiredGeneration, 0);

            return;
        }

        DB::table('runtime_reconciliation_states')
            ->where('id', $existing->id)
            ->update([
                'tenant_id' => $tenantId,
                'desired_generation' => max((int) $existing->desired_generation, $desiredGeneration),
                'status' => 'waiting',
                'blocked_reason' => null,
                'next_check_at' => now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function markProjectedTargetConverged(string $tenantId, string $targetType, string $targetId, int $desiredGeneration): void
    {
        if (! DB::getSchemaBuilder()->hasTable('runtime_reconciliation_states')) {
            return;
        }

        $existing = DB::table('runtime_reconciliation_states')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing === null) {
            app(ReconciliationRepository::class)->ensureTarget($tenantId, $targetType, $targetId, $desiredGeneration, 300);
            $existing = DB::table('runtime_reconciliation_states')
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->first();
        }

        if ($existing === null) {
            return;
        }

        DB::table('runtime_reconciliation_states')
            ->where('id', $existing->id)
            ->update([
                'tenant_id' => $tenantId,
                'desired_generation' => max((int) $existing->desired_generation, $desiredGeneration),
                'status' => 'converged',
                'blocked_reason' => null,
                'next_check_at' => now()->addSeconds(300),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function advanceCheckpoint(string $projector, string $partitionKey, ?string $runtimeNodeId, string $eventId, mixed $observedAt, ?string $eventSourceId = null): void
    {
        $this->advanceCheckpointForSource($projector, $partitionKey, $eventSourceId, $runtimeNodeId, $eventId, $observedAt);
    }

    public function advanceCheckpointForSource(string $projector, string $partitionKey, ?string $eventSourceId, ?string $runtimeNodeId, string $eventId, mixed $observedAt): void
    {
        $query = DB::table('runtime_projection_checkpoints')
            ->where('projector', $projector)
            ->where('partition_key', $partitionKey);

        if ($eventSourceId !== null) {
            $query->where('event_source_id', $eventSourceId);
        } else {
            $query->where('runtime_node_id', $runtimeNodeId);
        }

        $existing = $query->lockForUpdate()->first();

        if ($existing === null) {
            DB::table('runtime_projection_checkpoints')->insert([
                'id' => EngineIds::new(),
                'event_source_id' => $eventSourceId,
                'projector' => $projector,
                'partition_key' => $partitionKey,
                'runtime_node_id' => $runtimeNodeId,
                'last_source_event_id' => $eventId,
                'last_observed_at' => $observedAt,
                'sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($existing->last_source_event_id === $eventId) {
            return;
        }

        DB::table('runtime_projection_checkpoints')->where('id', $existing->id)->update([
            'last_source_event_id' => $eventId,
            'last_observed_at' => $observedAt,
            'sequence' => ((int) $existing->sequence) + 1,
            'updated_at' => now(),
        ]);
    }

    public function markStale(int $staleSeconds): int
    {
        return DB::table('runtime_nodes')
            ->whereIn('observed_state', ['ready', 'degraded', 'connecting'])
            ->whereNotNull('observed_at')
            ->where('observed_at', '<', now()->subSeconds($staleSeconds))
            ->update([
                'observed_state' => 'stale',
                'updated_at' => now(),
            ]);
    }
}
