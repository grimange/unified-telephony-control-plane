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
        $this->assertDatabaseHas('runtime_observations', ['observation_type' => 'call.leg.offered', 'subject_id' => 'runtime:'.$nodeId.':'.$channel]);

        $this->emit('offered-2', $tenantId, $nodeId, 'call.leg.offered', 'runtime:'.$nodeId.':'.$channel, ['runtime_channel_id' => $channel]);

        $this->assertSame(1, DB::table('call_legs')->where('runtime_node_id', $nodeId)->where('runtime_channel_id', $channel)->count());
        $this->assertSame(1, DB::table('calls')->where('direction', 'inbound')->count());
        $this->assertSame(2, DB::table('runtime_observations')->where('observation_type', 'call.leg.offered')->count());
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

        $this->emit('hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'requested']);
        $this->emit('duplicate-hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'requested']);
        $this->emit('conflicting-hangup', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => $channel, 'termination_reason' => 'busy']);

        $this->assertSame('completed', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame('requested', DB::table('call_legs')->where('id', $legId)->value('termination_reason'));
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'call_leg.terminated')->count());

        $this->emit('late-old-channel', $tenantId, $nodeId, 'call.leg.terminated', $legId, ['runtime_channel_id' => 'old-channel', 'termination_reason' => 'busy']);
        $this->assertSame('requested', DB::table('call_legs')->where('id', $legId)->value('termination_reason'));
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

    /** @return array{0:string,1:string} */
    private function runtimeNode(): array
    {
        $tenantId = Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'c6c-'.str_replace('-', '', $tenantId), 'display_name' => 'C6C tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'C6C simulator', 'slug' => 'c6c-'.str_replace('-', '', $nodeId), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenantId, $nodeId];
    }
}
