<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\Simulator\Events\SimulatorEventNormalizer;
use App\Simulator\SimulatorCatalog;
use App\TelephonyDomain\CallDomainService;
use App\TelephonyDomain\CallObservationProcessor;
use App\TelephonyDomain\CallState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CallObservationProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_offered_observation_is_persisted_and_adopts_one_inbound_call_leg(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'inbound-channel-1';

        $this->emit('offered-1', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, [
            'runtime_channel_id' => $channel,
            'remote_identity' => '+15550100',
            'called_address' => null,
        ]);

        $leg = DB::table('call_legs')->where('runtime_node_id', $nodeId)->where('runtime_channel_id', $channel)->first();
        $this->assertNotNull($leg);
        $this->assertSame('inbound', DB::table('calls')->where('id', $leg->call_id)->value('direction'));
        $this->assertSame('offered', $leg->observed_state);
        $this->assertSame($tenantId, $leg->tenant_id);
        $this->assertNull($leg->route_decision_id);
        $this->assertNull($leg->inbound_route_id);
        $this->assertDatabaseHas('control_plane_audit_records', ['action' => 'call.inbound_route_failed']);
        $failure = DB::table('control_plane_audit_records')->where('action', 'call.inbound_route_failed')->first();
        $metadata = json_decode((string) $failure->metadata, true);
        $this->assertSame('ingress_correlation_missing', $metadata['data']['reason']);
        $this->assertDatabaseHas('runtime_observations', ['observation_type' => 'call.leg.offered', 'subject_id' => 'runtime:'.$nodeId.':'.$channel]);

        $this->emit('offered-2', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel]);

        $this->assertSame(1, DB::table('call_legs')->where('runtime_node_id', $nodeId)->where('runtime_channel_id', $channel)->count());
        $this->assertSame(1, DB::table('calls')->where('direction', 'inbound')->count());
        $this->assertSame(2, DB::table('runtime_observations')->where('observation_type', 'call.leg.offered')->count());
    }

    public function test_valid_external_inbound_offer_is_bound_by_c7b_after_c6_adoption(): void
    {
        $fixture = $this->inboundFixture();
        $channel = 'inbound-bound';

        $this->emit('inbound-bound', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', 'runtime:'.$fixture['node_id'].':'.$channel, [
            'runtime_channel_id' => $channel,
            'remote_identity' => '+15550100',
            'called_address' => 'utcp-in-'.$fixture['address_id'],
            'ingress_external_trunk_id' => $fixture['trunk_id'],
            'ingress_telephony_address_id' => $fixture['address_id'],
            'ingress_trunk_endpoint_id' => $fixture['endpoint_id'],
            'ingress_runtime_node_id' => $fixture['node_id'],
        ]);

        $leg = DB::table('call_legs')->where('runtime_channel_id', $channel)->first();
        $call = DB::table('calls')->where('id', $leg->call_id)->first();
        $decision = json_decode((string) $call->route_decision, true);
        $this->assertSame(1, DB::table('calls')->count());
        $this->assertSame(1, DB::table('call_legs')->count());
        $this->assertSame($fixture['route_id'], $leg->inbound_route_id);
        $this->assertSame($fixture['route_id'], $decision['route_id']);
        $this->assertSame($fixture['trunk_id'], $leg->external_trunk_id);
        $this->assertSame($fixture['endpoint_id'], $leg->trunk_endpoint_id);
        $this->assertSame('opaque:application-entry', $leg->destination_ref);
        $this->assertSame('opaque:application-entry', $call->destination_ref);
        $this->assertSame('c7b', $call->route_decision_source);
        $this->assertSame('selected', $decision['status']);
        $this->assertArrayNotHasKey('provider', $decision);
    }

    public function test_duplicate_inbound_offer_reuses_the_existing_call_leg_and_binding(): void
    {
        $fixture = $this->inboundFixture();
        $payload = [
            'runtime_channel_id' => 'inbound-duplicate',
            'ingress_external_trunk_id' => $fixture['trunk_id'],
            'ingress_telephony_address_id' => $fixture['address_id'],
            'ingress_trunk_endpoint_id' => $fixture['endpoint_id'],
            'ingress_runtime_node_id' => $fixture['node_id'],
        ];
        $subject = 'runtime:'.$fixture['node_id'].':inbound-duplicate';
        $this->emit('inbound-duplicate-1', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', $subject, $payload);
        $leg = DB::table('call_legs')->where('runtime_channel_id', 'inbound-duplicate')->first();
        $decisionId = $leg->route_decision_id;
        $this->emit('inbound-duplicate-2', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', $subject, $payload);

        $this->assertSame(1, DB::table('calls')->count());
        $this->assertSame(1, DB::table('call_legs')->count());
        $this->assertSame($decisionId, DB::table('call_legs')->where('runtime_channel_id', 'inbound-duplicate')->value('route_decision_id'));
    }

    public function test_partial_and_malformed_inbound_correlation_is_observable_without_binding(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        foreach ([
            ['partial', ['ingress_external_trunk_id' => Str::uuid()->toString()]],
            ['malformed', ['ingress_external_trunk_id' => 'not-a-uuid', 'ingress_telephony_address_id' => Str::uuid()->toString(), 'ingress_trunk_endpoint_id' => Str::uuid()->toString(), 'ingress_runtime_node_id' => $nodeId]],
        ] as [$channel, $correlation]) {
            $this->emit('missing-'.$channel, $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel, ...$correlation]);
            $leg = DB::table('call_legs')->where('runtime_channel_id', $channel)->first();
            $this->assertNotNull($leg);
            $this->assertNull($leg->route_decision_id);
            $this->assertNull($leg->inbound_route_id);
            $this->assertSame('offered', $leg->observed_state);
        }
        $reasons = DB::table('control_plane_audit_records')->where('action', 'call.inbound_route_failed')->pluck('metadata')->map(fn (string $metadata): string => json_decode($metadata, true)['data']['reason'])->all();
        $this->assertSame(['ingress_correlation_missing', 'ingress_correlation_missing'], $reasons);
    }

    public function test_inbound_execution_target_mismatch_is_rejected_without_fallback(): void
    {
        $fixture = $this->inboundFixture();
        $this->emit('runtime-mismatch', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', 'runtime:'.$fixture['node_id'].':runtime-mismatch', [
            'runtime_channel_id' => 'runtime-mismatch',
            'ingress_external_trunk_id' => $fixture['trunk_id'],
            'ingress_telephony_address_id' => $fixture['address_id'],
            'ingress_trunk_endpoint_id' => $fixture['endpoint_id'],
            'ingress_runtime_node_id' => Str::uuid()->toString(),
        ]);
        $leg = DB::table('call_legs')->where('runtime_channel_id', 'runtime-mismatch')->first();
        $this->assertNull($leg->route_decision_id);
        $this->assertSame('ingress_execution_target_mismatch', $this->lastInboundFailureReason());
    }

    public function test_cross_tenant_inbound_correlation_is_rejected_before_route_evaluation(): void
    {
        $fixture = $this->inboundFixture();
        $other = $this->inboundFixture();
        $this->emit('tenant-mismatch', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', 'runtime:'.$fixture['node_id'].':tenant-mismatch', [
            'runtime_channel_id' => 'tenant-mismatch',
            'ingress_external_trunk_id' => $other['trunk_id'],
            'ingress_telephony_address_id' => $other['address_id'],
            'ingress_trunk_endpoint_id' => $other['endpoint_id'],
            'ingress_runtime_node_id' => $fixture['node_id'],
        ]);
        $leg = DB::table('call_legs')->where('runtime_channel_id', 'tenant-mismatch')->first();
        $this->assertSame($fixture['tenant_id'], $leg->tenant_id);
        $this->assertNull($leg->external_trunk_id);
        $this->assertSame('ingress_tenant_mismatch', $this->lastInboundFailureReason());
    }

    public function test_trunk_endpoint_correlation_mismatch_is_rejected_without_lookup_fallback(): void
    {
        $fixture = $this->inboundFixture();
        $other = $this->inboundFixture($fixture['tenant_id']);
        $this->emit('endpoint-mismatch', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', 'runtime:'.$fixture['node_id'].':endpoint-mismatch', [
            'runtime_channel_id' => 'endpoint-mismatch',
            'ingress_external_trunk_id' => $fixture['trunk_id'],
            'ingress_telephony_address_id' => $fixture['address_id'],
            'ingress_trunk_endpoint_id' => $other['endpoint_id'],
            'ingress_runtime_node_id' => $fixture['node_id'],
        ]);
        $leg = DB::table('call_legs')->where('runtime_channel_id', 'endpoint-mismatch')->first();
        $this->assertNull($leg->route_decision_id);
        $this->assertSame('ingress_correlation_mismatch', $this->lastInboundFailureReason());
    }

    public function test_inbound_route_failures_are_deterministic_and_do_not_bind_a_route(): void
    {
        foreach ([
            'missing-route' => ['with_route' => false, 'health' => null],
            'ineligible-route' => ['with_route' => true, 'health' => 'unknown'],
        ] as $channel => $case) {
            $fixture = $this->inboundFixture(withRoute: $case['with_route']);
            if ($case['health'] !== null) {
                DB::table('external_trunks')->where('id', $fixture['trunk_id'])->update(['observed_health' => $case['health']]);
            }
            $this->emit('route-failure-'.$channel, $fixture['tenant_id'], $fixture['node_id'], 'call.leg.offered', 'runtime:'.$fixture['node_id'].':'.$channel, [
                'runtime_channel_id' => $channel,
                'ingress_external_trunk_id' => $fixture['trunk_id'],
                'ingress_telephony_address_id' => $fixture['address_id'],
                'ingress_trunk_endpoint_id' => $fixture['endpoint_id'],
                'ingress_runtime_node_id' => $fixture['node_id'],
            ]);
            $leg = DB::table('call_legs')->where('runtime_channel_id', $channel)->first();
            $this->assertNull($leg->route_decision_id);
            $this->assertNull($leg->inbound_route_id);
            $this->assertSame('failed', $leg->observed_state);
        }
        $reasons = DB::table('control_plane_audit_records')->where('action', 'call.inbound_route_failed')->pluck('metadata')->map(fn (string $metadata): string => json_decode($metadata, true)['data']['reason'])->all();
        $this->assertContains('no_matching_route', $reasons);
        $this->assertContains('no_eligible_route', $reasons);
    }

    public function test_duplicate_source_event_is_processed_once_and_dtmf_does_not_change_lifecycle(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'dtmf-channel-1';
        $this->emit('offer', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel]);
        $legId = (string) DB::table('call_legs')->where('runtime_channel_id', $channel)->value('id');

        $this->emit('answer', $tenantId, $nodeId, 'call.leg.ringing', $legId, ['runtime_channel_id' => $channel]);
        $this->emit('answer-2', $tenantId, $nodeId, 'call.leg.answered', $legId, ['runtime_channel_id' => $channel]);
        $dtmf = $this->emit('dtmf-1', $tenantId, $nodeId, 'call.leg.dtmf_received', $legId, ['runtime_channel_id' => $channel, 'digit' => '1', 'source' => 'remote']);
        $this->emit('dtmf-1', $tenantId, $nodeId, 'call.leg.dtmf_received', $legId, ['runtime_channel_id' => $channel, 'digit' => '1', 'source' => 'remote'], $dtmf['epoch']);

        $this->assertSame('answered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame(1, DB::table('runtime_observations')->where('observation_type', 'call.leg.dtmf_received')->count());
        $payload = json_decode((string) DB::table('runtime_observations')->where('observation_type', 'call.leg.dtmf_received')->value('payload'), true);
        $this->assertSame('1', $payload['digit']);
        $this->assertArrayNotHasKey('meaning', $payload);
    }

    public function test_answered_fact_before_inbound_adoption_is_caught_up_by_exact_channel(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'catch-up-answered';

        $answered = $this->emit('answer-before-offer', $tenantId, $nodeId, 'call.leg.answered', 'runtime:'.$channel, ['runtime_channel_id' => $channel]);
        $this->assertDatabaseCount('calls', 0);
        $this->assertDatabaseCount('call_legs', 0);

        $this->emit('offer-after-answer', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel], $answered['epoch']);

        $leg = DB::table('call_legs')->where('runtime_node_id', $nodeId)->where('runtime_channel_id', $channel)->first();
        $this->assertNotNull($leg);
        $this->assertSame('answered', $leg->observed_state);
        $this->assertSame(1, DB::table('calls')->count());
        $this->assertSame(1, DB::table('call_legs')->count());
    }

    public function test_pre_adoption_ringing_and_answered_facts_apply_in_occurrence_order_once_identity_exists(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'catch-up-sequence';

        $ring = $this->emit('ring-before-offer', $tenantId, $nodeId, 'call.leg.ringing', 'runtime:'.$channel, ['runtime_channel_id' => $channel]);
        $answer = $this->emit('answer-before-offer', $tenantId, $nodeId, 'call.leg.answered', 'runtime:'.$channel, ['runtime_channel_id' => $channel], $ring['epoch']);
        DB::table('runtime_observations')->where('source_event_id', $ring['id'])->update(['observed_at' => now()->subSecond()]);
        DB::table('runtime_observations')->where('source_event_id', $answer['id'])->update(['observed_at' => now()]);

        $this->emit('offer-after-sequence', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel], $ring['epoch']);

        $this->assertSame('answered', DB::table('call_legs')->where('runtime_channel_id', $channel)->value('observed_state'));
    }

    public function test_pre_adoption_terminated_fact_terminalizes_after_inbound_adoption_and_reconsideration_is_idempotent(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'catch-up-terminated';
        $terminated = $this->emit('terminated-before-offer', $tenantId, $nodeId, 'call.leg.terminated', 'runtime:'.$channel, ['runtime_channel_id' => $channel, 'termination_reason' => 'remote']);
        $epoch = $terminated['epoch'];

        $this->emit('offer-after-terminated', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel], $epoch);
        $legId = (string) DB::table('call_legs')->where('runtime_channel_id', $channel)->value('id');
        $this->assertSame('completed', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame('completed', DB::table('calls')->where('id', DB::table('call_legs')->where('id', $legId)->value('call_id'))->value('observed_state'));

        $this->emit('offer-retry', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel], $epoch);
        $this->assertSame(1, DB::table('calls')->count());
        $this->assertSame(1, DB::table('call_legs')->count());
    }

    public function test_pre_adoption_fact_for_another_channel_is_not_applied_to_adopted_channel(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->emit('other-channel-answer', $tenantId, $nodeId, 'call.leg.answered', 'runtime:other-channel', ['runtime_channel_id' => 'other-channel']);

        $this->emit('target-offer', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':target-channel', ['runtime_channel_id' => 'target-channel']);

        $this->assertSame('offered', DB::table('call_legs')->where('runtime_channel_id', 'target-channel')->value('observed_state'));
        $this->assertSame(1, DB::table('calls')->count());
    }

    public function test_observations_progress_without_regression_and_terminal_metadata_is_fenced(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'fenced-channel-1';
        $this->emit('offer', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel]);
        $legId = (string) DB::table('call_legs')->where('runtime_channel_id', $channel)->value('id');
        $this->emit('ring', $tenantId, $nodeId, 'call.leg.ringing', $legId, ['runtime_channel_id' => $channel]);
        $this->emit('answer', $tenantId, $nodeId, 'call.leg.answered', $legId, ['runtime_channel_id' => $channel]);
        $this->emit('late-ring', $tenantId, $nodeId, 'call.leg.ringing', $legId, ['runtime_channel_id' => $channel]);
        $this->assertSame('answered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));

        app(CallDomainService::class)->requestOperation($tenantId, ExecutionContext::system(tenantId: $tenantId), 'call.leg.hangup', 'call_leg', $legId, [
            'call_id' => DB::table('call_legs')->where('id', $legId)->value('call_id'),
        ], runtimeNodeId: $nodeId);
        $this->emit('hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'requested']);
        $this->emit('duplicate-hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'requested']);
        $this->emit('conflicting-hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'busy']);

        $this->assertSame('completed', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame('requested', DB::table('call_legs')->where('id', $legId)->value('termination_reason'));
        $this->assertSame('control_plane', DB::table('call_legs')->where('id', $legId)->value('termination_party'));
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->count());

        $this->emit('late-old-channel', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => 'old-channel', 'termination_reason' => 'busy']);
        $this->assertSame('requested', DB::table('call_legs')->where('id', $legId)->value('termination_reason'));
    }

    public function test_authoritative_runtime_stale_transition_fails_only_bound_non_terminal_legs(): void
    {
        [$tenantId, $staleNodeId] = $this->runtimeNode();
        $healthyNodeId = Str::uuid()->toString();
        DB::table('runtime_nodes')->insert([
            'id' => $healthyNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Healthy second runtime',
            'slug' => 'healthy-'.str_replace('-', '', $healthyNodeId),
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => now(),
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_node_capabilities')->insert([
            'id' => Str::uuid()->toString(),
            'runtime_node_id' => $healthyNodeId,
            'capability_key' => 'call.control',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->where('id', $staleNodeId)->update(['observed_state' => 'ready', 'observed_at' => now()->subHour()]);

        $staleCall = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), runtimeNodeId: $staleNodeId);
        $healthyCall = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), runtimeNodeId: $healthyNodeId);

        $this->assertSame(1, (new ProjectionService)->markStale(60));
        $this->assertSame('failed', DB::table('call_legs')->where('id', $staleCall['leg_id'])->value('observed_state'));
        $this->assertSame('runtime_lost', DB::table('call_legs')->where('id', $staleCall['leg_id'])->value('termination_reason'));
        $this->assertSame('runtime', DB::table('call_legs')->where('id', $staleCall['leg_id'])->value('termination_party'));
        $this->assertSame('originating', DB::table('call_legs')->where('id', $healthyCall['leg_id'])->value('observed_state'));
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'call_leg.failed')->where('subject_id', $staleCall['leg_id'])->count());
    }

    public function test_outbound_leg_progresses_from_originating_to_answered_only_from_observations(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $created = app(CallDomainService::class)->createOutboundCall(
            $tenantId,
            ExecutionContext::system(tenantId: $tenantId),
            runtimeNodeId: $nodeId,
        );
        app(CallDomainService::class)->applyCallTransition($tenantId, $created['call_id'], CallState::Originating, 'canonical-reconciliation');
        app(CallDomainService::class)->applyLegTransition($tenantId, $created['leg_id'], CallState::Originating, 'canonical-reconciliation');

        $channel = CallDomainService::runtimeChannelId($created['leg_id']);

        $this->emit('outbound-ring', $tenantId, $nodeId, 'call.leg.ringing', $created['leg_id'], ['runtime_channel_id' => $channel]);
        $this->emit('outbound-answer', $tenantId, $nodeId, 'call.leg.answered', $created['leg_id'], ['runtime_channel_id' => $channel]);

        $this->assertSame('answered', DB::table('call_legs')->where('id', $created['leg_id'])->value('observed_state'));
        $this->assertSame('answered', DB::table('calls')->where('id', $created['call_id'])->value('observed_state'));
        $this->assertSame($channel, DB::table('call_legs')->where('id', $created['leg_id'])->value('runtime_channel_id'));
    }

    public function test_bridge_and_unbridge_observations_are_symmetric_and_stale_unbridge_is_ignored(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $first = $this->adopt($nodeId, 'bridge-a');
        $second = $this->adopt($nodeId, 'bridge-b');
        DB::table('call_legs')->where('id', $second['leg_id'])->update(['call_id' => $first['call_id']]);
        foreach ([$first['leg_id'], $second['leg_id']] as $legId) {
            app(CallDomainService::class)->applyObservedLegTransition($tenantId, $legId, $nodeId, (string) DB::table('call_legs')->where('id', $legId)->value('runtime_channel_id'), CallState::Ringing);
            app(CallDomainService::class)->applyObservedLegTransition($tenantId, $legId, $nodeId, (string) DB::table('call_legs')->where('id', $legId)->value('runtime_channel_id'), CallState::Answered);
        }

        $payload = ['leg_ids' => [$first['leg_id'], $second['leg_id']], 'runtime_channel_ids' => ['bridge-a', 'bridge-b']];
        $this->emit('bridge', $tenantId, $nodeId, 'call.leg.bridged', $first['leg_id'], $payload);
        $this->assertSame($second['leg_id'], DB::table('call_legs')->where('id', $first['leg_id'])->value('bridged_to_leg_id'));
        $this->assertSame($first['leg_id'], DB::table('call_legs')->where('id', $second['leg_id'])->value('bridged_to_leg_id'));

        $this->emit('unbridge-stale', $tenantId, $nodeId, 'call.leg.unbridged', $first['leg_id'], ['leg_ids' => [$first['leg_id'], $second['leg_id']], 'runtime_channel_ids' => ['old-a', 'old-b']]);
        $this->assertSame($second['leg_id'], DB::table('call_legs')->where('id', $first['leg_id'])->value('bridged_to_leg_id'));

        $this->emit('unbridge', $tenantId, $nodeId, 'call.leg.unbridged', $first['leg_id'], $payload);
        $this->assertNull(DB::table('call_legs')->where('id', $first['leg_id'])->value('bridged_to_leg_id'));
        $this->assertNull(DB::table('call_legs')->where('id', $second['leg_id'])->value('bridged_to_leg_id'));
    }

    public function test_conference_owned_channel_is_not_adopted_by_generic_call_authority(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $conferenceId = Str::uuid()->toString();
        $userId = Str::uuid()->toString();
        DB::table('users')->insert(['id' => $userId, 'email' => 'c6c-'.substr($userId, 0, 8).'@example.test', 'normalized_email' => 'c6c-'.substr($userId, 0, 8).'@example.test', 'display_name' => 'C6C user', 'password' => 'irrelevant', 'created_at' => now(), 'updated_at' => now()]);
        $sessionId = Str::uuid()->toString();
        DB::table('telephony_sessions')->insert(['id' => $sessionId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'active', 'issued_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conferences')->insert(['id' => $conferenceId, 'tenant_id' => $tenantId, 'slug' => 'c6c-'.substr($conferenceId, 0, 8), 'display_name' => 'C6C isolation', 'desired_state' => 'open', 'observed_state' => 'ready', 'configuration_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conference_participants')->insert(['id' => Str::uuid()->toString(), 'tenant_id' => $tenantId, 'conference_id' => $conferenceId, 'telephony_session_id' => $sessionId, 'user_id' => $userId, 'runtime_channel_id' => 'conference-owned', 'desired_state' => 'admitted', 'observed_state' => 'joined', 'created_at' => now(), 'updated_at' => now()]);

        $this->emit('conference-channel', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':conference-owned', ['runtime_channel_id' => 'conference-owned']);

        $this->assertDatabaseCount('calls', 0);
        $this->assertDatabaseCount('call_legs', 0);
        $this->assertDatabaseHas('conference_participants', ['runtime_channel_id' => 'conference-owned', 'observed_state' => 'joined']);
    }

    public function test_closed_epoch_observation_is_retained_but_does_not_mutate_canonical_state(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'epoch-channel';
        $offered = $this->emit('offer', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel]);
        $legId = (string) DB::table('call_legs')->where('runtime_channel_id', $channel)->value('id');
        DB::table('runtime_event_connection_epochs')->where('id', $offered['epoch'])->update(['status' => 'closed']);

        $staleReceipt = (object) ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'connection_epoch_id' => $offered['epoch']];
        app(CallObservationProcessor::class)->process($staleReceipt, [
            'observation_type' => 'call.leg.answered',
            'subject_id' => $legId,
            'payload' => ['runtime_channel_id' => $channel],
        ]);

        $this->assertSame('offered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
    }

    public function test_late_offered_after_origination_timeout_is_persisted_without_reopening(): void
    {
        $this->assertLateObservationAfterTimeout('call.leg.offered', 'late-offered', now()->subSeconds(90)->toISOString());
    }

    public function test_late_answered_after_origination_timeout_cannot_set_answered_at_or_reopen_call(): void
    {
        $fixture = $this->timeoutOutboundFixture();
        $this->emit('late-answered', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.answered', $fixture['leg_id'], [
            'runtime_channel_id' => $fixture['channel_id'],
            'occurred_at' => now()->subSeconds(90)->toISOString(),
        ]);

        $leg = DB::table('call_legs')->where('id', $fixture['leg_id'])->first();
        $call = DB::table('calls')->where('id', $fixture['call_id'])->first();
        $this->assertSame('failed', $leg->observed_state);
        $this->assertSame('origination_timeout', $leg->termination_reason);
        $this->assertSame('system', $leg->termination_party);
        $this->assertNull($leg->answered_at);
        $this->assertSame('failed', $call->observed_state);
        $this->assertNull($call->answered_at);
        $this->assertDatabaseHas('runtime_observations', ['observation_type' => 'call.leg.answered', 'subject_id' => $fixture['leg_id']]);
    }

    public function test_late_orderly_termination_after_origination_timeout_preserves_timeout_metadata(): void
    {
        $this->assertLateObservationAfterTimeout('call.leg.terminated', 'late-terminated', now()->subSeconds(90)->toISOString());
    }

    public function test_late_failure_after_origination_timeout_preserves_timeout_metadata(): void
    {
        $this->assertLateObservationAfterTimeout('call.leg.failed', 'late-failed', now()->subSeconds(90)->toISOString());
    }

    public function test_duplicate_late_terminal_observation_does_not_add_canonical_terminal_audit(): void
    {
        $fixture = $this->timeoutOutboundFixture();
        $payload = ['runtime_channel_id' => $fixture['channel_id'], 'occurred_at' => now()->subSeconds(90)->toISOString()];
        $this->emit('duplicate-late', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.terminated', $fixture['leg_id'], $payload);
        $before = DB::table('call_legs')->where('id', $fixture['leg_id'])->first();
        $auditCount = DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->where('subject_id', $fixture['leg_id'])->count();
        $this->emit('duplicate-late', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.terminated', $fixture['leg_id'], $payload);
        $after = DB::table('call_legs')->where('id', $fixture['leg_id'])->first();
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame($auditCount, DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->where('subject_id', $fixture['leg_id'])->count());
    }

    public function test_answered_observation_first_suppresses_origination_timeout(): void
    {
        $fixture = $this->timeoutOutboundFixture(backdate: false);
        $this->emit('answered-first', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.answered', $fixture['leg_id'], ['runtime_channel_id' => $fixture['channel_id']]);
        DB::table('runtime_operations')->where('id', $fixture['operation_id'])->update(['status' => 'succeeded', 'completed_at' => now()->subSeconds(61), 'updated_at' => now()->subSeconds(61)]);
        $result = app(\App\TelephonyDomain\Reconciliation\CallOriginationReconciler::class)->evaluate((object) ['tenant_id' => $fixture['tenant_id'], 'target_id' => $fixture['leg_id']]);
        $this->assertSame('converged', $result->status);
        $this->assertSame('answered', DB::table('call_legs')->where('id', $fixture['leg_id'])->value('observed_state'));
        $this->assertNull(DB::table('call_legs')->where('id', $fixture['leg_id'])->value('termination_reason'));
    }

    public function test_terminal_observation_first_suppresses_origination_timeout(): void
    {
        $fixture = $this->timeoutOutboundFixture(backdate: false);
        $this->emit('terminated-first', $fixture['tenant_id'], $fixture['node_id'], 'call.leg.terminated', $fixture['leg_id'], ['runtime_channel_id' => $fixture['channel_id']]);
        DB::table('runtime_operations')->where('id', $fixture['operation_id'])->update(['status' => 'succeeded', 'completed_at' => now()->subSeconds(61), 'updated_at' => now()->subSeconds(61)]);
        $result = app(\App\TelephonyDomain\Reconciliation\CallOriginationReconciler::class)->evaluate((object) ['tenant_id' => $fixture['tenant_id'], 'target_id' => $fixture['leg_id']]);
        $this->assertSame('converged', $result->status);
        $this->assertSame('completed', DB::table('call_legs')->where('id', $fixture['leg_id'])->value('observed_state'));
        $this->assertSame('remote', DB::table('call_legs')->where('id', $fixture['leg_id'])->value('termination_reason'));
    }

    /** @param array{tenant_id:string,node_id:string,call_id:string,leg_id:string,operation_id:string,channel_id:string} $fixture */
    private function assertLateObservationAfterTimeout(string $type, string $key, string $observedAt): void
    {
        $fixture = $this->timeoutOutboundFixture();
        $before = DB::table('call_legs')->where('id', $fixture['leg_id'])->first();
        $operationBefore = DB::table('runtime_operations')->where('id', $fixture['operation_id'])->first();
        $this->emit($key, $fixture['tenant_id'], $fixture['node_id'], $type, $fixture['leg_id'], ['runtime_channel_id' => $fixture['channel_id'], 'occurred_at' => $observedAt]);
        $after = DB::table('call_legs')->where('id', $fixture['leg_id'])->first();
        $call = DB::table('calls')->where('id', $fixture['call_id'])->first();
        $operationAfter = DB::table('runtime_operations')->where('id', $fixture['operation_id'])->first();
        $this->assertSame('failed', $after->observed_state);
        $this->assertSame('origination_timeout', $after->termination_reason);
        $this->assertSame('system', $after->termination_party);
        $this->assertSame($before->terminated_at, $after->terminated_at);
        $this->assertSame('failed', $call->observed_state);
        $this->assertSame('origination_timeout', $call->termination_reason);
        $this->assertSame((array) $operationBefore, (array) $operationAfter);
        $this->assertDatabaseHas('runtime_observations', ['observation_type' => $type, 'subject_id' => $fixture['leg_id'], 'observed_at' => $observedAt]);
    }

    /** @return array{tenant_id:string,node_id:string,call_id:string,leg_id:string,operation_id:string,channel_id:string} */
    private function timeoutOutboundFixture(bool $backdate = true): array
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), null, $nodeId);
        if ($backdate) {
            DB::table('runtime_operations')->where('id', $result['operation_id'])->update(['status' => 'succeeded', 'completed_at' => now()->subSeconds(61), 'updated_at' => now()->subSeconds(61)]);
            app(\App\TelephonyDomain\Reconciliation\CallOriginationReconciler::class)->evaluate((object) ['tenant_id' => $tenantId, 'target_id' => $result['leg_id']]);
        }
        return [...$result, 'tenant_id' => $tenantId, 'node_id' => $nodeId, 'channel_id' => (string) DB::table('call_legs')->where('id', $result['leg_id'])->value('runtime_channel_id')];
    }

    /** @return array{epoch:string,id:string} */
    private function emit(string $key, string $tenantId, string $nodeId, string $observationType, string $subjectId, array $payload, ?string $epoch = null): array
    {
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch ??= $receipts->openEpoch($tenantId, $nodeId, 'simulator-deterministic', 'c6c-test-'.$key);
        $accepted = $receipts->ingest($tenantId, $nodeId, 'simulator-deterministic', $epoch, $key, 'simulator.call.observation', 1, [
            'observation_type' => $observationType,
            'subject_type' => 'call_leg',
            'subject_id' => $subjectId,
            'runtime_channel_id' => $payload['runtime_channel_id'] ?? null,
            'observation_payload' => $payload,
            'observed_state' => 'observed',
            ...(isset($payload['occurred_at']) ? ['occurred_at' => $payload['occurred_at']] : []),
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();
        $normalizer = new SimulatorEventNormalizer(new SimulatorCatalog, 'simulator.call.observation');
        $payload = json_decode((string) $receipt->sanitized_payload, true, flags: JSON_THROW_ON_ERROR);
        app(ProjectionService::class)->apply($receipt, $normalizer->normalize($receipt, $payload));

        return ['epoch' => $epoch, 'id' => $accepted['id']];
    }

    /** @return array{call_id:string,leg_id:string} */
    private function adopt(string $nodeId, string $channel): array
    {
        return app(CallDomainService::class)->adoptInboundLeg($nodeId, $channel);
    }

    /** @return array{tenant_id:string,node_id:string,trunk_id:string,address_id:string,endpoint_id:string,route_id:?string} */
    private function inboundFixture(?string $tenantId = null, bool $withRoute = true, string $routeState = 'active'): array
    {
        $tenantId ??= Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        $trunkId = Str::uuid()->toString();
        $addressId = Str::uuid()->toString();
        $endpointId = Str::uuid()->toString();
        $routeId = $withRoute ? Str::uuid()->toString() : null;
        $suffix = str_replace('-', '', $tenantId);
        $resourceSuffix = substr(str_replace('-', '', $nodeId), 0, 8);
        if (! DB::table('tenants')->where('id', $tenantId)->exists()) {
            DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'inbound-'.$suffix, 'display_name' => 'Inbound tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'Inbound simulator', 'slug' => 'inbound-node-'.substr(str_replace('-', '', $nodeId), 0, 8), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telephony_addresses')->insert(['id' => $addressId, 'tenant_id' => $tenantId, 'address_type' => 'e164', 'normalized_value' => '+1555'.substr(str_replace('-', '', $addressId), 0, 7), 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('external_trunks')->insert(['id' => $trunkId, 'tenant_id' => $tenantId, 'name' => 'Inbound trunk', 'slug' => 'inbound-trunk-'.$resourceSuffix, 'supported_directions' => json_encode(['inbound']), 'capabilities' => json_encode([]), 'desired_state' => 'active', 'observed_health' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('external_trunk_addresses')->insert(['external_trunk_id' => $trunkId, 'telephony_address_id' => $addressId, 'direction' => 'inbound', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trunk_endpoints')->insert(['id' => $endpointId, 'tenant_id' => $tenantId, 'external_trunk_id' => $trunkId, 'endpoint_uri' => 'sip:inbound-'.$resourceSuffix.'.example.test', 'transport' => 'udp', 'authentication_mode' => 'none', 'capabilities' => json_encode([]), 'desired_state' => 'active', 'priority' => 100, 'signaling_mode' => 'static', 'created_at' => now(), 'updated_at' => now()]);
        if ($routeId !== null) {
            DB::table('inbound_routes')->insert(['id' => $routeId, 'tenant_id' => $tenantId, 'name' => 'Inbound route', 'slug' => 'route-'.$resourceSuffix, 'external_trunk_id' => $trunkId, 'telephony_address_id' => $addressId, 'destination_ref' => 'opaque:application-entry', 'priority' => 10, 'desired_state' => $routeState, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [
            'tenant_id' => $tenantId,
            'node_id' => $nodeId,
            'trunk_id' => $trunkId,
            'address_id' => $addressId,
            'endpoint_id' => $endpointId,
            'route_id' => $routeId,
        ];
    }

    private function lastInboundFailureReason(): string
    {
        $payload = DB::table('control_plane_audit_records')->where('action', 'call.inbound_route_failed')->latest('created_at')->value('metadata');

        return json_decode((string) $payload, true)['data']['reason'];
    }

    /** @return array{0:string,1:string} */
    private function runtimeNode(): array
    {
        $tenantId = Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'c6c-'.str_replace('-', '', $tenantId), 'display_name' => 'C6C tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'C6C simulator', 'slug' => 'c6c-'.str_replace('-', '', $nodeId), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_node_capabilities')->insert(['id' => Str::uuid()->toString(), 'runtime_node_id' => $nodeId, 'capability_key' => 'call.control', 'created_at' => now(), 'updated_at' => now()]);

        return [$tenantId, $nodeId];
    }
}
