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
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionService;
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
    public const RECOVERABLE_PARTICIPATION_GRACE_SECONDS = 120;

    private const FAILOVER_STATE_PENDING_NO_CAPACITY = 'pending_no_capacity';

    private const FAILOVER_REASON_NO_REPLACEMENT_AVAILABLE = 'no_replacement_available';

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

    /** @return array<string, mixed>|null */
    public function currentParticipation(string $tenantId, string $userId): ?array
    {
        $participant = DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->join('telephony_sessions', 'telephony_sessions.id', '=', 'conference_participants.telephony_session_id')
            ->where('conference_participants.tenant_id', $tenantId)
            ->where('conference_participants.user_id', $userId)
            ->where('conference_participants.desired_state', 'admitted')
            ->where('conference_participants.admission_reason', 'self_admission')
            ->orderByDesc('conference_participants.created_at')
            ->select('conference_participants.*', 'conferences.desired_state as conference_desired_state', 'telephony_sessions.status as session_status', 'telephony_sessions.expires_at as session_expires_at')
            ->first();

        if ($participant === null) {
            return null;
        }

        $lostAt = $participant->runtime_channel_lost_at === null ? null : Carbon::parse($participant->runtime_channel_lost_at);
        $recoverableUntil = $lostAt?->copy()->addSeconds(self::RECOVERABLE_PARTICIPATION_GRACE_SECONDS);
        $recoverable = $this->isRecoverableParticipation($participant, $tenantId, now());

        return [
            'participant_id' => (string) $participant->id,
            'conference_id' => (string) $participant->conference_id,
            'state' => $participant->runtime_channel_id !== null ? 'active' : ($lostAt === null ? 'awaiting_runtime' : ($recoverable ? 'recoverable' : 'expired')),
            'recoverable' => $recoverable,
            'recoverable_until' => $recoverableUntil?->toISOString(),
        ];
    }

    public function expireRecoverableParticipants(int $limit = 100): int
    {
        $expired = 0;
        $cutoff = now()->subSeconds(self::RECOVERABLE_PARTICIPATION_GRACE_SECONDS);
        $ids = DB::table('conference_participants')
            ->where('desired_state', 'admitted')
            ->where('admission_reason', 'self_admission')
            ->whereNull('runtime_channel_id')
            ->whereNotNull('runtime_channel_lost_at')
            ->where('runtime_channel_lost_at', '<', $cutoff)
            ->orderBy('runtime_channel_lost_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$expired): void {
                $participant = DB::table('conference_participants')->where('id', $id)->lockForUpdate()->first();
                if ($participant === null || ! $this->isGraceExpired($participant, now())) {
                    return;
                }

                DB::table('conference_participants')->where('id', $id)->update([
                    'desired_state' => 'removed',
                    'updated_at' => now(),
                ]);
                $conference = DB::table('conferences')->where('id', $participant->conference_id)->first();
                $tenantId = (string) $participant->tenant_id;
                $this->reconciliation->wakeTarget($tenantId, 'conference_participant', (string) $id, $this->participantDesiredGeneration($conference, 'removed'));
                $context = ExecutionContext::system(reason: 'conference participation recovery grace expired', tenantId: $tenantId, origin: 'telephony-domain');
                $this->audit->append($context, 'conference_participant.removed', 'conference_participant', (string) $id, [
                    'reason' => 'recovery_grace_expired',
                    'conference_id' => $participant->conference_id,
                ]);
                $this->outbox->append(EventEnvelope::forAggregate('conference_participant.removed', 1, 'conference_participant', (string) $id, [
                    'tenant_id' => $tenantId,
                    'conference_participant_id' => (string) $id,
                    'conference_id' => (string) $participant->conference_id,
                    'reason' => 'recovery_grace_expired',
                ], $context));
                $expired++;
            });
        }

        return $expired;
    }

    private function isRecoverableParticipation(object $participant, string $tenantId, Carbon $now): bool
    {
        if ($participant->runtime_channel_id !== null || $participant->runtime_channel_lost_at === null || $participant->conference_desired_state !== 'open' || $participant->session_status !== 'active' || Carbon::parse($participant->session_expires_at)->lessThanOrEqualTo($now)) {
            return false;
        }
        if ($now->greaterThan(Carbon::parse($participant->runtime_channel_lost_at)->addSeconds(self::RECOVERABLE_PARTICIPATION_GRACE_SECONDS))) {
            return false;
        }

        return DB::table('conference_runtime_bindings')
            ->join('conferences', function ($join): void {
                $join->on('conferences.id', '=', 'conference_runtime_bindings.conference_id')
                    ->on('conferences.tenant_id', '=', 'conference_runtime_bindings.tenant_id');
            })
            ->join('runtime_nodes', function ($join): void {
                $join->on('runtime_nodes.id', '=', 'conference_runtime_bindings.runtime_node_id')
                    ->whereColumn('runtime_nodes.tenant_id', 'conference_runtime_bindings.tenant_id');
            })
            ->where('conference_runtime_bindings.conference_id', $participant->conference_id)
            ->where('conference_runtime_bindings.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.status', 'active')
            ->whereColumn('conference_runtime_bindings.runtime_node_id', 'conferences.runtime_node_id')
            ->where('runtime_nodes.desired_state', 'active')
            ->where('runtime_nodes.observed_state', 'ready')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('runtime_node_endpoints')
                    ->whereColumn('runtime_node_endpoints.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_endpoints.purpose', 'sip')
                    ->where('runtime_node_endpoints.transport', 'udp')
                    ->where('runtime_node_endpoints.enabled', true);
            })
            ->exists();
    }

    private function isGraceExpired(object $participant, Carbon $now): bool
    {
        return $participant->desired_state === 'admitted'
            && $participant->admission_reason === 'self_admission'
            && $participant->runtime_channel_id === null
            && $participant->runtime_channel_lost_at !== null
            && $now->greaterThan(Carbon::parse($participant->runtime_channel_lost_at)->addSeconds(self::RECOVERABLE_PARTICIPATION_GRACE_SECONDS));
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
                $this->selectRuntimeNodeForConference(
                    $tenantId,
                    $this->conferenceRuntimeCapabilities(),
                    requestedRuntimeNodeId: $runtimeNodeId,
                );
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
            if ($desiredState === 'open' && ((string) $conference->desired_state !== 'open' || $runtimeNodeId === null)) {
                $runtimeNodeId = $this->selectRuntimeNodeForConference(
                    $tenantId,
                    $this->conferenceRuntimeCapabilities(),
                    conferenceId: $conferenceId,
                    requestedRuntimeNodeId: $runtimeNodeId,
                    returnNullWhenUnavailable: $runtimeNodeId === null,
                );
            }

            $nextGeneration = ((int) $conference->configuration_generation) + 1;
            $fields = [
                'desired_state' => $desiredState,
                'configuration_generation' => $nextGeneration,
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
                $fields['failover_state'] = null;
                $fields['failover_binding_id'] = null;
                $fields['failover_generation'] = null;
                $fields['failover_started_at'] = null;
            }
            if ($desiredState === 'open' && $conference->runtime_node_id === null && $runtimeNodeId !== null) {
                $fields['runtime_node_id'] = $runtimeNodeId;
            }
            if ($desiredState === 'open' && $runtimeNodeId === null) {
                $fields['failover_state'] = self::FAILOVER_STATE_PENDING_NO_CAPACITY;
                $fields['failover_binding_id'] = null;
                $fields['failover_generation'] = $nextGeneration;
                $fields['failover_started_at'] = now();
            }
            if ($desiredState === 'open' && $conference->runtime_node_id === null && $runtimeNodeId !== null) {
                $fields['failover_state'] = null;
                $fields['failover_binding_id'] = null;
                $fields['failover_generation'] = null;
                $fields['failover_started_at'] = null;
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
            $this->selectRuntimeNodeForConference(
                $tenantId,
                $this->conferenceRuntimeCapabilities(),
                conferenceId: $conferenceId,
                requestedRuntimeNodeId: $runtimeNodeId,
            );
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
            $replacementDesiredStates = array_values(array_diff(
                $this->normalizedStates($options['replacement_desired_states'] ?? null) ?? ['active'],
                ['draining', 'retired'],
            ));
            if ($replacementDesiredStates === []) {
                return ['status' => 'noop', 'reason' => 'no_eligible_replacement_runtime'];
            }
            $replacementRuntimeNodeId = $this->selectRuntimeNodeForConference(
                $tenantId,
                $requiredCapabilities,
                (string) $activeBinding->runtime_node_id,
                $replacementDesiredStates,
                conferenceId: $conferenceId,
            );

            if ($replacementRuntimeNodeId === (string) $activeBinding->runtime_node_id) {
                return ['status' => 'noop', 'reason' => 'replacement_runtime_node_not_distinct'];
            }
            $this->assertRuntimeNodeEligibleForConferenceRebind($tenantId, $replacementRuntimeNodeId, $requiredCapabilities, $replacementDesiredStates, $conferenceId);

            DB::table('conference_runtime_bindings')->where('id', $activeBinding->id)->update([
                'status' => 'retired',
                'unbound_at' => now(),
                'updated_at' => now(),
            ]);
            $this->writeBinding($tenantId, $conferenceId, $replacementRuntimeNodeId, $context->actorId ?? $conference->updated_by);
            DB::table('conferences')->where('id', $conferenceId)->update([
                'runtime_node_id' => $replacementRuntimeNodeId,
                'configuration_generation' => DB::raw('configuration_generation + 1'),
                'failover_state' => null,
                'failover_binding_id' => null,
                'failover_generation' => null,
                'failover_started_at' => null,
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

    /**
     * @return array{candidates:int,retired:int,skipped:int}
     */
    public function retireClosedConferenceBindings(int $batchSize = 100, ?string $tenantId = null, string $reason = 'conference_closed'): array
    {
        $limit = max(1, min(500, $batchSize));
        $candidates = DB::table('conferences')
            ->join('conference_runtime_bindings', function ($join): void {
                $join->on('conference_runtime_bindings.conference_id', '=', 'conferences.id')
                    ->on('conference_runtime_bindings.tenant_id', '=', 'conferences.tenant_id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->where('conferences.desired_state', 'closed')
            ->where('conferences.observed_state', 'closed')
            ->when($tenantId !== null, fn ($query) => $query->where('conferences.tenant_id', $tenantId))
            ->orderBy('conferences.updated_at')
            ->orderBy('conferences.id')
            ->limit($limit)
            ->get([
                'conferences.id as conference_id',
                'conferences.tenant_id',
                'conferences.runtime_node_id as conference_runtime_node_id',
                'conferences.configuration_generation',
                'conference_runtime_bindings.id as binding_id',
                'conference_runtime_bindings.runtime_node_id as binding_runtime_node_id',
            ]);

        $summary = [
            'candidates' => $candidates->count(),
            'retired' => 0,
            'skipped' => 0,
        ];

        foreach ($candidates as $candidate) {
            if ($this->retireClosedConferenceBindingCandidate($candidate, $reason)) {
                $summary['retired']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{candidates:int,inspected:int,woken:int,skipped:int,unavailable:int}
     */
    public function reclaimOrphanParticipantChannels(int $batchSize = 100, ?RuntimeConferenceInspectionService $inspections = null): array
    {
        $limit = max(1, min(500, $batchSize));
        $candidates = DB::table('conference_participants')
            ->join('conferences', function ($join): void {
                $join->on('conferences.id', '=', 'conference_participants.conference_id')
                    ->on('conferences.tenant_id', '=', 'conference_participants.tenant_id');
            })
            ->where('conference_participants.desired_state', 'removed')
            ->where('conferences.desired_state', 'closed')
            ->where('conferences.observed_state', 'closed')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conference_runtime_bindings')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->whereColumn('conference_runtime_bindings.conference_id', 'conferences.id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conference_runtime_bindings')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->whereColumn('conference_runtime_bindings.conference_id', 'conferences.id')
                    ->where('conference_runtime_bindings.status', 'retired')
                    ->whereNotNull('conference_runtime_bindings.runtime_node_id');
            })
            ->orderBy('conference_participants.updated_at')
            ->orderBy('conference_participants.id')
            ->limit($limit)
            ->get([
                'conference_participants.id as participant_id',
                'conference_participants.tenant_id',
                'conference_participants.conference_id',
                'conference_participants.telephony_session_id',
                'conferences.configuration_generation',
            ]);

        $summary = [
            'candidates' => $candidates->count(),
            'inspected' => 0,
            'woken' => 0,
            'skipped' => 0,
            'unavailable' => 0,
        ];
        $inspectionService = $inspections ?? app(RuntimeConferenceInspectionService::class);

        foreach ($candidates as $candidate) {
            $binding = $this->latestRuntimeBindingForConference((string) $candidate->tenant_id, (string) $candidate->conference_id);
            if ($binding === null || (string) $binding->runtime_node_id === '') {
                $summary['skipped']++;

                continue;
            }

            $inspection = $inspectionService->inspect((string) $candidate->tenant_id, (string) $binding->runtime_node_id, (string) $candidate->conference_id, (string) $candidate->participant_id);
            $summary['inspected']++;
            if (in_array($inspection->status, ['unavailable', 'failed'], true)) {
                $summary['unavailable']++;

                continue;
            }
            if ($inspection->status !== 'observed' || ! ((bool) $inspection->participantPresent || (bool) $inspection->participantAttached)) {
                $summary['skipped']++;

                continue;
            }

            if ($this->wakeOrphanParticipantReconciliationCandidate($candidate, $binding)) {
                $summary['woken']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function recordOrphanParticipantChannelReclaimed(array $evidence): bool
    {
        return DB::transaction(function () use ($evidence): bool {
            $tenantId = (string) ($evidence['tenant_id'] ?? '');
            $conferenceId = (string) ($evidence['conference_id'] ?? '');
            $participantId = (string) ($evidence['participant_id'] ?? '');
            $bindingId = (string) ($evidence['runtime_binding_id'] ?? '');
            $runtimeNodeId = (string) ($evidence['runtime_node_id'] ?? '');
            $generation = (int) ($evidence['conference_generation'] ?? 0);
            if ($tenantId === '' || $conferenceId === '' || $participantId === '' || $bindingId === '' || $runtimeNodeId === '' || $generation < 1) {
                return false;
            }

            $conference = DB::table('conferences')->where('id', $conferenceId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $participant = DB::table('conference_participants')->where('id', $participantId)->where('tenant_id', $tenantId)->where('conference_id', $conferenceId)->lockForUpdate()->first();
            $binding = DB::table('conference_runtime_bindings')->where('id', $bindingId)->where('tenant_id', $tenantId)->where('conference_id', $conferenceId)->lockForUpdate()->first();
            if ($conference === null || $participant === null || $binding === null) {
                return false;
            }
            if ((string) $conference->desired_state !== 'closed' || (string) $conference->observed_state !== 'closed') {
                return false;
            }
            if ((string) $participant->desired_state !== 'removed') {
                return false;
            }
            if ((int) $conference->configuration_generation !== $generation || (string) $binding->runtime_node_id !== $runtimeNodeId) {
                return false;
            }
            if ((string) $binding->status !== 'retired') {
                return false;
            }
            if (! $this->isLatestRuntimeBinding((string) $binding->tenant_id, (string) $binding->conference_id, (string) $binding->id)) {
                return false;
            }
            if (DB::table('control_plane_outbox_messages')
                ->where('event_type', 'conference_participant.channel_reclaimed')
                ->where('aggregate_type', 'conference_participant')
                ->where('aggregate_id', $participantId)
                ->exists()) {
                return false;
            }

            $context = ExecutionContext::system(reason: 'orphan_participant_channel_reclaimed', tenantId: $tenantId, origin: 'telephony-domain');
            $payload = [
                'tenant_id' => $tenantId,
                'conference_id' => $conferenceId,
                'conference_participant_id' => $participantId,
                'participant_id' => $participantId,
                'telephony_session_id' => (string) ($evidence['telephony_session_id'] ?? $participant->telephony_session_id ?? ''),
                'runtime_binding_id' => $bindingId,
                'conference_generation' => $generation,
                'configuration_generation' => $generation,
                'runtime_node_id' => $runtimeNodeId,
                'primary_channel_id' => (string) ($evidence['primary_channel_id'] ?? ''),
                'peer_channel_id' => (string) ($evidence['peer_channel_id'] ?? ''),
                'classification' => (string) ($evidence['classification'] ?? 'post_closure_orphan'),
                'attempt_id' => (string) ($evidence['operation_id'] ?? ''),
                'operation_id' => (string) ($evidence['operation_id'] ?? ''),
                'outcome' => (string) ($evidence['outcome'] ?? 'reclaimed'),
                'occurred_at' => $context->occurredAt->toISOString(),
            ];
            $this->audit->append($context, 'conference_participant.channel_reclaimed', 'conference_participant', $participantId, $payload, 'post_closure_orphan');
            $this->outbox->append(EventEnvelope::forAggregate('conference_participant.channel_reclaimed', 1, 'conference_participant', $participantId, $payload, $context));

            return true;
        });
    }

    private function retireClosedConferenceBindingCandidate(object $candidate, string $reason): bool
    {
        return DB::transaction(function () use ($candidate, $reason): bool {
            $conference = DB::table('conferences')
                ->where('id', (string) $candidate->conference_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->lockForUpdate()
                ->first();
            if ($conference === null) {
                return false;
            }
            if ((string) $conference->desired_state !== 'closed' || (string) $conference->observed_state !== 'closed') {
                return false;
            }
            if ((int) $conference->configuration_generation !== (int) $candidate->configuration_generation) {
                return false;
            }
            if ((string) $conference->runtime_node_id !== (string) $candidate->conference_runtime_node_id) {
                return false;
            }

            $binding = DB::table('conference_runtime_bindings')
                ->where('id', (string) $candidate->binding_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->where('conference_id', (string) $candidate->conference_id)
                ->lockForUpdate()
                ->first();
            if ($binding === null || (string) $binding->status !== 'active') {
                return false;
            }
            if ((string) $binding->runtime_node_id !== (string) $candidate->binding_runtime_node_id) {
                return false;
            }
            if ((string) $binding->runtime_node_id !== (string) $conference->runtime_node_id) {
                return false;
            }

            $activeBindingId = DB::table('conference_runtime_bindings')
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->where('conference_id', (string) $candidate->conference_id)
                ->where('status', 'active')
                ->value('id');
            if ((string) $activeBindingId !== (string) $candidate->binding_id) {
                return false;
            }

            $unboundAt = now();
            $updated = DB::table('conference_runtime_bindings')
                ->where('id', (string) $binding->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'retired',
                    'unbound_at' => $unboundAt,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return false;
            }

            $normalizedReason = mb_substr(trim($reason) === '' ? 'conference_closed' : $reason, 0, 120);
            $context = ExecutionContext::system(
                reason: $normalizedReason,
                tenantId: (string) $candidate->tenant_id,
                origin: 'telephony-domain',
            );
            $safePayload = [
                'tenant_id' => (string) $candidate->tenant_id,
                'conference_id' => (string) $candidate->conference_id,
                'runtime_binding_id' => (string) $binding->id,
                'runtime_node_id' => (string) $binding->runtime_node_id,
                'conference_generation' => (int) $conference->configuration_generation,
                'configuration_generation' => (int) $conference->configuration_generation,
                'retirement_reason' => $normalizedReason,
                'reason' => $normalizedReason,
                'source_transition' => 'conference_closed',
                'unbound_at' => $unboundAt->toISOString(),
                'occurred_at' => $context->occurredAt->toISOString(),
            ];
            $this->audit->append($context, 'conference.runtime_binding_retired', 'conference', (string) $candidate->conference_id, $safePayload, $normalizedReason);
            $this->outbox->append(EventEnvelope::forAggregate('conference.runtime_binding_retired', 1, 'conference', (string) $candidate->conference_id, $safePayload, $context));

            return true;
        });
    }

    private function wakeOrphanParticipantReconciliationCandidate(object $candidate, object $binding): bool
    {
        return DB::transaction(function () use ($candidate, $binding): bool {
            $conference = DB::table('conferences')
                ->where('id', (string) $candidate->conference_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->lockForUpdate()
                ->first();
            $participant = DB::table('conference_participants')
                ->where('id', (string) $candidate->participant_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->where('conference_id', (string) $candidate->conference_id)
                ->lockForUpdate()
                ->first();
            if ($conference === null || $participant === null) {
                return false;
            }
            if ((string) $conference->desired_state !== 'closed' || (string) $conference->observed_state !== 'closed') {
                return false;
            }
            if ((string) $participant->desired_state !== 'removed') {
                return false;
            }
            if ((int) $conference->configuration_generation !== (int) $candidate->configuration_generation) {
                return false;
            }
            if (DB::table('conference_runtime_bindings')
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->where('conference_id', (string) $candidate->conference_id)
                ->where('status', 'active')
                ->exists()) {
                return false;
            }
            if (! $this->isLatestRuntimeBinding((string) $candidate->tenant_id, (string) $candidate->conference_id, (string) $binding->id)) {
                return false;
            }

            $this->reconciliation->wakeTarget(
                (string) $candidate->tenant_id,
                'conference_participant',
                (string) $candidate->participant_id,
                $this->participantDesiredGeneration($conference, 'removed'),
            );

            return true;
        });
    }

    private function latestRuntimeBindingForConference(string $tenantId, string $conferenceId): ?object
    {
        return DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('status', 'retired')
            ->whereNotNull('runtime_node_id')
            ->orderByDesc('bound_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function isLatestRuntimeBinding(string $tenantId, string $conferenceId, string $bindingId): bool
    {
        $latest = $this->latestRuntimeBindingForConference($tenantId, $conferenceId);

        return $latest !== null && (string) $latest->id === $bindingId;
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
                conferenceId: $conferenceId,
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
    public function markConferenceFailoverPendingNoCapacity(
        ExecutionContext $context,
        string $tenantId,
        string $conferenceId,
        string $bindingId,
        int $generation,
        string $runtimeNodeId,
        string $reason = self::FAILOVER_REASON_NO_REPLACEMENT_AVAILABLE,
    ): array {
        return DB::transaction(function () use ($context, $tenantId, $conferenceId, $bindingId, $generation, $runtimeNodeId, $reason): array {
            $conference = DB::table('conferences')
                ->where('tenant_id', $tenantId)
                ->where('id', $conferenceId)
                ->lockForUpdate()
                ->first();
            if ($conference === null) {
                return ['status' => 'noop', 'reason' => 'conference_not_found'];
            }
            if ((string) $conference->desired_state !== 'open') {
                return ['status' => 'noop', 'reason' => 'conference_not_open'];
            }
            if ((int) $conference->configuration_generation !== $generation) {
                return ['status' => 'noop', 'reason' => 'generation_changed'];
            }

            $activeBinding = DB::table('conference_runtime_bindings')
                ->where('tenant_id', $tenantId)
                ->where('conference_id', $conferenceId)
                ->where('id', $bindingId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($activeBinding === null || (string) $activeBinding->runtime_node_id !== $runtimeNodeId) {
                return ['status' => 'noop', 'reason' => 'active_binding_changed'];
            }

            if (
                (string) ($conference->failover_state ?? '') === self::FAILOVER_STATE_PENDING_NO_CAPACITY
                && (string) ($conference->failover_binding_id ?? '') === $bindingId
                && (int) ($conference->failover_generation ?? 0) === $generation
            ) {
                return [
                    'status' => self::FAILOVER_STATE_PENDING_NO_CAPACITY,
                    'transitioned' => false,
                    'failover_started_at' => $conference->failover_started_at,
                ];
            }

            $startedAt = now();
            DB::table('conferences')->where('id', $conferenceId)->update([
                'failover_state' => self::FAILOVER_STATE_PENDING_NO_CAPACITY,
                'failover_binding_id' => $bindingId,
                'failover_generation' => $generation,
                'failover_started_at' => $startedAt,
                'updated_by' => $context->actorId,
                'updated_at' => now(),
            ]);

            $payload = [
                'tenant_id' => $tenantId,
                'conference_id' => $conferenceId,
                'active_binding_id' => $bindingId,
                'binding_id' => $bindingId,
                'generation' => $generation,
                'runtime_node_id' => $runtimeNodeId,
                'failure_reason' => mb_substr($reason, 0, 120),
                'reason' => self::FAILOVER_REASON_NO_REPLACEMENT_AVAILABLE,
                'failover_state' => self::FAILOVER_STATE_PENDING_NO_CAPACITY,
                'failover_started_at' => $startedAt->toISOString(),
                'coordinator_observed_at' => now()->toISOString(),
                'idempotency_key' => $this->failoverNoCapacityTransitionKey($tenantId, $conferenceId, $bindingId, $generation),
            ];
            $this->audit->append($context, 'conference.failover_coordinator.no_replacement', 'conference', $conferenceId, $payload);
            $this->outbox->append(EventEnvelope::forAggregate('conference.failover_coordinator.no_replacement', 1, 'conference', $conferenceId, $payload, $context));

            return [
                'status' => self::FAILOVER_STATE_PENDING_NO_CAPACITY,
                'transitioned' => true,
                'failover_started_at' => $startedAt->toISOString(),
            ];
        });
    }

    public function clearConferenceFailoverPendingForAuthority(string $tenantId, string $conferenceId, string $bindingId, int $generation): bool
    {
        return DB::transaction(function () use ($tenantId, $conferenceId, $bindingId, $generation): bool {
            $updated = DB::table('conferences')
                ->where('tenant_id', $tenantId)
                ->where('id', $conferenceId)
                ->where('failover_state', self::FAILOVER_STATE_PENDING_NO_CAPACITY)
                ->where('failover_binding_id', $bindingId)
                ->where('failover_generation', $generation)
                ->update([
                    'failover_state' => null,
                    'failover_binding_id' => null,
                    'failover_generation' => null,
                    'failover_started_at' => null,
                    'updated_at' => now(),
                ]);

            return $updated > 0;
        });
    }

    public function clearRecoveredFailoverPendingNoCapacity(int $graceSeconds): int
    {
        if (! DB::table('conferences')->where('failover_state', self::FAILOVER_STATE_PENDING_NO_CAPACITY)->exists()) {
            return 0;
        }

        $cutoff = now()->subSeconds(max(1, $graceSeconds));
        $rows = DB::table('conferences')
            ->select([
                'conferences.id as conference_id',
                'conferences.tenant_id',
                'conferences.failover_binding_id',
                'conferences.failover_generation',
            ])
            ->join('conference_runtime_bindings', function ($join): void {
                $join->on('conference_runtime_bindings.id', '=', 'conferences.failover_binding_id')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->whereColumn('conference_runtime_bindings.conference_id', 'conferences.id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->join('runtime_nodes', function ($join): void {
                $join->on('runtime_nodes.id', '=', 'conference_runtime_bindings.runtime_node_id')
                    ->whereColumn('runtime_nodes.tenant_id', 'conferences.tenant_id');
            })
            ->where('conferences.desired_state', 'open')
            ->where('conferences.failover_state', self::FAILOVER_STATE_PENDING_NO_CAPACITY)
            ->whereColumn('conferences.configuration_generation', 'conferences.failover_generation')
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('runtime_nodes.observed_state', ['ready', 'degraded'])
                    ->orWhereExists(function ($subquery) use ($cutoff): void {
                        $subquery->selectRaw('1')
                            ->from('runtime_observations')
                            ->whereColumn('runtime_observations.runtime_node_id', 'runtime_nodes.id')
                            ->where('runtime_observations.observed_state', 'ready')
                            ->where('runtime_observations.received_at', '>', $cutoff);
                    });
            })
            ->limit(100)
            ->get();

        $cleared = 0;
        foreach ($rows as $row) {
            if ($this->clearConferenceFailoverPendingForAuthority(
                (string) $row->tenant_id,
                (string) $row->conference_id,
                (string) $row->failover_binding_id,
                (int) $row->failover_generation,
            )) {
                $cleared++;
            }
        }

        $initialRows = DB::table('conferences')
            ->select(['id as conference_id', 'tenant_id'])
            ->where('desired_state', 'open')
            ->where('failover_state', self::FAILOVER_STATE_PENDING_NO_CAPACITY)
            ->whereNull('failover_binding_id')
            ->whereNull('runtime_node_id')
            ->orderBy('failover_started_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($initialRows as $row) {
            if ($this->retryInitialPendingNoCapacityConference((string) $row->tenant_id, (string) $row->conference_id)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    private function retryInitialPendingNoCapacityConference(string $tenantId, string $conferenceId): bool
    {
        return DB::transaction(function () use ($tenantId, $conferenceId): bool {
            $conference = DB::table('conferences')
                ->where('tenant_id', $tenantId)
                ->where('id', $conferenceId)
                ->lockForUpdate()
                ->first();
            if ($conference === null
                || (string) $conference->desired_state !== 'open'
                || (string) ($conference->failover_state ?? '') !== self::FAILOVER_STATE_PENDING_NO_CAPACITY
                || $conference->failover_binding_id !== null
                || $conference->runtime_node_id !== null
            ) {
                return false;
            }

            $runtimeNodeId = $this->selectRuntimeNodeForConference(
                $tenantId,
                $this->conferenceRuntimeCapabilities(),
                conferenceId: $conferenceId,
                returnNullWhenUnavailable: true,
            );
            if ($runtimeNodeId === null) {
                return false;
            }

            $nextGeneration = ((int) $conference->configuration_generation) + 1;
            DB::table('conferences')->where('id', $conferenceId)->update([
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => $nextGeneration,
                'failover_state' => null,
                'failover_binding_id' => null,
                'failover_generation' => null,
                'failover_started_at' => null,
                'updated_at' => now(),
            ]);
            $this->writeBinding(
                $tenantId,
                $conferenceId,
                $runtimeNodeId,
                is_string($conference->updated_by ?? null) ? (string) $conference->updated_by : null,
            );
            $this->reconciliation->wakeTarget($tenantId, 'conference', $conferenceId, $nextGeneration);

            return true;
        });
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
                return [
                    'participant' => $this->serializeParticipant($existing),
                    'signaling_destination' => $this->conferenceParticipantSignalingDestination((string) $existing->id),
                ];
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

            return [
                'participant' => $this->serializeParticipant(DB::table('conference_participants')->where('id', $id)->first()),
                'signaling_destination' => $this->conferenceParticipantSignalingDestination($id),
            ];
        });

        if ($key !== null) {
            $this->idempotency->complete('telephony.conference_participants.admit', $key, $result);
        }

        return $result;
    }

    private function conferenceParticipantSignalingDestination(string $participantId): string
    {
        $realm = config('telephony_signaling.realm');
        abort_unless(is_string($realm) && trim($realm) !== '', 500, 'Telephony signaling realm is not configured.');

        return 'sip:conf-'.$participantId.'@'.$realm;
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
        $this->selectRuntimeNodeForConference($tenantId, $capability, requestedRuntimeNodeId: $runtimeNodeId);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $desiredStates
     */
    private function assertRuntimeNodeEligibleForConferenceRebind(string $tenantId, string $runtimeNodeId, array $capabilities, array $desiredStates = ['active'], ?string $conferenceId = null): void
    {
        $this->selectRuntimeNodeForConference(
            $tenantId,
            $capabilities,
            desiredStates: $desiredStates,
            conferenceId: $conferenceId,
            requestedRuntimeNodeId: $runtimeNodeId,
        );
    }

    /**
     * @param  string|list<string>  $capabilities
     * @param  list<string>  $desiredStates
     */
    private function selectRuntimeNodeForConference(
        string $tenantId,
        string|array $capabilities,
        ?string $excludeRuntimeNodeId = null,
        array $desiredStates = ['active'],
        ?string $conferenceId = null,
        ?string $requestedRuntimeNodeId = null,
        bool $returnNullWhenUnavailable = false,
    ): ?string {
        $requiredCapabilities = is_array($capabilities) ? array_values(array_unique($capabilities)) : [$capabilities];
        $usage = DB::table('conference_runtime_bindings')
            ->select('runtime_node_id', DB::raw('count(*) as active_binding_count'))
            ->where('status', 'active')
            ->when($conferenceId !== null, fn ($query) => $query->where('conference_id', '<>', $conferenceId))
            ->groupBy('runtime_node_id');
        $query = DB::table('runtime_nodes')
            ->leftJoinSub($usage, 'active_bindings', function ($join): void {
                $join->on('active_bindings.runtime_node_id', '=', 'runtime_nodes.id');
            })
            ->where('tenant_id', $tenantId)
            ->whereIn('desired_state', $desiredStates)
            ->where('observed_state', 'ready')
            ->select([
                'runtime_nodes.id',
                'runtime_nodes.tenant_id',
                'runtime_nodes.desired_state',
                'runtime_nodes.observed_state',
                'runtime_nodes.placement_priority',
                'runtime_nodes.capacity_weight',
                DB::raw('coalesce(active_bindings.active_binding_count, 0) as active_binding_count'),
            ])
            ->when($requestedRuntimeNodeId !== null, fn ($nodeQuery) => $nodeQuery->where('runtime_nodes.id', $requestedRuntimeNodeId))
            ->when($requestedRuntimeNodeId === null && $excludeRuntimeNodeId !== null, fn ($nodeQuery) => $nodeQuery->where('runtime_nodes.id', '<>', $excludeRuntimeNodeId));
        foreach ($requiredCapabilities as $capability) {
            $query->whereExists(function ($capabilityQuery) use ($capability): void {
                $capabilityQuery->selectRaw('1')
                    ->from('runtime_node_capabilities')
                    ->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_capabilities.capability_key', $capability);
            });
        }

        $candidates = $query->get()
            ->filter(fn (object $node): bool => $this->runtimeNodeHasConferenceCapacity($node, (int) $node->active_binding_count))
            ->values()
            ->all();

        usort($candidates, function (object $left, object $right): int {
            return [
                (int) $left->placement_priority,
                -$this->conferenceAvailableSlotRank($left, (int) $left->active_binding_count),
                (int) $left->active_binding_count,
                (string) $left->id,
            ] <=> [
                (int) $right->placement_priority,
                -$this->conferenceAvailableSlotRank($right, (int) $right->active_binding_count),
                (int) $right->active_binding_count,
                (string) $right->id,
            ];
        });

        foreach ($candidates as $candidate) {
            $node = DB::table('runtime_nodes')
                ->where('id', (string) $candidate->id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            if ($node === null) {
                continue;
            }
            if (! in_array((string) $node->desired_state, $desiredStates, true)
                || (string) $node->observed_state !== 'ready'
                || ($excludeRuntimeNodeId !== null && $requestedRuntimeNodeId === null && (string) $node->id === $excludeRuntimeNodeId)
                || ! $this->runtimeNodeHasCapabilities((string) $node->id, $requiredCapabilities)
            ) {
                continue;
            }

            $activeBindingCount = $this->activeConferenceBindingCountForRuntimeNode((string) $node->id, $conferenceId);
            if (! $this->runtimeNodeHasConferenceCapacity($node, $activeBindingCount)) {
                continue;
            }

            return (string) $node->id;
        }

        if ($requestedRuntimeNodeId !== null) {
            $requested = DB::table('runtime_nodes')->where('id', $requestedRuntimeNodeId)->where('tenant_id', $tenantId)->first();
            abort_unless($requested !== null, 404, 'Runtime node not found.');
            abort(422, 'Runtime node is not eligible for conference execution.');
        }

        if ($returnNullWhenUnavailable) {
            return null;
        }

        abort(422, 'No eligible runtime node is available for conference execution.');
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function runtimeNodeHasCapabilities(string $runtimeNodeId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! DB::table('runtime_node_capabilities')->where('runtime_node_id', $runtimeNodeId)->where('capability_key', $capability)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function activeConferenceBindingCountForRuntimeNode(string $runtimeNodeId, ?string $excludeConferenceId = null): int
    {
        return (int) DB::table('conference_runtime_bindings')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('status', 'active')
            ->when($excludeConferenceId !== null, fn ($query) => $query->where('conference_id', '<>', $excludeConferenceId))
            ->count();
    }

    private function runtimeNodeHasConferenceCapacity(object $node, int $activeBindingCount): bool
    {
        $capacity = (int) ($node->capacity_weight ?? 0);

        return $capacity === 0 || $activeBindingCount < $capacity;
    }

    private function conferenceAvailableSlotRank(object $node, int $activeBindingCount): int
    {
        $capacity = (int) ($node->capacity_weight ?? 0);

        return $capacity === 0 ? PHP_INT_MAX : max(0, $capacity - $activeBindingCount);
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
        $existing = DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        if ($existing !== null && (string) $existing->runtime_node_id === $runtimeNodeId) {
            return;
        }

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

    private function failoverNoCapacityTransitionKey(string $tenantId, string $conferenceId, string $bindingId, int $generation): string
    {
        return hash('sha256', implode(':', [
            $tenantId,
            $conferenceId,
            $bindingId,
            (string) $generation,
            self::FAILOVER_REASON_NO_REPLACEMENT_AVAILABLE,
        ]));
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
        $binding = $this->conferenceBindingSnapshot((string) $row->tenant_id, (string) $row->id);

        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'slug' => $row->slug,
            'display_name' => $row->display_name,
            'runtime_node_id' => $row->runtime_node_id,
            'active_runtime_binding_id' => $binding['active_runtime_binding_id'],
            'active_binding_runtime_node_id' => $binding['active_binding_runtime_node_id'],
            'runtime_binding_lifecycle_status' => $binding['runtime_binding_lifecycle_status'],
            'last_runtime_binding_retirement_reason' => $binding['last_runtime_binding_retirement_reason'],
            'last_runtime_binding_retired_at' => $binding['last_runtime_binding_retired_at'],
            'desired_state' => $row->desired_state,
            'observed_state' => $row->observed_state,
            'failover_state' => $row->failover_state ?? null,
            'failover_binding_id' => $row->failover_binding_id ?? null,
            'failover_generation' => ($row->failover_generation ?? null) === null ? null : (int) $row->failover_generation,
            'failover_started_at' => $row->failover_started_at ?? null,
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
     * @return array{
     *     active_runtime_binding_id:?string,
     *     active_binding_runtime_node_id:?string,
     *     runtime_binding_lifecycle_status:?string,
     *     last_runtime_binding_retirement_reason:?string,
     *     last_runtime_binding_retired_at:?string
     * }
     */
    private function conferenceBindingSnapshot(string $tenantId, string $conferenceId): array
    {
        $active = DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('status', 'active')
            ->orderByDesc('bound_at')
            ->first();
        $retired = DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->where('status', 'retired')
            ->whereNotNull('unbound_at')
            ->orderByDesc('unbound_at')
            ->first();
        $retirementReason = null;
        if ($retired !== null) {
            $metadata = DB::table('control_plane_audit_records')
                ->where('tenant_id', $tenantId)
                ->where('subject_type', 'conference')
                ->where('subject_id', $conferenceId)
                ->where('action', 'conference.runtime_binding_retired')
                ->orderByDesc('occurred_at')
                ->value('metadata');
            $decoded = $this->decodeJsonObject($metadata);
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            $reason = $data['retirement_reason'] ?? $data['reason'] ?? null;
            $retirementReason = is_string($reason) && $reason !== '' ? $reason : null;
        }

        return [
            'active_runtime_binding_id' => $active === null ? null : (string) $active->id,
            'active_binding_runtime_node_id' => $active === null ? null : (string) $active->runtime_node_id,
            'runtime_binding_lifecycle_status' => $active === null ? ($retired === null ? null : 'retired') : 'active',
            'last_runtime_binding_retirement_reason' => $retirementReason,
            'last_runtime_binding_retired_at' => $retired === null ? null : (string) $retired->unbound_at,
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
