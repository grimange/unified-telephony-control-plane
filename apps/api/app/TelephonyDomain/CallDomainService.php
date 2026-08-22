<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class CallDomainService
{
    /** @var array<string, list<string>> */
    private const CALL_TRANSITIONS = [
        'requested' => ['selecting_route', 'originating'],
        'selecting_route' => ['originating'],
        'originating' => ['ringing', 'early_media', 'answered', 'terminating'],
        'offered' => ['ringing', 'answered', 'terminating'],
        'ringing' => ['early_media', 'answered', 'terminating'],
        'early_media' => ['ringing', 'answered', 'terminating'],
        'answered' => ['bridged', 'transferring', 'terminating'],
        'bridged' => ['answered', 'transferring', 'terminating'],
        'transferring' => ['answered', 'terminating'],
        'terminating' => ['completed', 'failed', 'cancelled'],
    ];

    /** @var array<string, list<string>> */
    private const LEG_TRANSITIONS = [
        'requested' => ['selecting_route', 'originating'],
        'selecting_route' => ['originating'],
        'originating' => ['ringing', 'early_media', 'answered', 'terminating'],
        'offered' => ['ringing', 'answered', 'terminating'],
        'ringing' => ['early_media', 'answered', 'terminating'],
        'early_media' => ['ringing', 'answered', 'terminating'],
        'answered' => ['bridged', 'held', 'transferring', 'terminating'],
        'bridged' => ['answered', 'transferring', 'terminating'],
        'held' => ['answered', 'terminating'],
        'transferring' => ['answered', 'terminating'],
        'terminating' => ['completed', 'failed', 'cancelled'],
    ];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly RuntimeOperationRepository $operations,
        private readonly ReconciliationRepository $reconciliation,
    ) {}

    /** @return array{call_id:string, leg_id:string, operation_id:string} */
    public function createOutboundCall(
        string $tenantId,
        ExecutionContext $context,
        ?IdempotencyKey $idempotencyKey = null,
        ?string $runtimeNodeId = null,
        ?string $destinationRef = null,
    ): array {
        if ($context->tenantId !== null && $context->tenantId !== $tenantId) {
            throw new InvalidArgumentException('execution context tenant does not match call tenant');
        }

        return DB::transaction(function () use ($tenantId, $context, $idempotencyKey, $runtimeNodeId, $destinationRef): array {
            $callId = TelephonyDomainIds::new();
            $legId = TelephonyDomainIds::new();
            $now = now();

            DB::table('calls')->insert([
                'id' => $callId,
                'tenant_id' => $tenantId,
                'direction' => CallDirection::Outbound->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Requested->value,
                'runtime_node_id' => $runtimeNodeId,
                'destination_ref' => $destinationRef,
                'requested_by_user_id' => $context->actorId,
                'correlation_id' => $context->correlationId->value(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('call_legs')->insert([
                'id' => $legId,
                'tenant_id' => $tenantId,
                'call_id' => $callId,
                'runtime_node_id' => $runtimeNodeId,
                'runtime_channel_id' => self::runtimeChannelId($legId),
                'direction' => CallDirection::Outbound->value,
                'role' => CallLegRole::Destination->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Requested->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->record($context, 'call.created', 'call', $callId, ['call_id' => $callId, 'leg_id' => $legId, 'direction' => 'outbound']);
            $operationId = $this->requestOperation($tenantId, $context, 'call.leg.originate', 'call_leg', $legId, [
                'call_id' => $callId,
                'leg_id' => $legId,
                'runtime_node_id' => $runtimeNodeId,
                'destination_ref' => $destinationRef,
                'runtime_channel_id' => self::runtimeChannelId($legId),
            ], $idempotencyKey, $runtimeNodeId);
            $this->applyCallTransition($tenantId, $callId, CallState::Originating, 'command-requested');
            $this->applyLegTransition($tenantId, $legId, CallState::Originating, 'command-requested');
            $this->reconciliation->ensureTarget($tenantId, 'call_leg_origination', $legId, 1);

            return ['call_id' => $callId, 'leg_id' => $legId, 'operation_id' => $operationId];
        });
    }

    public static function runtimeChannelId(string $legId): string
    {
        return 'utcp-call-leg-'.$legId;
    }

    public function terminalizePendingOrigination(string $tenantId, string $legId, string $reason = 'origination_timeout'): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $reason): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || CallState::from($leg->observed_state)->terminal() || ! in_array($leg->observed_state, [CallState::Requested->value, CallState::SelectingRoute->value, CallState::Originating->value], true)) {
                return false;
            }
            DB::table('call_legs')->where('id', $legId)->update(['desired_state' => 'terminated', 'observed_state' => CallState::Failed->value, 'termination_reason' => $reason, 'termination_party' => 'system', 'terminated_at' => now(), 'updated_at' => now()]);
            $this->record(ExecutionContext::system(reason: 'origination reconciled', tenantId: $tenantId, origin: 'canonical-reconciliation'), 'call_leg.terminated', 'call_leg', $legId, ['state' => CallState::Failed->value, 'reason' => $reason, 'source' => 'canonical-reconciliation']);
            $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $leg->call_id)->lockForUpdate()->first();
            if ($call !== null && ! CallState::from($call->observed_state)->terminal()) {
                DB::table('calls')->where('id', $call->id)->update(['desired_state' => 'terminated', 'observed_state' => CallState::Failed->value, 'termination_reason' => $reason, 'termination_party' => 'system', 'terminated_at' => now(), 'updated_at' => now()]);
                $this->record(ExecutionContext::system(reason: 'origination reconciled', tenantId: $tenantId, origin: 'canonical-reconciliation'), 'call.terminated', 'call', (string) $call->id, ['state' => CallState::Failed->value, 'reason' => $reason, 'source' => 'canonical-reconciliation']);
            }

            return true;
        });
    }

    public function applyCallTransition(string $tenantId, string $callId, CallState $next, string $source): void
    {
        $this->applyTransition('calls', $tenantId, $callId, $next, self::CALL_TRANSITIONS, $source);
    }

    public function applyLegTransition(string $tenantId, string $legId, CallState $next, string $source): void
    {
        $this->applyTransition('call_legs', $tenantId, $legId, $next, self::LEG_TRANSITIONS, $source);
    }

    /**
     * Confirm the canonical effect of a successfully completed local call
     * command. Provider acknowledgement is distinct from a runtime
     * observation; this method never creates an observation.
     */
    public function applySuccessfulCallOperation(string $tenantId, string $operationType, string $aggregateType, string $aggregateId): bool
    {
        $next = match ($operationType) {
            'call.leg.hold' => CallState::Held,
            'call.leg.resume' => CallState::Answered,
            default => null,
        };
        if ($next === null || $aggregateType !== 'call_leg') {
            return false;
        }

        return DB::transaction(function () use ($tenantId, $aggregateId, $next): bool {
            $leg = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('id', $aggregateId)
                ->lockForUpdate()
                ->first();
            if ($leg === null) {
                return false;
            }

            $current = CallState::from($leg->observed_state);
            if ($current === $next || $current->terminal() || ! in_array($next->value, self::LEG_TRANSITIONS[$current->value] ?? [], true)) {
                return false;
            }

            $updates = ['observed_state' => $next->value, 'updated_at' => now()];
            if ($next === CallState::Answered) {
                $updates['answered_at'] = now();
            }
            if ($next === CallState::Held) {
                $updates['held'] = true;
            } elseif ($current === CallState::Held && $next === CallState::Answered) {
                $updates['held'] = false;
            }
            DB::table('call_legs')->where('id', $aggregateId)->update($updates);
            $this->record(
                ExecutionContext::system(reason: 'runtime call command acknowledged', tenantId: $tenantId, origin: 'command-confirmed'),
                'call_leg.state_changed',
                'call_leg',
                $aggregateId,
                ['from' => $current->value, 'to' => $next->value, 'source' => 'command-confirmed'],
            );
            $this->advanceCallFromLeg($tenantId, (string) $leg->call_id, $next, 'command-confirmed');

            return true;
        });
    }

    /** @return array{call_id:string,leg_id:string,created:bool} */
    public function adoptInboundLeg(string $runtimeNodeId, string $runtimeChannelId, ?string $remoteIdentity = null, ?string $calledAddress = null): array
    {
        return DB::transaction(function () use ($runtimeNodeId, $runtimeChannelId, $remoteIdentity, $calledAddress): array {
            $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->lockForUpdate()->first();
            if ($node === null || $node->tenant_id === null) {
                throw new InvalidArgumentException('runtime node is not tenant-owned');
            }

            $tenantId = (string) $node->tenant_id;
            $conferenceOwned = DB::table('conference_participants')
                ->where('tenant_id', $tenantId)
                ->where('runtime_channel_id', $runtimeChannelId)
                ->exists();
            if ($conferenceOwned) {
                return ['call_id' => '', 'leg_id' => '', 'created' => false];
            }

            $existing = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $runtimeNodeId)
                ->where('runtime_channel_id', $runtimeChannelId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return ['call_id' => (string) $existing->call_id, 'leg_id' => (string) $existing->id, 'created' => false];
            }

            $callId = TelephonyDomainIds::new();
            $legId = TelephonyDomainIds::new();
            $now = now();
            DB::table('calls')->insert([
                'id' => $callId,
                'tenant_id' => $tenantId,
                'direction' => CallDirection::Inbound->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Offered->value,
                'runtime_node_id' => $runtimeNodeId,
                'destination_ref' => null,
                'route_decision' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('call_legs')->insert([
                'id' => $legId,
                'tenant_id' => $tenantId,
                'call_id' => $callId,
                'runtime_node_id' => $runtimeNodeId,
                'runtime_channel_id' => $runtimeChannelId,
                'direction' => CallDirection::Inbound->value,
                'role' => CallLegRole::Originator->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Offered->value,
                'remote_identity' => $remoteIdentity,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record(ExecutionContext::system(reason: 'inbound call adopted', tenantId: $tenantId, origin: 'telephony-observation'), 'call.created', 'call', $callId, [
                'call_id' => $callId,
                'leg_id' => $legId,
                'direction' => CallDirection::Inbound->value,
                'called_address' => $calledAddress,
            ]);

            return ['call_id' => $callId, 'leg_id' => $legId, 'created' => true];
        });
    }

    public function bindObservedRuntimeChannel(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null) {
                return false;
            }
            if ($leg->runtime_channel_id !== null) {
                if ((string) $leg->runtime_node_id === $runtimeNodeId && (string) $leg->runtime_channel_id === $runtimeChannelId) {
                    return false;
                }

                return false;
            }
            if (DB::table('call_legs')->where('runtime_node_id', $runtimeNodeId)->where('runtime_channel_id', $runtimeChannelId)->exists()) {
                return false;
            }
            DB::table('call_legs')->where('id', $legId)->update([
                'runtime_node_id' => $runtimeNodeId,
                'runtime_channel_id' => $runtimeChannelId,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function applyObservedLegTransition(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId, CallState $next): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId, $next): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || (string) $leg->runtime_node_id !== $runtimeNodeId || (string) $leg->runtime_channel_id !== $runtimeChannelId) {
                return false;
            }
            $current = CallState::from($leg->observed_state);
            if ($current === $next || $current->terminal() || ! in_array($next->value, self::LEG_TRANSITIONS[$current->value] ?? [], true)) {
                return false;
            }
            $updates = ['observed_state' => $next->value, 'updated_at' => now()];
            if ($next === CallState::Answered) {
                $updates['answered_at'] = now();
            }
            if ($next === CallState::Held) {
                $updates['held'] = true;
            } elseif ($current === CallState::Held && $next === CallState::Answered) {
                $updates['held'] = false;
            }
            DB::table('call_legs')->where('id', $legId)->update($updates);
            $this->record(ExecutionContext::system(reason: 'runtime call observation', tenantId: $tenantId, origin: 'telephony-observation'), 'call_leg.state_changed', 'call_leg', $legId, ['from' => $current->value, 'to' => $next->value, 'source' => 'observation-confirmed']);
            $this->advanceCallFromLeg($tenantId, (string) $leg->call_id, $next);

            return true;
        });
    }

    public function terminalizeObservedLeg(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId, CallState $terminalState, string $reason): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId, $terminalState, $reason): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || (string) $leg->runtime_node_id !== $runtimeNodeId || (string) $leg->runtime_channel_id !== $runtimeChannelId) {
                return false;
            }
            if (CallState::from($leg->observed_state)->terminal()) {
                if ($leg->observed_state === $terminalState->value && $leg->termination_reason === $reason) {
                    return false;
                }

                return false;
            }
            DB::table('call_legs')->where('id', $legId)->update([
                'desired_state' => 'terminated',
                'observed_state' => $terminalState->value,
                'termination_reason' => $reason,
                'termination_party' => 'runtime',
                'terminated_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record(ExecutionContext::system(reason: 'runtime call leg terminated', tenantId: $tenantId, origin: 'telephony-observation'), 'call_leg.terminated', 'call_leg', $legId, ['state' => $terminalState->value, 'reason' => $reason]);
            $remaining = DB::table('call_legs')->where('tenant_id', $tenantId)->where('call_id', $leg->call_id)->whereNotIn('observed_state', array_map(static fn (CallState $state): string => $state->value, [CallState::Completed, CallState::Failed, CallState::Cancelled]))->exists();
            if (! $remaining) {
                $callState = $terminalState === CallState::Failed ? CallState::Failed : CallState::Completed;
                $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $leg->call_id)->lockForUpdate()->first();
                if ($call !== null && ! CallState::from($call->observed_state)->terminal()) {
                    DB::table('calls')->where('id', $call->id)->update([
                        'desired_state' => 'terminated',
                        'observed_state' => $callState->value,
                        'termination_reason' => $reason,
                        'termination_party' => 'runtime',
                        'terminated_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->record(ExecutionContext::system(reason: 'runtime call terminated', tenantId: $tenantId, origin: 'telephony-observation'), 'call.terminated', 'call', (string) $call->id, ['state' => $callState->value, 'reason' => $reason]);
                }
            }

            return true;
        });
    }

    /** @param list<string> $legIds */
    public function applyObservedBridge(string $tenantId, array $legIds, string $runtimeNodeId, array $runtimeChannelIds, bool $unbridge = false): bool
    {
        if (count($legIds) !== 2 || count(array_unique($legIds)) !== 2 || count($runtimeChannelIds) !== 2) {
            return false;
        }

        return DB::transaction(function () use ($tenantId, $legIds, $runtimeNodeId, $runtimeChannelIds, $unbridge): bool {
            $legs = DB::table('call_legs')->where('tenant_id', $tenantId)->whereIn('id', $legIds)->lockForUpdate()->get();
            if ($legs->count() !== 2 || $legs->pluck('call_id')->unique()->count() !== 1) {
                return false;
            }
            foreach ($legs as $leg) {
                $index = array_search((string) $leg->id, $legIds, true);
                if ((string) $leg->runtime_node_id !== $runtimeNodeId || (string) $leg->runtime_channel_id !== (string) $runtimeChannelIds[$index] || CallState::from($leg->observed_state)->terminal()) {
                    return false;
                }
            }
            $first = $legs[0];
            $second = $legs[1];
            if ($unbridge) {
                if ((string) $first->bridged_to_leg_id !== (string) $second->id || (string) $second->bridged_to_leg_id !== (string) $first->id) {
                    return false;
                }
                DB::table('call_legs')->whereIn('id', $legIds)->update(['bridged_to_leg_id' => null, 'bridged_at' => null, 'updated_at' => now()]);
                $this->record(ExecutionContext::system(reason: 'runtime call legs unbridged', tenantId: $tenantId, origin: 'telephony-observation'), 'call_legs.unbridged', 'call', (string) $first->call_id, ['leg_ids' => $legIds, 'source' => 'observation-confirmed']);

                return true;
            }
            DB::table('call_legs')->where('id', $first->id)->update(['bridged_to_leg_id' => $second->id, 'bridged_at' => now(), 'updated_at' => now()]);
            DB::table('call_legs')->where('id', $second->id)->update(['bridged_to_leg_id' => $first->id, 'bridged_at' => now(), 'updated_at' => now()]);
            $this->record(ExecutionContext::system(reason: 'runtime call legs bridged', tenantId: $tenantId, origin: 'telephony-observation'), 'call_legs.bridged', 'call', (string) $first->call_id, ['leg_ids' => $legIds, 'source' => 'observation-confirmed']);

            return true;
        });
    }

    public function applyObservedMute(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId, bool $muted): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId, $muted): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || (string) $leg->runtime_node_id !== $runtimeNodeId || (string) $leg->runtime_channel_id !== $runtimeChannelId || CallState::from($leg->observed_state)->terminal()) {
                return false;
            }
            if ((bool) $leg->muted === $muted) {
                return false;
            }
            DB::table('call_legs')->where('id', $legId)->update(['muted' => $muted, 'updated_at' => now()]);
            $this->record(ExecutionContext::system(reason: 'runtime call leg mute changed', tenantId: $tenantId, origin: 'telephony-observation'), 'call_leg.mute_changed', 'call_leg', $legId, ['muted' => $muted, 'source' => 'observation-confirmed']);

            return true;
        });
    }

    public function terminalize(string $tenantId, string $aggregateType, string $id, CallState $terminalState, string $reason, string $party = 'system'): bool
    {
        if (! $terminalState->terminal()) {
            throw new InvalidArgumentException('terminalize requires a terminal call state');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('termination reason is required');
        }

        $table = $aggregateType === 'call' ? 'calls' : ($aggregateType === 'call_leg' ? 'call_legs' : null);
        if ($table === null) {
            throw new InvalidArgumentException('unsupported call aggregate type');
        }

        return DB::transaction(function () use ($tenantId, $table, $aggregateType, $id, $terminalState, $reason, $party): bool {
            $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new InvalidArgumentException('call aggregate not found for tenant');
            }
            if (CallState::from($row->observed_state)->terminal()) {
                if ($row->observed_state === $terminalState->value && $row->termination_reason === $reason && $row->termination_party === $party) {
                    return false;
                }
                throw new LogicException('terminal call metadata is write-once');
            }

            DB::table($table)->where('id', $id)->update([
                'desired_state' => 'terminated',
                'observed_state' => $terminalState->value,
                'termination_reason' => $reason,
                'termination_party' => $party,
                'terminated_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record(ExecutionContext::system(reason: 'call terminalized', tenantId: $tenantId, origin: 'telephony-domain'), $aggregateType.'.terminated', $aggregateType, $id, ['state' => $terminalState->value, 'reason' => $reason, 'party' => $party]);

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    public function requestOperation(string $tenantId, ExecutionContext $context, string $type, string $aggregateType, string $aggregateId, array $payload, ?IdempotencyKey $idempotencyKey = null, ?string $runtimeNodeId = null): string
    {
        $definition = CallOperationCatalog::all()[$type] ?? null;
        if ($definition === null || $definition['target'] !== $aggregateType) {
            throw new InvalidArgumentException('operation target does not match the normalized C6 operation vocabulary');
        }
        if ($aggregateType === 'call') {
            $this->assertTenantRow('calls', $tenantId, $aggregateId);
        } elseif ($aggregateType === 'call_leg') {
            $leg = $this->assertTenantRow('call_legs', $tenantId, $aggregateId);
            $this->assertTenantRow('calls', $tenantId, $leg->call_id);
            if (isset($payload['call_id']) && $payload['call_id'] !== $leg->call_id) {
                throw new InvalidArgumentException('call leg payload crosses call identity');
            }
        } elseif ($aggregateType === 'relationship') {
            $this->assertTenantRow('calls', $tenantId, $aggregateId);
            $legIds = $payload['leg_ids'] ?? null;
            if (! is_array($legIds) || count($legIds) !== 2 || count(array_unique($legIds)) !== 2) {
                throw new InvalidArgumentException('call relationship operations require two distinct leg ids');
            }
            $legs = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $legIds)
                ->get(['id', 'call_id']);
            if ($legs->count() !== 2 || $legs->pluck('call_id')->unique()->count() !== 1 || $legs->first()->call_id !== $aggregateId) {
                throw new InvalidArgumentException('call relationship legs must belong to the same call and tenant');
            }
        }

        $payload['requested_by_user_id'] ??= $context->actorId;

        return $this->operations->create($type, $aggregateType, $aggregateId, $payload, $context, $idempotencyKey, 1, 100, 3, $runtimeNodeId);
    }

    /** @param array<string, list<string>> $transitions */
    private function applyTransition(string $table, string $tenantId, string $id, CallState $next, array $transitions, string $source): void
    {
        if (! in_array($source, ['command-requested', 'command-confirmed', 'observation-confirmed', 'canonical-reconciliation'], true)) {
            throw new InvalidArgumentException('invalid canonical transition source');
        }
        DB::transaction(function () use ($table, $tenantId, $id, $next, $transitions, $source): void {
            $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new InvalidArgumentException('call aggregate not found for tenant');
            }
            $current = CallState::from($row->observed_state);
            if ($current === $next) {
                return;
            }
            if ($current->terminal() || ! in_array($next->value, $transitions[$current->value] ?? [], true)) {
                throw new LogicException("illegal canonical call transition {$current->value} -> {$next->value}");
            }
            $updates = ['observed_state' => $next->value, 'updated_at' => now()];
            if ($next === CallState::Answered) {
                $updates['answered_at'] = now();
            }
            if ($table === 'call_legs' && $next === CallState::Held) {
                $updates['held'] = true;
            } elseif ($table === 'call_legs' && $current === CallState::Held && $next === CallState::Answered) {
                $updates['held'] = false;
            }
            DB::table($table)->where('id', $id)->update($updates);
            $this->record(ExecutionContext::system(reason: 'canonical call state transition', tenantId: $tenantId, origin: $source), $table === 'calls' ? 'call.state_changed' : 'call_leg.state_changed', $table === 'calls' ? 'call' : 'call_leg', $id, ['from' => $current->value, 'to' => $next->value, 'source' => $source]);
        });
    }

    private function assertTenantRow(string $table, string $tenantId, string $id): object
    {
        $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->first();
        if ($row === null) {
            throw new InvalidArgumentException('call aggregate not found for tenant');
        }

        return $row;
    }

    private function advanceCallFromLeg(string $tenantId, string $callId, CallState $legState, string $source = 'observation-confirmed'): void
    {
        $next = match ($legState) {
            CallState::Ringing => CallState::Ringing,
            CallState::EarlyMedia => CallState::EarlyMedia,
            CallState::Answered, CallState::Held, CallState::Bridged => CallState::Answered,
            default => null,
        };
        if ($next === null) {
            return;
        }
        $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $callId)->lockForUpdate()->first();
        if ($call === null || CallState::from($call->observed_state) === $next || CallState::from($call->observed_state)->terminal()) {
            return;
        }
        $current = CallState::from($call->observed_state);
        if (! in_array($next->value, self::CALL_TRANSITIONS[$current->value] ?? [], true)) {
            return;
        }
        DB::table('calls')->where('id', $callId)->update(['observed_state' => $next->value, 'updated_at' => now()]);
        $this->record(ExecutionContext::system(reason: 'call state follows leg', tenantId: $tenantId, origin: $source), 'call.state_changed', 'call', $callId, ['from' => $current->value, 'to' => $next->value, 'source' => $source]);
    }

    /** @param array<string, mixed> $payload */
    private function record(ExecutionContext $context, string $event, string $aggregateType, string $aggregateId, array $payload): void
    {
        $this->audit->append($context, $event, $aggregateType, $aggregateId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, $aggregateType, $aggregateId, $payload, $context));
    }
}
