<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Identity\IdentityContext;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\TelephonyDomain\Signaling\SignalingCredentialService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TelephonyDomainService
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
        private readonly ReconciliationRepository $reconciliation,
        private readonly SignalingCredentialService $signaling,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function currentSession(string $tenantId, string $userId): ?array
    {
        $row = DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('issued_at')
            ->first();

        return $row === null ? null : $this->serializeSession($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentSessionForUser(string $tenantId, string $userId): ?array
    {
        return $this->currentSession($tenantId, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mostRecentSessionForUser(string $tenantId, string $userId): ?array
    {
        $row = DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('issued_at')
            ->first();

        return $row === null ? null : $this->serializeSession($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(Request $request, string $tenantId, ?IdempotencyKey $key = null): array
    {
        $userId = (string) $request->user()->id;
        $fingerprint = ['tenant_id' => $tenantId, 'user_id' => $userId, 'action' => 'create_own_session'];
        if ($key !== null && ($existing = $this->beginIdempotent('telephony.sessions.create_own', $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $userId): array {
            $this->assertActiveMembership($tenantId, $userId);
            $this->expireDueSessionsForUpdate($tenantId, $userId);

            $existing = DB::table('telephony_sessions')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return ['telephony_session' => $this->serializeSession($existing)];
            }

            $id = TelephonyDomainIds::new();
            DB::table('telephony_sessions')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'active',
                'issued_at' => now(),
                'expires_at' => now()->addMinutes((int) config('telephony_domain.session_lifetime_minutes', 60)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->emit($request, $tenantId, 'telephony_session.created', 'telephony_session', $id, [
                'status' => 'active',
                'expires_at' => DB::table('telephony_sessions')->where('id', $id)->value('expires_at'),
            ]);

            return ['telephony_session' => $this->serializeSession(DB::table('telephony_sessions')->where('id', $id)->first())];
        });

        if ($key !== null) {
            $this->idempotency->complete('telephony.sessions.create_own', $key, $result);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function endSession(Request $request, string $tenantId, string $sessionId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $sessionId): array {
            $session = DB::table('telephony_sessions')->where('id', $sessionId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($session !== null, 404, 'Telephony session not found.');
            abort_unless($session->user_id === $request->user()->id, 403, 'Forbidden');

            return $this->endLockedSession($request, $tenantId, $session, 'user_ended');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function endSessionForUser(Request $request, string $tenantId, string $userId, string $sessionId, string $reason = 'admin_ended'): array
    {
        return DB::transaction(function () use ($request, $tenantId, $userId, $sessionId, $reason): array {
            $session = DB::table('telephony_sessions')->where('id', $sessionId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($session !== null && $session->user_id === $userId, 404, 'Telephony session not found.');

            return $this->endLockedSession($request, $tenantId, $session, $reason);
        });
    }

    public function expireDueSessions(int $limit = 100): int
    {
        $context = ExecutionContext::system(reason: 'telephony session expiry', origin: 'scheduler');
        $expired = 0;
        DB::table('telephony_sessions')
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (object $session) use (&$expired, $context): void {
                DB::transaction(function () use ($session, &$expired, $context): void {
                    $locked = DB::table('telephony_sessions')->where('id', $session->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== 'active') {
                        return;
                    }
                    DB::table('telephony_sessions')->where('id', $locked->id)->update([
                        'status' => 'expired',
                        'ended_at' => now(),
                        'termination_reason' => 'expired',
                        'updated_at' => now(),
                    ]);
                    $this->signaling->revokeForSession((string) $locked->tenant_id, (string) $locked->id, 'session_expired', $context);
                    $this->removeParticipantsForSession((string) $locked->tenant_id, (string) $locked->id, 'session_expired');
                    $this->audit->append($context, 'telephony_session.expired', 'telephony_session', (string) $locked->id, ['status' => 'expired']);
                    $this->outbox->append(EventEnvelope::forAggregate('telephony_session.expired', 1, 'telephony_session', (string) $locked->id, [
                        'tenant_id' => $locked->tenant_id,
                        'telephony_session_id' => $locked->id,
                    ], $context));
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConferences(string $tenantId): array
    {
        return DB::table('conferences')
            ->where('tenant_id', $tenantId)
            ->orderBy('slug')
            ->get()
            ->map(fn (object $row): array => $this->serializeConference($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function conference(string $tenantId, string $conferenceId): array
    {
        $row = $this->conferenceRow($tenantId, $conferenceId);

        return $this->serializeConference($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createConference(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $slug = $this->normalizeSlug((string) $input['slug']);
        $fingerprint = ['tenant_id' => $tenantId, 'slug' => $slug, 'runtime_node_id' => $input['runtime_node_id'] ?? null];
        if ($key !== null && ($existing = $this->beginIdempotent('telephony.conferences.create', $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $input, $slug): array {
            $id = TelephonyDomainIds::new();
            $runtimeNodeId = isset($input['runtime_node_id']) ? (string) $input['runtime_node_id'] : null;
            if ($runtimeNodeId !== null) {
                $this->assertRuntimeNodeSupportsConference($tenantId, $runtimeNodeId, 'conference.lifecycle');
            }
            DB::table('conferences')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'slug' => $slug,
                'display_name' => trim((string) $input['display_name']),
                'runtime_node_id' => $runtimeNodeId,
                'desired_state' => 'draft',
                'observed_state' => 'unobserved',
                'configuration_generation' => 1,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($runtimeNodeId !== null) {
                $this->writeBinding($tenantId, $id, $runtimeNodeId, (string) $request->user()->id);
            }
            $this->emit($request, $tenantId, 'conference.created', 'conference', $id, ['slug' => $slug]);

            return ['conference' => $this->serializeConference(DB::table('conferences')->where('id', $id)->first())];
        });

        if ($key !== null) {
            $this->idempotency->complete('telephony.conferences.create', $key, $result);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function changeConferenceDesiredState(Request $request, string $tenantId, string $conferenceId, string $desiredState): array
    {
        return DB::transaction(function () use ($request, $tenantId, $conferenceId, $desiredState): array {
            $conference = DB::table('conferences')->where('id', $conferenceId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($conference !== null, 404, 'Conference not found.');
            $this->assertConferenceTransition((string) $conference->desired_state, $desiredState);
            $runtimeNodeId = is_string($conference->runtime_node_id) ? (string) $conference->runtime_node_id : null;
            if ($desiredState === 'open') {
                $runtimeNodeId ??= $this->selectRuntimeNodeForConference($tenantId, 'conference.lifecycle');
                $this->assertRuntimeNodeSupportsConference($tenantId, $runtimeNodeId, 'conference.lifecycle');
            }

            $fields = [
                'desired_state' => $desiredState,
                'configuration_generation' => DB::raw('configuration_generation + 1'),
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ];
            if ($desiredState === 'open' && $conference->opened_at === null) {
                $fields['opened_at'] = now();
            }
            if ($desiredState === 'draining') {
                $fields['draining_at'] = now();
            }
            if ($desiredState === 'closed') {
                $fields['closed_at'] = now();
            }
            if ($desiredState === 'open' && $conference->runtime_node_id === null && $runtimeNodeId !== null) {
                $fields['runtime_node_id'] = $runtimeNodeId;
            }
            DB::table('conferences')->where('id', $conferenceId)->update($fields);
            if ($desiredState === 'open' && $conference->runtime_node_id === null && $runtimeNodeId !== null) {
                $this->writeBinding($tenantId, $conferenceId, $runtimeNodeId, (string) $request->user()->id);
            }
            $updated = DB::table('conferences')->where('id', $conferenceId)->first();
            $this->reconciliation->ensureTarget($tenantId, 'conference', $conferenceId, (int) $updated->configuration_generation);
            $this->emit($request, $tenantId, 'conference.desired_state_changed', 'conference', $conferenceId, [
                'desired_state' => $desiredState,
                'configuration_generation' => (int) $updated->configuration_generation,
            ]);

            return ['conference' => $this->serializeConference($updated)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function bindRuntimeNode(Request $request, string $tenantId, string $conferenceId, string $runtimeNodeId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $conferenceId, $runtimeNodeId): array {
            $conference = DB::table('conferences')->where('id', $conferenceId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($conference !== null, 404, 'Conference not found.');
            abort_unless(in_array($conference->desired_state, ['draft', 'closed'], true), 422, 'Runtime binding changes require draft or closed desired state.');
            $this->assertRuntimeNodeSupportsConference($tenantId, $runtimeNodeId, 'conference.lifecycle');
            DB::table('conferences')->where('id', $conferenceId)->update([
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => DB::raw('configuration_generation + 1'),
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            DB::table('conference_runtime_bindings')->where('conference_id', $conferenceId)->where('status', 'active')->update([
                'status' => 'retired',
                'unbound_at' => now(),
                'updated_at' => now(),
            ]);
            $this->writeBinding($tenantId, $conferenceId, $runtimeNodeId, (string) $request->user()->id);
            $updated = DB::table('conferences')->where('id', $conferenceId)->first();
            $this->reconciliation->ensureTarget($tenantId, 'conference', $conferenceId, (int) $updated->configuration_generation);
            $this->emit($request, $tenantId, 'conference.runtime_binding_changed', 'conference', $conferenceId, [
                'configuration_generation' => (int) $updated->configuration_generation,
            ]);

            return ['conference' => $this->serializeConference($updated)];
        });
    }

    /**
     * @param  array{
     *     expected_binding_id?:string,
     *     expected_runtime_node_id?:string,
     *     qualifying_bound_states?:list<string>,
     *     replacement_desired_states?:list<string>,
     *     ready_observation_grace_seconds?:int,
     *     required_fence_operation_id?:string
     * }  $options
     * @return array<string, mixed>
     */
    public function failoverRebindConference(ExecutionContext $context, string $tenantId, string $conferenceId, string $reason = 'runtime_node_unavailable', array $options = []): array
    {
        return DB::transaction(function () use ($context, $tenantId, $conferenceId, $reason, $options): array {
            $conference = DB::table('conferences')
                ->where('id', $conferenceId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            abort_unless($conference !== null, 404, 'Conference not found.');

            if ((string) $conference->desired_state !== 'open') {
                return ['status' => 'noop', 'reason' => 'conference_not_open'];
            }

            $activeBinding = DB::table('conference_runtime_bindings')
                ->where('tenant_id', $tenantId)
                ->where('conference_id', $conferenceId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($activeBinding === null) {
                return ['status' => 'noop', 'reason' => 'active_binding_missing'];
            }
            if (($options['expected_binding_id'] ?? null) !== null && (string) $activeBinding->id !== (string) $options['expected_binding_id']) {
                return ['status' => 'noop', 'reason' => 'active_binding_changed'];
            }
            if (($options['expected_runtime_node_id'] ?? null) !== null && (string) $activeBinding->runtime_node_id !== (string) $options['expected_runtime_node_id']) {
                return ['status' => 'noop', 'reason' => 'active_binding_changed'];
            }

            $boundNode = DB::table('runtime_nodes')
                ->where('id', $activeBinding->runtime_node_id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            if ($boundNode === null) {
                return ['status' => 'noop', 'reason' => 'bound_runtime_node_missing'];
            }
            $qualifyingBoundStates = $this->normalizedStates($options['qualifying_bound_states'] ?? null);
            if ($qualifyingBoundStates !== null) {
                if (! in_array((string) $boundNode->observed_state, $qualifyingBoundStates, true)) {
                    return [
                        'status' => 'noop',
                        'reason' => (string) $boundNode->observed_state === 'ready'
                            ? 'bound_runtime_node_ready'
                            : 'bound_runtime_node_not_eligible',
                    ];
                }
            } elseif ((string) $boundNode->observed_state === 'ready') {
                return ['status' => 'noop', 'reason' => 'bound_runtime_node_ready'];
            }
            if (($options['ready_observation_grace_seconds'] ?? null) !== null && ! $this->runtimeReadyObservationOlderThan(
                (string) $activeBinding->runtime_node_id,
                max(1, (int) $options['ready_observation_grace_seconds']),
            )) {
                return ['status' => 'noop', 'reason' => 'bound_runtime_node_recently_ready'];
            }
            if (($options['required_fence_operation_id'] ?? null) !== null) {
                $fenceFailure = $this->validateFailoverFenceEvidence(
                    $tenantId,
                    $conferenceId,
                    $conference,
                    $activeBinding,
                    (string) $options['required_fence_operation_id'],
                );
                if ($fenceFailure !== null) {
                    return $fenceFailure;
                }
            }

            $requiredCapabilities = $this->conferenceRuntimeCapabilities();
            $replacementDesiredStates = $this->normalizedStates($options['replacement_desired_states'] ?? null) ?? ['active', 'draining'];
            $replacementRuntimeNodeId = $this->selectRuntimeNodeForConference(
                $tenantId,
                $requiredCapabilities,
                (string) $activeBinding->runtime_node_id,
                $replacementDesiredStates,
            );

            if ($replacementRuntimeNodeId === (string) $activeBinding->runtime_node_id) {
                return ['status' => 'noop', 'reason' => 'replacement_runtime_node_not_distinct'];
            }
            $this->assertRuntimeNodeEligibleForConferenceRebind($tenantId, $replacementRuntimeNodeId, $requiredCapabilities, $replacementDesiredStates);

            DB::table('conference_runtime_bindings')->where('id', $activeBinding->id)->update([
                'status' => 'retired',
                'unbound_at' => now(),
                'updated_at' => now(),
            ]);
            $this->writeBinding($tenantId, $conferenceId, $replacementRuntimeNodeId, $context->actorId ?? $conference->updated_by);
            DB::table('conferences')->where('id', $conferenceId)->update([
                'runtime_node_id' => $replacementRuntimeNodeId,
                'configuration_generation' => DB::raw('configuration_generation + 1'),
                'updated_by' => $context->actorId,
                'updated_at' => now(),
            ]);

            $updated = DB::table('conferences')->where('id', $conferenceId)->first();
            $generation = (int) $updated->configuration_generation;
            $this->reconciliation->wakeTarget($tenantId, 'conference', $conferenceId, $generation);
            DB::table('conference_participants')
                ->where('tenant_id', $tenantId)
                ->where('conference_id', $conferenceId)
                ->where('desired_state', 'admitted')
                ->pluck('id')
                ->each(function (string $participantId) use ($tenantId, $updated): void {
                    $this->reconciliation->wakeTarget(
                        $tenantId,
                        'conference_participant',
                        $participantId,
                        $this->participantDesiredGeneration($updated, 'admitted'),
                    );
                });

            $safePayload = [
                'tenant_id' => $tenantId,
                'conference_id' => $conferenceId,
                'previous_runtime_node_id' => (string) $activeBinding->runtime_node_id,
                'runtime_node_id' => $replacementRuntimeNodeId,
                'configuration_generation' => $generation,
                'reason' => mb_substr($reason, 0, 120),
            ];
            $this->audit->append($context, 'conference.runtime_binding_replaced', 'conference', $conferenceId, $safePayload);
            $this->outbox->append(EventEnvelope::forAggregate('conference.runtime_binding_replaced', 1, 'conference', $conferenceId, $safePayload, $context));

            return [
                'status' => 'rebound',
                'reason' => 'runtime_binding_replaced',
                'previous_runtime_node_id' => (string) $activeBinding->runtime_node_id,
                'runtime_node_id' => $replacementRuntimeNodeId,
                'conference' => $this->serializeConference($updated),
            ];
        });
    }

    /**
     * @param  array{
     *     expected_binding_id?:string,
     *     expected_runtime_node_id?:string,
     *     qualifying_bound_states?:list<string>,
     *     replacement_desired_states?:list<string>,
     *     ready_observation_grace_seconds?:int
     * }  $options
     * @return array<string, mixed>
     */
    public function failoverRebindConferenceAfterFence(ExecutionContext $context, string $tenantId, string $conferenceId, string $fenceOperationId, string $reason = 'former_runtime_absent_verified', array $options = []): array
    {
        return $this->failoverRebindConference($context, $tenantId, $conferenceId, $reason, array_merge($options, [
            'required_fence_operation_id' => $fenceOperationId,
        ]));
    }

    public function hasDistinctEligibleReplacement(string $tenantId, string $conferenceId, string $formerRuntimeNodeId): bool
    {
        $conference = DB::table('conferences')
            ->where('id', $conferenceId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conference === null) {
            return false;
        }

        try {
            $replacementRuntimeNodeId = $this->selectRuntimeNodeForConference(
                $tenantId,
                $this->conferenceRuntimeCapabilities(),
                $formerRuntimeNodeId,
                ['active'],
            );
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 422) {
                return false;
            }

            throw $exception;
        }

        return $replacementRuntimeNodeId !== $formerRuntimeNodeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function admitSelf(Request $request, string $tenantId, string $conferenceId, ?IdempotencyKey $key = null): array
    {
        $userId = (string) $request->user()->id;
        $session = DB::table('telephony_sessions')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->orderByDesc('issued_at')->first();
        abort_unless($session !== null, 422, 'Active telephony session is required.');

        return $this->admitParticipant($request, $tenantId, $conferenceId, (string) $session->id, 'participant', 'self_admission', $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function admitParticipant(Request $request, string $tenantId, string $conferenceId, string $sessionId, string $role, string $reason, ?IdempotencyKey $key = null): array
    {
        $fingerprint = ['tenant_id' => $tenantId, 'conference_id' => $conferenceId, 'session_id' => $sessionId, 'role' => $role];
        if ($key !== null && ($existing = $this->beginIdempotent('telephony.conference_participants.admit', $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $conferenceId, $sessionId, $role, $reason): array {
            $conference = DB::table('conferences')->where('id', $conferenceId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($conference !== null, 404, 'Conference not found.');
            abort_unless($conference->desired_state === 'open', 422, 'Conference must be open for admission.');
            abort_unless(is_string($conference->runtime_node_id), 422, 'Conference runtime binding is required.');
            $session = DB::table('telephony_sessions')->where('id', $sessionId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($session !== null && $session->status === 'active' && Carbon::parse($session->expires_at)->greaterThan(now()), 422, 'Active telephony session is required.');
            abort_unless($session->user_id === $request->user()->id || $this->hasManageParticipantCapability($request, $tenantId), 403, 'Forbidden');
            abort_unless(in_array($role, config('telephony_domain.participant_roles', []), true), 422, 'Invalid participant role.');

            $existing = DB::table('conference_participants')
                ->where('conference_id', $conferenceId)
                ->where('telephony_session_id', $sessionId)
                ->where('desired_state', 'admitted')
                ->first();
            if ($existing !== null) {
                return ['participant' => $this->serializeParticipant($existing)];
            }

            $id = TelephonyDomainIds::new();
            DB::table('conference_participants')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'conference_id' => $conferenceId,
                'telephony_session_id' => $sessionId,
                'user_id' => $session->user_id,
                'desired_state' => 'admitted',
                'observed_state' => 'unobserved',
                'role' => $role,
                'admission_reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->reconciliation->wakeTarget($tenantId, 'conference_participant', $id, $this->participantDesiredGeneration($conference, 'admitted'));
            $this->emit($request, $tenantId, 'conference_participant.admitted', 'conference_participant', $id, [
                'conference_id' => $conferenceId,
                'role' => $role,
            ]);

            return ['participant' => $this->serializeParticipant(DB::table('conference_participants')->where('id', $id)->first())];
        });

        if ($key !== null) {
            $this->idempotency->complete('telephony.conference_participants.admit', $key, $result);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function removeParticipant(Request $request, string $tenantId, string $participantId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $participantId): array {
            $participant = DB::table('conference_participants')->where('id', $participantId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($participant !== null, 404, 'Participant not found.');
            $session = DB::table('telephony_sessions')->where('id', $participant->telephony_session_id)->first();
            abort_unless(($session !== null && $session->user_id === $request->user()->id) || $this->hasManageParticipantCapability($request, $tenantId), 403, 'Forbidden');

            if ($participant->desired_state !== 'removed') {
                DB::table('conference_participants')->where('id', $participantId)->update([
                    'desired_state' => 'removed',
                    'updated_at' => now(),
                ]);
                $conference = DB::table('conferences')->where('id', $participant->conference_id)->first();
                $this->reconciliation->wakeTarget($tenantId, 'conference_participant', $participantId, $this->participantDesiredGeneration($conference, 'removed'));
                $this->emit($request, $tenantId, 'conference_participant.removed', 'conference_participant', $participantId, [
                    'conference_id' => $participant->conference_id,
                    'reason' => 'requested',
                ]);
            }

            return ['participant' => $this->serializeParticipant(DB::table('conference_participants')->where('id', $participantId)->first())];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function removeSelfFromConference(Request $request, string $tenantId, string $conferenceId): array
    {
        $userId = (string) $request->user()->id;
        $session = DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'ending', 'ended', 'expired'])
            ->orderByDesc('issued_at')
            ->first();
        abort_unless($session !== null, 422, 'Telephony session is required.');

        $participant = DB::table('conference_participants')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('telephony_session_id', $session->id)
            ->where('desired_state', 'admitted')
            ->orderByDesc('created_at')
            ->first();
        abort_unless($participant !== null, 404, 'Participant not found.');

        return $this->removeParticipant($request, $tenantId, (string) $participant->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function participants(string $tenantId, string $conferenceId): array
    {
        $this->conferenceRow($tenantId, $conferenceId);

        return DB::table('conference_participants')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $row): array => $this->serializeParticipant($row))
            ->all();
    }

    private function removeParticipantsForSession(string $tenantId, string $sessionId, string $reason): void
    {
        DB::table('conference_participants')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $sessionId)
            ->where('desired_state', 'admitted')
            ->get()
            ->each(function (object $participant) use ($tenantId, $reason): void {
                DB::table('conference_participants')->where('id', $participant->id)->update([
                    'desired_state' => 'removed',
                    'updated_at' => now(),
                ]);
                $conference = DB::table('conferences')->where('id', $participant->conference_id)->first();
                $this->reconciliation->wakeTarget($tenantId, 'conference_participant', (string) $participant->id, $this->participantDesiredGeneration($conference, 'removed'));
                $this->audit->append(ExecutionContext::system(reason: $reason, tenantId: $tenantId, origin: 'telephony-domain'), 'conference_participant.removed', 'conference_participant', (string) $participant->id, ['reason' => $reason]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function endLockedSession(Request $request, string $tenantId, object $session, string $reason): array
    {
        if (in_array($session->status, ['ended', 'expired', 'failed'], true)) {
            return ['telephony_session' => $this->serializeSession($session)];
        }

        DB::table('telephony_sessions')->where('id', $session->id)->update([
            'status' => 'ended',
            'ended_at' => now(),
            'termination_reason' => $reason,
            'updated_at' => now(),
        ]);
        $this->signaling->revokeForSession($tenantId, (string) $session->id, $reason, IdentityContext::fromRequest($request, $tenantId));
        $this->removeParticipantsForSession($tenantId, (string) $session->id, $reason);
        $this->emit($request, $tenantId, 'telephony_session.ended', 'telephony_session', (string) $session->id, ['reason' => $reason]);

        return ['telephony_session' => $this->serializeSession(DB::table('telephony_sessions')->where('id', $session->id)->first())];
    }

    private function participantDesiredGeneration(?object $conference, string $desiredState): int
    {
        $conferenceGeneration = max(1, (int) ($conference->configuration_generation ?? 1));

        return ($conferenceGeneration * 2) + ($desiredState === 'removed' ? 1 : 0);
    }

    private function assertActiveMembership(string $tenantId, string $userId): void
    {
        $active = DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->where('tenant_memberships.user_id', $userId)
            ->where('tenant_memberships.status', 'active')
            ->where('tenants.status', 'active')
            ->where('users.status', 'active')
            ->exists();
        abort_unless($active, 409, 'Active tenant membership is required.');
    }

    private function expireDueSessionsForUpdate(string $tenantId, string $userId): void
    {
        DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get()
            ->each(function (object $session) use ($tenantId): void {
                DB::table('telephony_sessions')->where('id', $session->id)->update([
                    'status' => 'expired',
                    'ended_at' => now(),
                    'termination_reason' => 'expired',
                    'updated_at' => now(),
                ]);
                $this->signaling->revokeForSession($tenantId, (string) $session->id, 'session_expired');
            });
    }

    private function assertConferenceTransition(string $from, string $to): void
    {
        $allowed = [
            'draft' => ['draft', 'open', 'closed'],
            'open' => ['open', 'draining', 'closed'],
            'draining' => ['draining', 'closed'],
            'closed' => ['closed'],
        ];
        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw new InvalidArgumentException('Invalid conference desired-state transition.');
        }
    }

    private function assertRuntimeNodeSupportsConference(string $tenantId, string $runtimeNodeId, string $capability): void
    {
        $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        abort_unless(in_array($node->desired_state, ['active', 'draining'], true), 422, 'Runtime node desired state does not permit execution.');
        abort_unless(DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', $capability)->exists(), 422, 'Runtime node lacks required conference capability.');
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $desiredStates
     */
    private function assertRuntimeNodeEligibleForConferenceRebind(string $tenantId, string $runtimeNodeId, array $capabilities, array $desiredStates = ['active', 'draining']): void
    {
        $node = DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        abort_unless(in_array($node->desired_state, $desiredStates, true), 422, 'Runtime node desired state does not permit execution.');
        abort_unless((string) $node->observed_state === 'ready', 422, 'Runtime node is not ready for conference execution.');
        foreach ($capabilities as $capability) {
            abort_unless(DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', $capability)->exists(), 422, 'Runtime node lacks required conference capability.');
        }
    }

    /**
     * @param  string|list<string>  $capabilities
     * @param  list<string>  $desiredStates
     */
    private function selectRuntimeNodeForConference(string $tenantId, string|array $capabilities, ?string $excludeRuntimeNodeId = null, array $desiredStates = ['active', 'draining']): string
    {
        $requiredCapabilities = is_array($capabilities) ? array_values(array_unique($capabilities)) : [$capabilities];
        $node = DB::table('runtime_nodes')
            ->where('tenant_id', $tenantId)
            ->whereIn('desired_state', $desiredStates)
            ->where('observed_state', 'ready')
            ->when($excludeRuntimeNodeId !== null, fn ($query) => $query->where('id', '<>', $excludeRuntimeNodeId))
            ->orderBy('runtime_family')
            ->orderBy('adapter_key')
            ->orderBy('id');
        foreach ($requiredCapabilities as $capability) {
            $node->whereExists(function ($query) use ($capability): void {
                $query->selectRaw('1')
                    ->from('runtime_node_capabilities')
                    ->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_capabilities.capability_key', $capability);
            });
        }

        $selected = $node->first();

        abort_unless($selected !== null, 422, 'No eligible runtime node is available for conference execution.');

        return (string) $selected->id;
    }

    /**
     * @return list<string>
     */
    private function conferenceRuntimeCapabilities(): array
    {
        return [
            (string) config('telephony_domain.runtime_capabilities.conference_lifecycle', 'conference.lifecycle'),
            (string) config('telephony_domain.runtime_capabilities.conference_participation', 'conference.participation'),
        ];
    }

    /**
     * @param  list<string>|null  $states
     * @return list<string>|null
     */
    private function normalizedStates(?array $states): ?array
    {
        if ($states === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $state): string => trim((string) $state),
            $states,
        ))));
    }

    private function runtimeReadyObservationOlderThan(string $runtimeNodeId, int $graceSeconds): bool
    {
        $latestReady = DB::table('runtime_observations')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('observed_state', 'ready')
            ->max('received_at');

        return is_string($latestReady) && Carbon::parse($latestReady)->lessThanOrEqualTo(now()->subSeconds($graceSeconds));
    }

    /**
     * @return array{status:string,reason:string}|null
     */
    private function validateFailoverFenceEvidence(string $tenantId, string $conferenceId, object $conference, object $activeBinding, string $operationId): ?array
    {
        $operation = DB::table('runtime_operations')
            ->where('id', $operationId)
            ->lockForUpdate()
            ->first();
        if ($operation === null
            || (string) $operation->tenant_id !== $tenantId
            || (string) $operation->aggregate_type !== 'conference'
            || (string) $operation->aggregate_id !== $conferenceId
            || (string) $operation->runtime_node_id !== (string) $activeBinding->runtime_node_id
        ) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_stale'];
        }
        if ((string) $operation->status !== OperationStatus::Succeeded->value) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_missing'];
        }

        $operationType = (string) $operation->operation_type;
        $verifiedAbsenceType = (string) config('telephony_domain.operation_types.verify_conference_absent', 'runtime.node.verify_conference_absent');
        $externalFenceType = (string) config('telephony_domain.operation_types.runtime_fence', 'runtime.node.runtime.fence');
        if (! in_array($operationType, [$verifiedAbsenceType, $externalFenceType], true)) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_not_authoritative'];
        }

        $payload = $this->decodeJsonObject($operation->payload);
        if ((string) ($payload['conference_id'] ?? '') !== $conferenceId
            || (string) ($payload['former_runtime_binding_id'] ?? '') !== (string) $activeBinding->id
            || (string) ($payload['former_runtime_node_id'] ?? '') !== (string) $activeBinding->runtime_node_id
            || (int) ($payload['configuration_generation'] ?? 0) !== (int) $conference->configuration_generation
        ) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_stale'];
        }

        $evidence = $this->completedFenceEvidencePayload($tenantId, $conferenceId, $operationId, $operationType);
        if ($evidence === null) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_missing'];
        }
        if ((string) ($evidence['conference_id'] ?? '') !== $conferenceId
            || (string) ($evidence['former_runtime_binding_id'] ?? '') !== (string) $activeBinding->id
            || (string) ($evidence['former_runtime_node_id'] ?? '') !== (string) $activeBinding->runtime_node_id
            || (int) ($evidence['configuration_generation'] ?? 0) !== (int) $conference->configuration_generation
        ) {
            return ['status' => 'noop', 'reason' => 'fence_evidence_stale'];
        }
        if ($operationType === $verifiedAbsenceType && (string) ($evidence['verification_result'] ?? '') === 'absent') {
            return null;
        }
        if ($operationType === $externalFenceType && in_array((string) ($evidence['fence_result'] ?? ''), ['fenced', 'already_fenced'], true)) {
            return null;
        }

        return ['status' => 'noop', 'reason' => 'fence_evidence_not_authoritative'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completedFenceEvidencePayload(string $tenantId, string $conferenceId, string $operationId, ?string $operationType = null): ?array
    {
        $eventType = $operationType === (string) config('telephony_domain.operation_types.runtime_fence', 'runtime.node.runtime.fence')
            ? 'conference.runtime_fence_terminated'
            : 'conference.runtime_fence_verified';
        $rows = DB::table('control_plane_outbox_messages')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'conference')
            ->where('aggregate_id', $conferenceId)
            ->where('event_type', $eventType)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        foreach ($rows as $row) {
            $payload = $this->decodeJsonObject($row->payload);
            if ((string) ($payload['operation_id'] ?? '') === $operationId) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeBinding(string $tenantId, string $conferenceId, string $runtimeNodeId, ?string $userId): void
    {
        DB::table('conference_runtime_bindings')->insert([
            'id' => TelephonyDomainIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $runtimeNodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conferenceRow(string $tenantId, string $conferenceId): object
    {
        $row = DB::table('conferences')->where('id', $conferenceId)->where('tenant_id', $tenantId)->first();
        abort_unless($row !== null, 404, 'Conference not found.');

        return $row;
    }

    private function hasManageParticipantCapability(Request $request, string $tenantId): bool
    {
        return DB::table('tenant_memberships')
            ->join('tenant_role_assignments', 'tenant_role_assignments.membership_id', '=', 'tenant_memberships.id')
            ->join('role_capabilities', 'role_capabilities.role_key', '=', 'tenant_role_assignments.role_key')
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->where('tenant_memberships.user_id', $request->user()->id)
            ->where('tenant_memberships.status', 'active')
            ->where('role_capabilities.capability_key', 'telephony.conferences.participants.manage')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(Request $request, string $tenantId, string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $safePayload = array_merge([
            'tenant_id' => $tenantId,
            $aggregateType.'_id' => $aggregateId,
        ], $payload);
        $this->audit->append($context, $eventType, $aggregateType, $aggregateId, $safePayload);
        $this->outbox->append(EventEnvelope::forAggregate($eventType, 1, $aggregateType, $aggregateId, $safePayload, $context));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function beginIdempotent(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try {
            $existing = $this->idempotency->begin($scope, $key, $payload);
        } catch (IdempotencyConflict) {
            abort(response()->json(['message' => 'Idempotency key conflict.'], 409));
        }
        if ($existing === null) {
            return null;
        }
        if ($existing->status === 'completed' && $existing->result !== null) {
            return $existing->result;
        }

        abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }

    private function normalizeSlug(string $slug): string
    {
        $value = Str::slug($slug);
        if ($value === '' || strlen($value) > 100) {
            throw new InvalidArgumentException('Invalid conference slug.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'status' => $row->status,
            'issued_at' => $row->issued_at,
            'expires_at' => $row->expires_at,
            'ended_at' => $row->ended_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConference(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'slug' => $row->slug,
            'display_name' => $row->display_name,
            'runtime_node_id' => $row->runtime_node_id,
            'desired_state' => $row->desired_state,
            'observed_state' => $row->observed_state,
            'configuration_generation' => (int) $row->configuration_generation,
            'observed_generation' => $row->observed_generation === null ? null : (int) $row->observed_generation,
            'observed_at' => $row->observed_at,
            'opened_at' => $row->opened_at,
            'draining_at' => $row->draining_at,
            'closed_at' => $row->closed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeParticipant(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'conference_id' => $row->conference_id,
            'telephony_session_id' => $row->telephony_session_id,
            'desired_state' => $row->desired_state,
            'observed_state' => $row->observed_state,
            'role' => $row->role,
            'joined_at' => $row->joined_at,
            'left_at' => $row->left_at,
            'failure_class' => $row->failure_class,
            'failure_code' => $row->failure_code,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
