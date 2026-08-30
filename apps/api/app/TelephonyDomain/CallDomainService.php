<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeRegistry\RuntimeNodeSelector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly C7bService $routes,
        private readonly RuntimeNodeSelector $runtimeNodes,
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
            $existing = $this->existingOrigination($tenantId, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }

            $selectedRuntimeNodeId = $this->runtimeNodes->selectForOutboundCall($tenantId, $runtimeNodeId);

            $routeableDestination = is_string($destinationRef) && str_starts_with($destinationRef, 'telephony_address:');
            $decision = ! $routeableDestination ? null : $this->routes->evaluateOutbound($tenantId, $destinationRef);
            if ($decision !== null && ! $decision->isSelected()) {
                throw new InvalidArgumentException('outbound_route_'.$decision->toArray()['failure_code']);
            }
            $endpoint = $decision === null ? null : $this->routes->resolveOutboundEndpoint($tenantId, $decision);
            $executionDestination = $decision === null ? $destinationRef : $this->routes->executionDestination($tenantId, $decision);
            $callerIdentityId = $decision?->callerIdentityId();
            $callerIdentityAddress = $decision === null || $callerIdentityId === null
                ? null
                : $this->routes->executionCallerIdentityAddress($tenantId, $callerIdentityId);
            $decisionData = $decision === null ? null : [...$decision->toArray(), 'trunk_endpoint_id' => (string) $endpoint->id];

            $callId = TelephonyDomainIds::new();
            $legId = TelephonyDomainIds::new();
            $now = now();

            DB::table('calls')->insert([
                'id' => $callId,
                'tenant_id' => $tenantId,
                'direction' => CallDirection::Outbound->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Requested->value,
                'runtime_node_id' => $selectedRuntimeNodeId,
                'destination_ref' => $decision?->destination()?->canonical() ?? $destinationRef,
                'caller_identity_ref' => $callerIdentityId,
                'route_decision' => $decisionData === null ? null : json_encode($decisionData, JSON_THROW_ON_ERROR),
                'route_decision_source' => $decision === null ? null : 'c7b',
                'requested_by_user_id' => $context->actorId,
                'correlation_id' => $context->correlationId->value(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('call_legs')->insert([
                'id' => $legId,
                'tenant_id' => $tenantId,
                'call_id' => $callId,
                'runtime_node_id' => $selectedRuntimeNodeId,
                'runtime_channel_id' => self::runtimeChannelId($legId),
                'destination_ref' => $decision?->destination()?->canonical() ?? $destinationRef,
                'caller_identity_ref' => $callerIdentityId,
                'route_decision_id' => $decision?->id(),
                'outbound_route_id' => $decision?->routeId(),
                'external_trunk_id' => $decision?->externalTrunkId(),
                'trunk_endpoint_id' => $endpoint?->id,
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
                'runtime_node_id' => $selectedRuntimeNodeId,
                'destination_ref' => $decision?->destination()?->canonical() ?? $destinationRef,
                'caller_identity_id' => $callerIdentityId,
                'caller_identity_address' => $callerIdentityAddress,
                'route_decision_id' => $decision?->id(),
                'outbound_route_id' => $decision?->routeId(),
                'external_trunk_id' => $decision?->externalTrunkId(),
                'trunk_endpoint_id' => $endpoint?->id,
                'runtime_channel_id' => self::runtimeChannelId($legId),
                'destination_uri' => $executionDestination,
            ], $idempotencyKey, $selectedRuntimeNodeId);
            $this->applyCallTransition($tenantId, $callId, CallState::Originating, 'command-requested');
            $this->applyLegTransition($tenantId, $legId, CallState::Originating, 'command-requested');
            $this->reconciliation->ensureTarget($tenantId, 'call_leg_origination', $legId, 1);

            return ['call_id' => $callId, 'leg_id' => $legId, 'operation_id' => $operationId];
        });
    }

    /** @return array{call_id:string, leg_id:string, operation_id:string} */
    public function createOutboundLeg(
        string $tenantId,
        ExecutionContext $context,
        string $callId,
        ?IdempotencyKey $idempotencyKey = null,
        ?string $runtimeNodeId = null,
        ?string $destinationRef = null,
    ): array {
        if ($context->tenantId !== null && $context->tenantId !== $tenantId) {
            throw new InvalidArgumentException('execution context tenant does not match call tenant');
        }

        return DB::transaction(function () use ($tenantId, $context, $callId, $idempotencyKey, $runtimeNodeId, $destinationRef): array {
            $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $callId)->lockForUpdate()->first();
            if ($call === null) {
                throw new InvalidArgumentException('call aggregate not found for tenant');
            }
            if (CallState::from($call->observed_state)->terminal()) {
                throw new InvalidArgumentException('call is not eligible for an additional outbound leg');
            }

            $selectedRuntimeNodeId = $runtimeNodeId ?? $call->runtime_node_id;
            if ($selectedRuntimeNodeId !== null && DB::table('runtime_nodes')->where('id', $selectedRuntimeNodeId)->where('tenant_id', $tenantId)->doesntExist()) {
                throw new InvalidArgumentException('runtime node is not available for this tenant');
            }

            $existing = $this->existingOrigination($tenantId, $idempotencyKey, $callId, $selectedRuntimeNodeId, $destinationRef);
            if ($existing !== null) {
                return $existing;
            }

            $legId = TelephonyDomainIds::new();
            $now = now();
            DB::table('call_legs')->insert([
                'id' => $legId,
                'tenant_id' => $tenantId,
                'call_id' => $callId,
                'runtime_node_id' => $selectedRuntimeNodeId,
                'runtime_channel_id' => self::runtimeChannelId($legId),
                'direction' => CallDirection::Outbound->value,
                'role' => CallLegRole::Destination->value,
                'desired_state' => 'active',
                'observed_state' => CallState::Requested->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $operationId = $this->requestOperation($tenantId, $context, 'call.leg.originate', 'call_leg', $legId, [
                'call_id' => $callId,
                'leg_id' => $legId,
                'runtime_node_id' => $selectedRuntimeNodeId,
                'destination_ref' => $destinationRef,
                'runtime_channel_id' => self::runtimeChannelId($legId),
            ], $idempotencyKey, $selectedRuntimeNodeId);
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

    /**
     * Attach the canonical C7B decision after C6 has observed and adopted the
     * runtime channel. The ingress values are trusted only after the Kamailio
     * boundary and are revalidated against the observation receipt here.
     *
     * @param  array<string, mixed>  $ingress
     * @return array{status:string,route_decision_id:?string}
     */
    public function evaluateAndBindInboundRoute(
        string $tenantId,
        string $callId,
        string $legId,
        string $runtimeNodeId,
        array $ingress,
    ): array {
        return DB::transaction(function () use ($tenantId, $callId, $legId, $runtimeNodeId, $ingress): array {
            $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $callId)->lockForUpdate()->first();
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($call === null || $leg === null || (string) $leg->call_id !== $callId) {
                return ['status' => 'missing_call_leg', 'route_decision_id' => null];
            }

            // Duplicate offered observations must converge on the existing
            // binding without re-running route selection or creating a new
            // decision identity.
            if ($leg->route_decision_id !== null) {
                return ['status' => 'selected', 'route_decision_id' => (string) $leg->route_decision_id];
            }

            $externalTrunkId = $this->uuidValue($ingress['ingress_external_trunk_id'] ?? null);
            $addressId = $this->uuidValue($ingress['ingress_telephony_address_id'] ?? null);
            $endpointId = $this->uuidValue($ingress['ingress_trunk_endpoint_id'] ?? null);
            $selectedNodeId = $this->uuidValue($ingress['ingress_runtime_node_id'] ?? null);
            if ($externalTrunkId === null || $addressId === null || $endpointId === null || $selectedNodeId === null) {
                $this->recordInboundRouteFailure($tenantId, $callId, $legId, 'ingress_correlation_missing');

                return ['status' => 'ingress_correlation_missing', 'route_decision_id' => null];
            }
            if ($selectedNodeId !== $runtimeNodeId) {
                $this->recordInboundRouteFailure($tenantId, $callId, $legId, 'ingress_execution_target_mismatch');

                return ['status' => 'ingress_execution_target_mismatch', 'route_decision_id' => null];
            }

            $trunkTenant = DB::table('external_trunks')->where('id', $externalTrunkId)->value('tenant_id');
            if ((string) $trunkTenant !== $tenantId) {
                $this->recordInboundRouteFailure($tenantId, $callId, $legId, 'ingress_tenant_mismatch');

                return ['status' => 'ingress_tenant_mismatch', 'route_decision_id' => null];
            }

            $endpoint = DB::table('trunk_endpoints')
                ->where('tenant_id', $tenantId)
                ->where('id', $endpointId)
                ->where('external_trunk_id', $externalTrunkId)
                ->first();
            if ($endpoint === null) {
                $this->recordInboundRouteFailure($tenantId, $callId, $legId, 'ingress_correlation_mismatch');

                return ['status' => 'ingress_correlation_mismatch', 'route_decision_id' => null];
            }

            $decision = $this->routes->evaluateInbound($tenantId, $externalTrunkId, $addressId);
            if (! $decision->isSelected()) {
                $failure = $decision->toArray()['failure_code'] ?? 'no_matching_route';
                $this->recordInboundRouteFailure($tenantId, $callId, $legId, (string) $failure, $decision->toArray(), true);

                return ['status' => (string) $failure, 'route_decision_id' => null];
            }

            $destination = $decision->destination()?->canonical();
            $decisionData = [...$decision->toArray(), 'trunk_endpoint_id' => $endpointId];
            DB::table('calls')->where('id', $callId)->update([
                'destination_ref' => $destination,
                'route_decision' => json_encode($decisionData, JSON_THROW_ON_ERROR),
                'route_decision_source' => 'c7b',
                'updated_at' => now(),
            ]);
            DB::table('call_legs')->where('id', $legId)->update([
                'route_decision_id' => $decision->id(),
                'inbound_route_id' => $decision->routeId(),
                'external_trunk_id' => $decision->externalTrunkId(),
                'trunk_endpoint_id' => $endpointId,
                'destination_ref' => $destination,
                'updated_at' => now(),
            ]);
            $this->record(ExecutionContext::system(reason: 'inbound route selected', tenantId: $tenantId, origin: 'telephony-observation'), 'call.inbound_route_selected', 'call', $callId, [
                'call_id' => $callId,
                'leg_id' => $legId,
                'route_decision_id' => $decision->id(),
                'inbound_route_id' => $decision->routeId(),
                'external_trunk_id' => $decision->externalTrunkId(),
                'trunk_endpoint_id' => $endpointId,
                'destination_ref' => $destination,
                'source' => 'c7b',
            ]);

            return ['status' => 'selected', 'route_decision_id' => $decision->id()];
        });
    }

    /** @param array<string,mixed> $details */
    private function recordInboundRouteFailure(string $tenantId, string $callId, string $legId, string $reason, array $details = [], bool $terminate = false): void
    {
        if ($terminate) {
            DB::table('call_legs')->where('id', $legId)->update([
                'desired_state' => 'terminated',
                'observed_state' => CallState::Failed->value,
                'termination_reason' => $reason,
                'termination_party' => 'system',
                'terminated_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('calls')->where('id', $callId)->update([
                'desired_state' => 'terminated',
                'observed_state' => CallState::Failed->value,
                'termination_reason' => $reason,
                'termination_party' => 'system',
                'terminated_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->record(ExecutionContext::system(reason: 'inbound route evaluation', tenantId: $tenantId, origin: 'telephony-observation'), 'call.inbound_route_failed', 'call', $callId, [
            'call_id' => $callId,
            'leg_id' => $legId,
            'reason' => $reason,
            'details' => $details,
            'route_binding' => null,
        ]);
    }

    private function uuidValue(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    public function bindObservedRuntimeChannel(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null) {
                return false;
            }
            if (CallState::from($leg->observed_state)->terminal()) {
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

    public function terminalizeObservedLeg(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId, CallState $terminalState, ?string $observedAt = null, bool $deferPreAnswerAsteriskTermination = false): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId, $terminalState, $observedAt, $deferPreAnswerAsteriskTermination): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || (string) $leg->runtime_node_id !== $runtimeNodeId || (string) $leg->runtime_channel_id !== $runtimeChannelId) {
                return false;
            }
            if (CallState::from($leg->observed_state)->terminal()) {
                return false;
            }
            if ($deferPreAnswerAsteriskTermination
                && $terminalState === CallState::Completed
                && $leg->direction === CallDirection::Outbound->value
                && $leg->answered_at === null
                && ! $this->hasRequestedTerminationIntent($tenantId, (string) $leg->call_id, $legId, $observedAt)) {
                return false;
            }
            [$reason, $party] = $terminalState === CallState::Failed
                ? ['runtime_lost', 'runtime']
                : ($this->hasRequestedTerminationIntent($tenantId, (string) $leg->call_id, $legId, $observedAt) ? ['requested', 'control_plane'] : ['remote', 'remote']);

            return $this->terminalizeObservedLegLocked($tenantId, $leg, $terminalState, $reason, $party, $terminalState === CallState::Failed ? 'call_leg.failed' : 'call_leg.terminated');
        });
    }

    public function terminalizeObservedProviderFailure(string $tenantId, string $legId, string $runtimeNodeId, string $runtimeChannelId, ?string $failureClass, ?string $failureCode, ?string $observedAt = null): bool
    {
        return DB::transaction(function () use ($tenantId, $legId, $runtimeNodeId, $runtimeChannelId, $failureClass, $failureCode, $observedAt): bool {
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->lockForUpdate()->first();
            if ($leg === null || (string) $leg->runtime_node_id !== $runtimeNodeId || CallState::from($leg->observed_state)->terminal()) {
                return false;
            }
            $answered = $leg->answered_at !== null;
            $requested = $this->hasRequestedTerminationIntent($tenantId, (string) $leg->call_id, $legId, $observedAt);
            $state = $answered || $requested ? CallState::Completed : CallState::Failed;
            [$reason, $party] = $requested ? ['requested', 'control_plane'] : ['remote', 'remote'];
            $failureClass = $state === CallState::Failed ? $failureClass : null;
            $failureCode = $state === CallState::Failed ? $failureCode : null;

            return $this->terminalizeObservedLegLocked($tenantId, $leg, $state, $reason, $party, 'call_leg.terminated', $failureClass, $failureCode);
        });
    }

    /**
     * The stale projection is the existing authoritative event-source loss
     * transition. Only legs still bound to that node and not already terminal
     * are failed; an ordinary readiness update never calls this method.
     */
    public function failRuntimeLostLegs(string $tenantId, string $runtimeNodeId): int
    {
        return DB::transaction(function () use ($tenantId, $runtimeNodeId): int {
            $legs = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $runtimeNodeId)
                ->whereNotNull('runtime_channel_id')
                ->whereNotIn('observed_state', array_map(static fn (CallState $state): string => $state->value, [CallState::Completed, CallState::Failed, CallState::Cancelled]))
                ->lockForUpdate()
                ->get();
            $failed = 0;
            foreach ($legs as $leg) {
                if ($this->terminalizeObservedLegLocked($tenantId, $leg, CallState::Failed, 'runtime_lost', 'runtime', 'call_leg.failed')) {
                    $failed++;
                }
            }

            return $failed;
        });
    }

    private function hasRequestedTerminationIntent(string $tenantId, string $callId, string $legId, ?string $observedAt): bool
    {
        $operationTypes = ['call.hangup', 'call.leg.hangup', 'call.leg.cancel_origination'];
        $query = DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->whereIn('operation_type', $operationTypes)
            ->where('status', '!=', 'terminal_failed')
            ->where(function ($aggregate) use ($callId, $legId): void {
                $aggregate->where(function ($call) use ($callId): void {
                    $call->where('aggregate_type', 'call')->where('aggregate_id', $callId);
                })->orWhere(function ($leg) use ($legId): void {
                    $leg->where('aggregate_type', 'call_leg')->where('aggregate_id', $legId);
                });
            });
        if ($observedAt !== null && $observedAt !== '') {
            $query->where('created_at', '<=', $observedAt);
        }

        return $query->exists();
    }

    private function terminalizeObservedLegLocked(string $tenantId, object $leg, CallState $terminalState, string $reason, string $party, string $eventType, ?string $failureClass = null, ?string $failureCode = null): bool
    {
        if (CallState::from($leg->observed_state)->terminal()) {
            return false;
        }
        DB::table('call_legs')->where('id', $leg->id)->update([
            'desired_state' => 'terminated',
            'observed_state' => $terminalState->value,
            'termination_reason' => $reason,
            'termination_party' => $party,
            'failure_class' => $terminalState === CallState::Failed ? $failureClass : null,
            'failure_code' => $terminalState === CallState::Failed ? $failureCode : null,
            'terminated_at' => now(),
            'updated_at' => now(),
        ]);
        $this->record(ExecutionContext::system(reason: 'runtime call leg terminal fact', tenantId: $tenantId, origin: 'telephony-observation'), $eventType, 'call_leg', (string) $leg->id, ['state' => $terminalState->value, 'reason' => $reason, 'party' => $party]);
        $remaining = DB::table('call_legs')->where('tenant_id', $tenantId)->where('call_id', $leg->call_id)->whereNotIn('observed_state', array_map(static fn (CallState $state): string => $state->value, [CallState::Completed, CallState::Failed, CallState::Cancelled]))->exists();
        if (! $remaining) {
            $callState = $terminalState === CallState::Failed ? CallState::Failed : CallState::Completed;
            $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $leg->call_id)->lockForUpdate()->first();
            if ($call !== null && ! CallState::from($call->observed_state)->terminal()) {
                DB::table('calls')->where('id', $call->id)->update([
                    'desired_state' => 'terminated',
                    'observed_state' => $callState->value,
                    'termination_reason' => $reason,
                    'termination_party' => $party,
                    'failure_class' => $callState === CallState::Failed ? $failureClass : null,
                    'failure_code' => $callState === CallState::Failed ? $failureCode : null,
                    'terminated_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->record(ExecutionContext::system(reason: 'runtime call terminal fact', tenantId: $tenantId, origin: 'telephony-observation'), 'call.terminated', 'call', (string) $call->id, ['state' => $callState->value, 'reason' => $reason, 'party' => $party]);
            }
        }

        return true;
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

    /** @return array{call_id:string, leg_id:string, operation_id:string}|null */
    private function existingOrigination(
        string $tenantId,
        ?IdempotencyKey $idempotencyKey,
        ?string $expectedCallId = null,
        ?string $runtimeNodeId = null,
        ?string $destinationRef = null,
    ): ?array {
        if ($idempotencyKey === null) {
            return null;
        }

        $operation = DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('operation_type', 'call.leg.originate')
            ->where('idempotency_key', $idempotencyKey->value())
            ->lockForUpdate()
            ->first();
        if ($operation === null) {
            return null;
        }

        $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $existingCallId = is_string($payload['call_id'] ?? null) ? $payload['call_id'] : null;
        $existingLegId = is_string($payload['leg_id'] ?? null) ? $payload['leg_id'] : null;
        if ($existingCallId === null || $existingLegId === null || $operation->aggregate_type !== 'call_leg') {
            throw new InvalidArgumentException('idempotency key is already used for an incompatible origination operation');
        }
        if ($expectedCallId !== null && $existingCallId !== $expectedCallId) {
            throw new InvalidArgumentException('idempotency key is already used for another call');
        }
        if ($expectedCallId !== null && (($payload['runtime_node_id'] ?? null) !== $runtimeNodeId || ($payload['destination_ref'] ?? null) !== $destinationRef)) {
            throw new InvalidArgumentException('idempotency key is reused with a different leg request');
        }

        $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $existingLegId)->where('call_id', $existingCallId)->first();
        if ($leg === null) {
            throw new InvalidArgumentException('idempotent origination leg is missing');
        }

        return ['call_id' => $existingCallId, 'leg_id' => $existingLegId, 'operation_id' => (string) $operation->id];
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
        $updates = ['observed_state' => $next->value, 'updated_at' => now()];
        if ($next === CallState::Answered && $call->answered_at === null) {
            $updates['answered_at'] = now();
        }
        DB::table('calls')->where('id', $callId)->update($updates);
        $this->record(ExecutionContext::system(reason: 'call state follows leg', tenantId: $tenantId, origin: $source), 'call.state_changed', 'call', $callId, ['from' => $current->value, 'to' => $next->value, 'source' => $source]);
    }

    /** @param array<string, mixed> $payload */
    private function record(ExecutionContext $context, string $event, string $aggregateType, string $aggregateId, array $payload): void
    {
        $this->audit->append($context, $event, $aggregateType, $aggregateId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, $aggregateType, $aggregateId, $payload, $context));
    }
}
