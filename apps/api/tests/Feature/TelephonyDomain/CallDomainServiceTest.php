<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\TelephonyDomain\CallDomainService;
use App\TelephonyDomain\CallOperationCatalog;
use App\TelephonyDomain\CallState;
use App\TelephonyDomain\Reconciliation\CallOriginationReconciler;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class CallDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_c6_adds_only_calls_and_call_legs_and_exposes_the_runtime_channel_fence(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('calls'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_legs'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('call_operations'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('call_observations'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('call_timeline_entries'));
        $indexes = DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'call_legs_runtime_channel_unique'");
        $this->assertSame('call_legs_runtime_channel_unique', $indexes[0]->name ?? null);
    }

    public function test_outbound_call_and_operation_use_the_existing_runtime_operation_authority(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), IdempotencyKey::fromString('call-create-001'));

        $this->assertSame('originating', DB::table('calls')->where('id', $result['call_id'])->value('observed_state'));
        $this->assertSame('originating', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
        $this->assertSame('active', DB::table('call_legs')->where('id', $result['leg_id'])->value('desired_state'));
        $this->assertSame($tenantId, DB::table('call_legs')->where('id', $result['leg_id'])->value('tenant_id'));
        $operation = DB::table('runtime_operations')->where('id', $result['operation_id'])->first();
        $this->assertSame('call.leg.originate', $operation->operation_type);
        $this->assertSame('call_leg', $operation->aggregate_type);
        $this->assertSame($result['leg_id'], $operation->aggregate_id);
        $this->assertSame($tenantId, $operation->tenant_id);
        $this->assertSame($result['call_id'], json_decode($operation->payload, true)['call_id']);
        $this->assertSame(2, DB::table('control_plane_audit_records')->where('subject_id', $result['call_id'])->count());
        $this->assertSame(2, DB::table('control_plane_outbox_messages')->where('aggregate_id', $result['call_id'])->count());
        $this->assertSame(CallDomainService::runtimeChannelId($result['leg_id']), DB::table('call_legs')->where('id', $result['leg_id'])->value('runtime_channel_id'));
        $this->assertSame(CallDomainService::runtimeChannelId($result['leg_id']), json_decode($operation->payload, true)['runtime_channel_id']);
        $this->assertDatabaseHas('runtime_reconciliation_states', ['target_type' => 'call_leg_origination', 'target_id' => $result['leg_id']]);
    }

    public function test_c7b_selected_caller_identity_address_is_carried_into_originate_operation(): void
    {
        $tenantId = $this->tenant();
        $trunkId = Str::uuid()->toString();
        $endpointId = Str::uuid()->toString();
        $destinationId = Str::uuid()->toString();
        $callerAddressId = Str::uuid()->toString();
        $callerIdentityId = Str::uuid()->toString();
        $routeId = Str::uuid()->toString();
        $now = now();

        DB::table('external_trunks')->insert([
            'id' => $trunkId, 'tenant_id' => $tenantId, 'name' => 'C7B Carrier', 'slug' => 'c7b-carrier',
            'supported_directions' => json_encode(['outbound']), 'capabilities' => json_encode([]),
            'desired_state' => 'active', 'observed_health' => 'ready', 'configuration_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('trunk_endpoints')->insert([
            'id' => $endpointId, 'tenant_id' => $tenantId, 'external_trunk_id' => $trunkId,
            'endpoint_uri' => 'sip:provider.example:5060', 'transport' => 'udp', 'authentication_mode' => 'none',
            'signaling_mode' => 'static', 'desired_state' => 'active', 'priority' => 100,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('telephony_addresses')->insert([
            'id' => $destinationId, 'tenant_id' => $tenantId, 'address_type' => 'sip_uri',
            'normalized_value' => 'sip:97001@38.146.161.46', 'desired_state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('external_trunk_addresses')->insert([
            'external_trunk_id' => $trunkId, 'telephony_address_id' => $destinationId,
            'direction' => 'outbound', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('telephony_addresses')->insert([
            'id' => $callerAddressId, 'tenant_id' => $tenantId, 'address_type' => 'sip_uri',
            'normalized_value' => 'sip:utcp-v1@38.146.161.46', 'desired_state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('caller_identities')->insert([
            'id' => $callerIdentityId, 'tenant_id' => $tenantId, 'name' => 'V1 Caller',
            'telephony_address_id' => $callerAddressId, 'desired_state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('caller_identity_policies')->insert([
            'id' => Str::uuid()->toString(), 'tenant_id' => $tenantId, 'caller_identity_id' => $callerIdentityId,
            'external_trunk_id' => $trunkId, 'desired_state' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('outbound_routes')->insert([
            'id' => $routeId, 'tenant_id' => $tenantId, 'name' => 'C7B Route', 'slug' => 'c7b-route',
            'external_trunk_id' => $trunkId, 'telephony_address_id' => $destinationId,
            'caller_identity_id' => $callerIdentityId, 'priority' => 100, 'desired_state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $result = app(CallDomainService::class)->createOutboundCall(
            $tenantId,
            ExecutionContext::system(tenantId: $tenantId),
            null,
            null,
            'telephony_address:'.$destinationId,
        );
        $operation = DB::table('runtime_operations')->where('id', $result['operation_id'])->first();
        $payload = json_decode($operation->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($callerIdentityId, $payload['caller_identity_id']);
        $this->assertSame('sip:utcp-v1@38.146.161.46', $payload['caller_identity_address']);
        $this->assertSame('sip:97001@38.146.161.46', $payload['destination_uri']);
    }

    public function test_additional_outbound_leg_inherits_call_runtime_and_is_idempotent(): void
    {
        $tenantId = $this->tenant();
        $service = app(CallDomainService::class);
        $runtimeId = Str::uuid()->toString();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeId,
            'tenant_id' => $tenantId,
            'name' => 'runtime-default',
            'slug' => 'runtime-default-'.str_replace('-', '', $runtimeId),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $call = $service->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        DB::table('calls')->where('id', $call['call_id'])->update(['runtime_node_id' => $runtimeId]);

        $key = IdempotencyKey::fromString('additional-leg-001');
        $first = $service->createOutboundLeg($tenantId, ExecutionContext::system(tenantId: $tenantId), $call['call_id'], $key, null, 'opaque:second');
        $second = $service->createOutboundLeg($tenantId, ExecutionContext::system(tenantId: $tenantId), $call['call_id'], $key, null, 'opaque:second');

        $this->assertSame($first, $second);
        $this->assertSame($runtimeId, DB::table('call_legs')->where('id', $first['leg_id'])->value('runtime_node_id'));
        $this->assertSame(CallDomainService::runtimeChannelId($first['leg_id']), DB::table('call_legs')->where('id', $first['leg_id'])->value('runtime_channel_id'));
        $this->assertSame(2, DB::table('call_legs')->where('call_id', $call['call_id'])->count());
        $this->assertSame(2, DB::table('runtime_operations')->where('operation_type', 'call.leg.originate')->where('tenant_id', $tenantId)->count());
        $operation = DB::table('runtime_operations')->where('id', $first['operation_id'])->first();
        $payload = json_decode($operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('call.leg.originate', $operation->operation_type);
        $this->assertSame('call_leg', $operation->aggregate_type);
        $this->assertSame($first['leg_id'], $operation->aggregate_id);
        $this->assertSame($runtimeId, $operation->runtime_node_id);
        $this->assertSame($call['call_id'], $payload['call_id']);
        $this->assertSame($first['leg_id'], $payload['leg_id']);
        $this->assertSame($runtimeId, $payload['runtime_node_id']);
        $this->assertSame('opaque:second', $payload['destination_ref']);
        $this->assertSame(CallDomainService::runtimeChannelId($first['leg_id']), $payload['runtime_channel_id']);
    }

    public function test_additional_outbound_leg_can_use_an_explicit_other_runtime_without_handoff(): void
    {
        $tenantId = $this->tenant();
        $runtimeA = Str::uuid()->toString();
        $runtimeB = Str::uuid()->toString();
        foreach ([$runtimeA, $runtimeB] as $runtime) {
            DB::table('runtime_nodes')->insert([
                'id' => $runtime,
                'tenant_id' => $tenantId,
                'name' => 'runtime-'.$runtime,
                'slug' => 'runtime-'.str_replace('-', '', $runtime),
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'desired_state' => 'active',
                'observed_state' => 'ready',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $service = app(CallDomainService::class);
        $call = $service->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), null, $runtimeA, 'opaque:first');
        $leg = $service->createOutboundLeg($tenantId, ExecutionContext::system(tenantId: $tenantId), $call['call_id'], null, $runtimeB, 'opaque:second');

        $this->assertSame($runtimeA, DB::table('call_legs')->where('id', $call['leg_id'])->value('runtime_node_id'));
        $this->assertSame($runtimeB, DB::table('call_legs')->where('id', $leg['leg_id'])->value('runtime_node_id'));
        $this->assertSame($call['call_id'], DB::table('call_legs')->where('id', $leg['leg_id'])->value('call_id'));
    }

    public function test_additional_leg_rejects_terminal_call(): void
    {
        $tenantId = $this->tenant();
        $service = app(CallDomainService::class);
        $call = $service->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        DB::table('calls')->where('id', $call['call_id'])->update(['observed_state' => CallState::Completed->value]);

        $this->expectException(InvalidArgumentException::class);
        $service->createOutboundLeg($tenantId, ExecutionContext::system(tenantId: $tenantId), $call['call_id'], null, null, 'opaque:closed');
    }

    public function test_accepted_origination_without_observation_times_out_once_and_observation_progression_wins(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $operationId = $result['operation_id'];
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'status' => 'succeeded',
            'completed_at' => now()->subSeconds(61),
            'updated_at' => now()->subSeconds(61),
        ]);

        $target = (object) ['tenant_id' => $tenantId, 'target_id' => $result['leg_id']];
        $reconciler = app(CallOriginationReconciler::class);
        $this->assertSame('converged', $reconciler->evaluate($target)->status);
        $this->assertSame('failed', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
        $this->assertSame('failed', DB::table('calls')->where('id', $result['call_id'])->value('observed_state'));
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->where('subject_id', $result['leg_id'])->count());
        $this->assertSame('converged', $reconciler->evaluate($target)->status);
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->where('subject_id', $result['leg_id'])->count());

        $second = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        DB::table('runtime_operations')->where('id', $second['operation_id'])->update([
            'status' => 'succeeded',
            'completed_at' => now()->subSeconds(61),
            'updated_at' => now()->subSeconds(61),
        ]);
        app(CallDomainService::class)->applyLegTransition($tenantId, $second['leg_id'], CallState::Originating, 'canonical-reconciliation');
        app(CallDomainService::class)->applyCallTransition($tenantId, $second['call_id'], CallState::Originating, 'canonical-reconciliation');
        app(CallDomainService::class)->applyLegTransition($tenantId, $second['leg_id'], CallState::Ringing, 'observation-confirmed');
        app(CallDomainService::class)->applyCallTransition($tenantId, $second['call_id'], CallState::Ringing, 'observation-confirmed');
        $this->assertSame('converged', $reconciler->evaluate((object) ['tenant_id' => $tenantId, 'target_id' => $second['leg_id']])->status);
        $this->assertSame('ringing', DB::table('call_legs')->where('id', $second['leg_id'])->value('observed_state'));
    }

    public function test_runtime_channel_fence_allows_nulls_rejects_same_node_duplicates_and_allows_other_nodes(): void
    {
        $tenantId = $this->tenant();
        $callId = Str::uuid()->toString();
        $now = now();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'inbound', 'desired_state' => 'active', 'observed_state' => 'offered', 'created_at' => $now, 'updated_at' => $now]);
        $insert = fn (string $id, ?string $node, ?string $channel) => DB::table('call_legs')->insert(['id' => $id, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $node, 'runtime_channel_id' => $channel, 'direction' => 'inbound', 'role' => 'destination', 'observed_state' => 'offered', 'created_at' => $now, 'updated_at' => $now]);
        $insert(Str::uuid()->toString(), null, null);
        $insert(Str::uuid()->toString(), null, null);
        $nodeA = Str::uuid()->toString();
        $nodeB = Str::uuid()->toString();
        foreach ([$nodeA, $nodeB] as $node) {
            DB::table('runtime_nodes')->insert(['id' => $node, 'tenant_id' => $tenantId, 'name' => 'node-'.$node, 'slug' => 'node-'.str_replace('-', '', $node), 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'ready', 'created_at' => $now, 'updated_at' => $now]);
        }
        $insert(Str::uuid()->toString(), $nodeA, 'channel-1');
        $insert(Str::uuid()->toString(), $nodeB, 'channel-1');

        $this->expectException(QueryException::class);
        $insert(Str::uuid()->toString(), $nodeA, 'channel-1');
    }

    public function test_transitions_are_provider_neutral_and_terminal_metadata_is_write_once(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $service = app(CallDomainService::class);
        $service->applyCallTransition($tenantId, $result['call_id'], CallState::Originating, 'canonical-reconciliation');
        $service->applyCallTransition($tenantId, $result['call_id'], CallState::Ringing, 'observation-confirmed');
        $service->applyCallTransition($tenantId, $result['call_id'], CallState::Answered, 'observation-confirmed');
        $this->assertTrue($service->terminalize($tenantId, 'call', $result['call_id'], CallState::Completed, 'requested', 'local'));
        $this->assertFalse($service->terminalize($tenantId, 'call', $result['call_id'], CallState::Completed, 'requested', 'local'));

        $this->expectException(LogicException::class);
        $service->terminalize($tenantId, 'call', $result['call_id'], CallState::Failed, 'busy', 'remote');
    }

    public function test_answered_leg_advances_call_with_first_canonical_answer_timestamp_and_replay_is_idempotent(): void
    {
        $tenantId = $this->tenant();
        $nodeId = Str::uuid()->toString();
        $now = Carbon::parse('2026-08-29 12:00:00 UTC');
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'C6 answer node',
            'slug' => 'c6-answer-'.str_replace('-', '', $nodeId), 'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active',
            'observed_state' => 'ready', 'configuration_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Carbon::setTestNow($now);

        try {
            $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId), null, $nodeId);
            $service = app(CallDomainService::class);
            $channelId = (string) DB::table('call_legs')->where('id', $result['leg_id'])->value('runtime_channel_id');

            $this->assertTrue($service->applyObservedLegTransition($tenantId, $result['leg_id'], $nodeId, $channelId, CallState::Answered));
            $this->assertSame('answered', DB::table('calls')->where('id', $result['call_id'])->value('observed_state'));
            $this->assertSame($now->toISOString(), Carbon::parse((string) DB::table('calls')->where('id', $result['call_id'])->value('answered_at'))->toISOString());
            $this->assertSame($now->toISOString(), Carbon::parse((string) DB::table('call_legs')->where('id', $result['leg_id'])->value('answered_at'))->toISOString());

            Carbon::setTestNow($now->copy()->addMinute());
            $firstAnswerAt = DB::table('calls')->where('id', $result['call_id'])->value('answered_at');
            $this->assertFalse($service->applyObservedLegTransition($tenantId, $result['leg_id'], $nodeId, $channelId, CallState::Answered));
            $this->assertSame($firstAnswerAt, DB::table('calls')->where('id', $result['call_id'])->value('answered_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_answered_call_completion_preserves_answered_at_and_pre_answer_failure_does_not_stamp_it(): void
    {
        $tenantId = $this->tenant();
        $service = app(CallDomainService::class);
        $answered = $service->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $service->applyCallTransition($tenantId, $answered['call_id'], CallState::Ringing, 'observation-confirmed');
        $service->applyCallTransition($tenantId, $answered['call_id'], CallState::Answered, 'observation-confirmed');
        $answeredAt = DB::table('calls')->where('id', $answered['call_id'])->value('answered_at');

        $this->assertNotNull($answeredAt);
        $this->assertTrue($service->terminalize($tenantId, 'call', $answered['call_id'], CallState::Completed, 'requested', 'local'));
        $this->assertSame($answeredAt, DB::table('calls')->where('id', $answered['call_id'])->value('answered_at'));

        $failed = $service->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $this->assertNull(DB::table('calls')->where('id', $failed['call_id'])->value('answered_at'));
        $this->assertTrue($service->terminalize($tenantId, 'call', $failed['call_id'], CallState::Failed, 'no_answer'));
        $this->assertNull(DB::table('calls')->where('id', $failed['call_id'])->value('answered_at'));
    }

    public function test_successful_local_hold_and_resume_are_command_confirmed_without_observations(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $service = app(CallDomainService::class);
        $service->applyCallTransition($tenantId, $result['call_id'], CallState::Ringing, 'observation-confirmed');
        $service->applyLegTransition($tenantId, $result['leg_id'], CallState::Ringing, 'observation-confirmed');
        $service->applyCallTransition($tenantId, $result['call_id'], CallState::Answered, 'observation-confirmed');
        $service->applyLegTransition($tenantId, $result['leg_id'], CallState::Answered, 'observation-confirmed');

        $this->assertTrue($service->applySuccessfulCallOperation($tenantId, 'call.leg.hold', 'call_leg', $result['leg_id']));
        $this->assertSame('held', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
        $this->assertTrue((bool) DB::table('call_legs')->where('id', $result['leg_id'])->value('held'));
        $this->assertSame(0, DB::table('runtime_observations')->count());
        $audit = DB::table('control_plane_audit_records')
            ->where('action', 'call_leg.state_changed')
            ->where('subject_id', $result['leg_id'])
            ->get()
            ->first(static function (object $row): bool {
                $metadata = json_decode((string) $row->metadata, true);

                return ($metadata['data']['source'] ?? null) === 'command-confirmed'
                    && ($metadata['data']['from'] ?? null) === 'answered'
                    && ($metadata['data']['to'] ?? null) === 'held';
            });
        $this->assertNotNull($audit);

        $this->assertTrue($service->applySuccessfulCallOperation($tenantId, 'call.leg.resume', 'call_leg', $result['leg_id']));
        $this->assertSame('answered', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
        $this->assertFalse((bool) DB::table('call_legs')->where('id', $result['leg_id'])->value('held'));
    }

    public function test_local_command_confirmation_is_idempotent_and_cannot_resurrect_terminal_legs(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));
        $service = app(CallDomainService::class);
        $service->applyLegTransition($tenantId, $result['leg_id'], CallState::Answered, 'observation-confirmed');

        $this->assertTrue($service->applySuccessfulCallOperation($tenantId, 'call.leg.hold', 'call_leg', $result['leg_id']));
        $this->assertFalse($service->applySuccessfulCallOperation($tenantId, 'call.leg.hold', 'call_leg', $result['leg_id']));
        $this->assertFalse($service->applyObservedLegTransition($tenantId, $result['leg_id'], '', '', CallState::Held));

        $service->terminalize($tenantId, 'call_leg', $result['leg_id'], CallState::Completed, 'requested');
        $this->assertFalse($service->applySuccessfulCallOperation($tenantId, 'call.leg.resume', 'call_leg', $result['leg_id']));
        $this->assertSame('completed', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
    }

    public function test_leg_terminalization_persists_its_terminal_desired_state(): void
    {
        $tenantId = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantId, ExecutionContext::system(tenantId: $tenantId));

        app(CallDomainService::class)->terminalize($tenantId, 'call_leg', $result['leg_id'], CallState::Failed, 'no_answer');

        $this->assertSame('terminated', DB::table('call_legs')->where('id', $result['leg_id'])->value('desired_state'));
        $this->assertSame('failed', DB::table('call_legs')->where('id', $result['leg_id'])->value('observed_state'));
    }

    public function test_cross_tenant_leg_operation_is_rejected(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();
        $result = app(CallDomainService::class)->createOutboundCall($tenantA, ExecutionContext::system(tenantId: $tenantA));

        $this->expectException(InvalidArgumentException::class);
        app(CallDomainService::class)->requestOperation($tenantB, ExecutionContext::system(tenantId: $tenantB), 'call.leg.answer', 'call_leg', $result['leg_id'], ['call_id' => $result['call_id']]);
    }

    public function test_operation_catalog_has_the_normalized_targets_and_capabilities(): void
    {
        $catalog = CallOperationCatalog::all();
        $this->assertCount(19, $catalog);
        $this->assertSame(['target' => 'call_leg', 'capability' => 'media.playback'], $catalog['call.leg.play_media']);
        $this->assertSame(['target' => 'relationship', 'capability' => 'call.control'], $catalog['call.legs.bridge'] ?? ['target' => '', 'capability' => '']);
    }

    private function tenant(): string
    {
        $id = Str::uuid()->toString();
        DB::table('tenants')->insert(['id' => $id, 'slug' => 'tenant-'.str_replace('-', '', $id), 'display_name' => 'C6 tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
