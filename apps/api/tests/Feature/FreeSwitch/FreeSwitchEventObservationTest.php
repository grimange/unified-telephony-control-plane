<?php

namespace Tests\Feature\FreeSwitch;

use App\RuntimeAdapters\FreeSwitch\FreeSwitchCatalog;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslEventListener;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslEventTransport;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslException;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslProtocol;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEventNormalizer;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FreeSwitchEventObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_esl_frame_parser_preserves_headers_and_content_length_body(): void
    {
        $frame = "Content-Type: text/event-plain\r\nContent-Length: 11\r\n\r\nhello world";
        $parsed = FreeSwitchEslProtocol::parseFrame($frame);

        $this->assertSame('text/event-plain', $parsed['headers']['Content-Type']);
        $this->assertSame('11', $parsed['headers']['Content-Length']);
        $this->assertSame('hello world', $parsed['body']);
        FreeSwitchEslProtocol::assertAuthenticated("Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n");
    }

    public function test_esl_authentication_failure_is_explicit(): void
    {
        $this->expectExceptionCode(0);
        $this->expectException(FreeSwitchEslException::class);
        FreeSwitchEslProtocol::assertAuthenticated('-ERR invalid');
    }

    public function test_all_c6_event_headers_normalize_to_the_existing_vocabulary(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $receipt = (object) ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'connection_epoch_id' => 'epoch-1'];
        $channel = 'provider-channel-1';
        $cases = [
            ['CHANNEL_CREATE', ['Caller-Direction' => 'inbound', 'Caller-Caller-ID-Number' => '+15550101', 'Caller-Destination-Number' => '1000'], 'call.leg.offered'],
            ['CHANNEL_ANSWER', [], 'call.leg.answered'],
            ['CHANNEL_HOLD', [], 'call.leg.held'],
            ['CHANNEL_UNHOLD', [], 'call.leg.resumed'],
            ['CHANNEL_HANGUP_COMPLETE', ['Hangup-Cause' => 'NORMAL_CLEARING'], 'call.leg.terminated'],
            ['DTMF', ['DTMF-Digit' => '5'], 'call.leg.dtmf_received'],
            ['PLAYBACK_START', ['Playback-File-Path' => '/usr/share/freeswitch/sounds/reference-tone.wav'], 'call.leg.media_started'],
            ['PLAYBACK_STOP', ['Playback-File-Name' => 'welcome.wav'], 'call.leg.media_stopped'],
        ];
        foreach ($cases as [$event, $extra, $expected]) {
            $observations = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, $event))->normalize($receipt, ['Unique-ID' => $channel, ...$extra]);
            $this->assertCount(1, $observations, $event);
            $this->assertSame($expected, $observations[0]['observation_type'], $event);
            $this->assertSame($channel, $observations[0]['payload']['runtime_channel_id'], $event);
            if (in_array($event, ['PLAYBACK_START', 'PLAYBACK_STOP'], true)) {
                $this->assertSame($event === 'PLAYBACK_START' ? 'utcp:media/reference-tone' : 'utcp:media/welcome', $observations[0]['payload']['media_ref']);
            }
        }

        $bridge = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_BRIDGE'))->normalize($receipt, ['Unique-ID' => 'a', 'Other-Leg-Unique-ID' => 'b']);
        $this->assertSame('call.legs.bridged', $bridge[0]['observation_type']);
        $this->assertSame(['a', 'b'], $bridge[0]['payload']['runtime_channel_ids']);
        $unbridge = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_UNBRIDGE'))->normalize($receipt, ['Unique-ID' => 'a', 'Other-Leg-Unique-ID' => 'b']);
        $this->assertSame('call.legs.unbridged', $unbridge[0]['observation_type']);
    }

    public function test_inbound_sip_headers_normalize_to_provider_neutral_correlation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $receipt = (object) ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'connection_epoch_id' => 'epoch-inbound'];
        $addressId = '11111111-1111-4111-8111-111111111111';
        $trunkId = '22222222-2222-4222-8222-222222222222';
        $endpointId = '33333333-3333-4333-8333-333333333333';
        $runtimeNodeId = '44444444-4444-4444-8444-444444444444';
        $observations = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_CREATE'))->normalize($receipt, [
            'Unique-ID' => 'freeswitch-inbound-1',
            'Caller-Direction' => 'inbound',
            'variable_utcp_called_address' => 'utcp-in-'.$addressId,
            'variable_sip_h_X-UTCP-Ingress-External-Trunk-ID' => strtoupper($trunkId),
            'variable_sip_h_X-UTCP-Ingress-Telephony-Address-ID' => $addressId,
            'variable_sip_h_X-UTCP-Ingress-Trunk-Endpoint-ID' => $endpointId,
            'variable_sip_h_X-UTCP-Ingress-Runtime-Node-ID' => $runtimeNodeId,
            'variable_sip_h_X-Provider-Secret' => 'must-not-leak',
        ]);

        $payload = $observations[0]['payload'];
        $this->assertSame('utcp-in-'.$addressId, $payload['called_address']);
        $this->assertSame($trunkId, $payload['ingress_external_trunk_id']);
        $this->assertSame($addressId, $payload['ingress_telephony_address_id']);
        $this->assertSame($endpointId, $payload['ingress_trunk_endpoint_id']);
        $this->assertSame($runtimeNodeId, $payload['ingress_runtime_node_id']);
        $this->assertArrayNotHasKey('variable_sip_h_X-Provider-Secret', $payload);

        $malformed = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_CREATE'))->normalize($receipt, [
            'Unique-ID' => 'freeswitch-inbound-2',
            'Caller-Direction' => 'inbound',
            'variable_utcp_called_address' => 'utcp-in-'.$addressId,
            'variable_sip_h_X-UTCP-Ingress-External-Trunk-ID' => 'not-a-uuid',
        ]);
        $this->assertArrayNotHasKey('ingress_external_trunk_id', $malformed[0]['payload']);
    }

    public function test_outbound_create_is_not_inbound_offered_and_reserved_answer_correlates(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'ringing', 'created_at' => now(), 'updated_at' => now()]);
        $reserved = 'utcp-call-leg-'.$legId;
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => $reserved, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'ringing', 'created_at' => now(), 'updated_at' => now()]);
        $receipt = $this->receipt($tenantId, $nodeId, 'answer-outbound');

        $outbound = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_CREATE'))->normalize($receipt, ['Unique-ID' => $reserved, 'Caller-Direction' => 'outbound']);
        $this->assertSame([], $outbound);
        $answered = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_ANSWER'))->normalize($receipt, ['Unique-ID' => $reserved]);
        (new ProjectionService)->apply($receipt, $answered);

        $this->assertSame(1, DB::table('calls')->where('tenant_id', $tenantId)->count());
        $this->assertSame(1, DB::table('call_legs')->where('tenant_id', $tenantId)->count());
        $this->assertSame('answered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
    }

    public function test_event_from_another_runtime_node_cannot_resolve_a_call_leg(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $otherNodeId = Str::uuid()->toString();
        DB::table('runtime_nodes')->insert([
            'id' => $otherNodeId,
            'tenant_id' => $tenantId,
            'name' => 'FreeSWITCH secondary',
            'slug' => 'fs-'.substr($otherNodeId, 0, 8),
            'runtime_family' => 'freeswitch',
            'adapter_key' => 'freeswitch-esl',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'ringing', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $otherNodeId, 'runtime_channel_id' => 'shared-channel', 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'ringing', 'created_at' => now(), 'updated_at' => now()]);

        $receipt = $this->receipt($tenantId, $nodeId, 'wrong-node');
        $observations = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_ANSWER'))->normalize($receipt, ['Unique-ID' => 'shared-channel']);
        (new ProjectionService)->apply($receipt, $observations);

        $this->assertSame('ringing', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
    }

    public function test_inbound_create_is_adopted_and_answer_catches_up_from_the_same_observation_store(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $channel = 'inbound-provider-1';
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', 'freeswitch-test');
        $answerReceiptId = $receipts->ingest($tenantId, $nodeId, 'freeswitch-esl', $epoch, 'answer:1', 'CHANNEL_ANSWER', 1, ['Unique-ID' => $channel])['id'];
        $answerReceipt = DB::table('runtime_event_receipts')->where('id', $answerReceiptId)->first();
        (new ProjectionService)->apply($answerReceipt, (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_ANSWER'))->normalize($answerReceipt, ['Unique-ID' => $channel]));

        $createReceiptId = $receipts->ingest($tenantId, $nodeId, 'freeswitch-esl', $epoch, 'create:1', 'CHANNEL_CREATE', 1, ['Unique-ID' => $channel])['id'];
        $createReceipt = DB::table('runtime_event_receipts')->where('id', $createReceiptId)->first();
        (new ProjectionService)->apply($createReceipt, (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_CREATE'))->normalize($createReceipt, ['Unique-ID' => $channel, 'Caller-Direction' => 'inbound', 'Caller-Caller-ID-Number' => '+15550102']));

        $leg = DB::table('call_legs')->where('runtime_channel_id', $channel)->first();
        $this->assertNotNull($leg);
        $this->assertSame(1, DB::table('calls')->where('tenant_id', $tenantId)->count());
        $this->assertSame('inbound', DB::table('calls')->where('id', $leg->call_id)->value('direction'));
        $this->assertSame('answered', $leg->observed_state);
        $this->assertSame(1, DB::table('runtime_observations')->where('observation_type', 'call.leg.answered')->count());
    }

    public function test_provider_hold_and_resume_facts_are_retained_and_idempotent_after_command_confirmed_states(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        $channel = 'hold-resume-channel';
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => $channel, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'held', 'held' => true, 'created_at' => now(), 'updated_at' => now()]);
        $receipt = $this->receipt($tenantId, $nodeId, 'hold-resume');
        $projection = new ProjectionService;
        $projection->apply($receipt, (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_HOLD'))->normalize($receipt, ['Unique-ID' => $channel]));
        $this->assertSame('held', DB::table('call_legs')->where('id', $legId)->value('observed_state'));

        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered', 'held' => false]);
        $projection->apply($receipt, (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_UNHOLD'))->normalize($receipt, ['Unique-ID' => $channel]));
        $this->assertSame('answered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame(2, DB::table('runtime_observations')->whereIn('observation_type', ['call.leg.held', 'call.leg.resumed'])->count());
    }

    public function test_bridge_pair_and_hangup_facts_project_without_heuristic_peer_lookup(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $first = Str::uuid()->toString();
        $second = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$first, 'a'], [$second, 'b']] as [$legId, $channel]) {
            DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => $channel, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => now(), 'updated_at' => now()]);
        }
        $receipt = $this->receipt($tenantId, $nodeId, 'bridge');
        $projection = new ProjectionService;
        $bridge = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_BRIDGE'))->normalize($receipt, ['Unique-ID' => 'a', 'Other-Leg-Unique-ID' => 'b']);
        $this->assertSame([$first, $second], $bridge[0]['payload']['leg_ids']);
        $projection->apply($receipt, $bridge);
        $this->assertSame($second, DB::table('call_legs')->where('id', $first)->value('bridged_to_leg_id'));
        $hangup = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'CHANNEL_HANGUP_COMPLETE'))->normalize($receipt, ['Unique-ID' => 'a', 'Hangup-Cause' => 'NORMAL_CLEARING']);
        $projection->apply($receipt, $hangup);
        $this->assertSame('NORMAL_CLEARING', DB::table('call_legs')->where('id', $first)->value('termination_reason'));
        $this->assertSame('NORMAL_CLEARING', json_decode((string) DB::table('runtime_observations')->where('observation_type', 'call.leg.terminated')->value('payload'), true)['termination_reason']);
    }

    public function test_listener_assigns_context_and_epoch_and_rejects_missing_event_name(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $listener = new FreeSwitchEslEventListener(new FreeSwitchCatalog, new RuntimeEventReceiptRepository, new RuntimeListenerLeaseRepository);
        $this->assertSame('event plain CHANNEL_CREATE CHANNEL_ANSWER CHANNEL_HOLD CHANNEL_UNHOLD CHANNEL_BRIDGE CHANNEL_UNBRIDGE CHANNEL_HANGUP_COMPLETE DTMF PLAYBACK_START PLAYBACK_STOP', $listener->subscriptionCommand());
        $connection = $listener->openConnection($tenantId, $nodeId, 'freeswitch-listener');
        $accepted = $listener->ingestEvent($tenantId, $nodeId, $connection['epoch_id'], ['Event-Name' => 'CHANNEL_ANSWER', 'Event-Sequence' => '9', 'Unique-ID' => 'u-9']);
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();

        $this->assertSame($tenantId, $receipt->tenant_id);
        $this->assertSame($nodeId, $receipt->runtime_node_id);
        $this->assertSame($connection['epoch_id'], $receipt->connection_epoch_id);
        $this->assertSame('sequence:9', $receipt->external_event_key);
        $this->expectException(\InvalidArgumentException::class);
        $listener->ingestEvent($tenantId, $nodeId, $connection['epoch_id'], ['Unique-ID' => 'missing-type']);
    }

    public function test_initial_connection_still_ingests_readiness(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $stream = $this->eventStream();
        $transport = new class($stream) implements FreeSwitchEslEventTransport
        {
            public int $openCount = 0;

            public function __construct(private readonly mixed $stream) {}

            public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed
            {
                unset($tenantId, $runtimeNodeId, $subscription);
                $this->openCount++;

                return $this->stream;
            }

            public function closeEventStream($stream): void
            {
                unset($stream);
            }
        };
        $listener = new FreeSwitchEslEventListener(new FreeSwitchCatalog, new RuntimeEventReceiptRepository, new RuntimeListenerLeaseRepository, $transport);

        $this->assertSame(1, $listener->workOnce('listener-initial-readiness'));
        $this->assertSame(1, $transport->openCount);
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('event_type', 'runtime.readiness.observed')->count());
    }

    public function test_existing_connection_reconfirms_new_configuration_generation_without_reconnect(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(configurationVersion: 6);
        $stream = $this->eventStream(frames: 2);
        $transport = new class($stream) implements FreeSwitchEslEventTransport
        {
            public int $openCount = 0;

            public function __construct(private readonly mixed $stream) {}

            public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed
            {
                unset($tenantId, $runtimeNodeId, $subscription);
                $this->openCount++;

                return $this->stream;
            }

            public function closeEventStream($stream): void
            {
                unset($stream);
            }
        };
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $lease = $leases->claim($tenantId, $nodeId, 'freeswitch-esl-events', 'listener-generation', 45);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', 'listener-generation');
        $listener = new FreeSwitchEslEventListener(new FreeSwitchCatalog, $receipts, $leases, $transport);
        $connections = new \ReflectionProperty($listener, 'connections');
        $connections->setValue($listener, [
            $nodeId => [
                'stream' => $stream,
                'lease_id' => (string) $lease->id,
                'fencing_token' => (string) $lease->fencing_token,
                'epoch_id' => $epoch,
                'tenant_id' => $tenantId,
                'owner' => 'listener-generation',
                'configuration_version' => 6,
            ],
        ]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['configuration_version' => 7]);

        $this->assertSame(1, $listener->workOnce('listener-generation'));
        $this->assertSame(0, $transport->openCount);
        $this->assertSame($epoch, $connections->getValue($listener)[$nodeId]['epoch_id']);
        $this->assertSame(7, $connections->getValue($listener)[$nodeId]['configuration_version']);
        $this->assertSame('claimed', DB::table('runtime_listener_leases')->where('id', (string) $lease->id)->value('status'));
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'readiness:7:'.$lease->fencing_token)->count());

        $listener->workOnce('listener-generation');

        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'readiness:7:'.$lease->fencing_token)->count());
    }

    public function test_same_configuration_generation_does_not_emit_readiness_again(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(configurationVersion: 6);
        $stream = $this->eventStream();
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $lease = $leases->claim($tenantId, $nodeId, 'freeswitch-esl-events', 'listener-same-generation', 45);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', 'listener-same-generation');
        $listener = new FreeSwitchEslEventListener(new FreeSwitchCatalog, $receipts, $leases, new class($stream) implements FreeSwitchEslEventTransport
        {
            public function __construct(private readonly mixed $stream) {}

            public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed
            {
                unset($tenantId, $runtimeNodeId, $subscription);

                return $this->stream;
            }

            public function closeEventStream($stream): void
            {
                unset($stream);
            }
        });
        $connections = new \ReflectionProperty($listener, 'connections');
        $connections->setValue($listener, [$nodeId => [
            'stream' => $stream,
            'lease_id' => (string) $lease->id,
            'fencing_token' => (string) $lease->fencing_token,
            'epoch_id' => $epoch,
            'tenant_id' => $tenantId,
            'owner' => 'listener-same-generation',
            'configuration_version' => 6,
        ]]);

        $listener->workOnce('listener-same-generation');

        $this->assertSame(0, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('event_type', 'runtime.readiness.observed')->count());
    }

    public function test_failed_generation_readiness_ingestion_does_not_advance_cached_version(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(configurationVersion: 6);
        $stream = $this->eventStream();
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $lease = $leases->claim($tenantId, $nodeId, 'freeswitch-esl-events', 'listener-generation-retry', 45);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', 'listener-generation-retry');
        $listener = new FreeSwitchEslEventListener(new FreeSwitchCatalog, $receipts, $leases, new class($stream) implements FreeSwitchEslEventTransport
        {
            public function __construct(private readonly mixed $stream) {}

            public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed
            {
                unset($tenantId, $runtimeNodeId, $subscription);

                return $this->stream;
            }

            public function closeEventStream($stream): void
            {
                unset($stream);
            }
        });
        $connections = new \ReflectionProperty($listener, 'connections');
        $connections->setValue($listener, [$nodeId => [
            'stream' => $stream,
            'lease_id' => (string) $lease->id,
            'fencing_token' => (string) $lease->fencing_token,
            'epoch_id' => $epoch,
            'tenant_id' => $tenantId,
            'owner' => 'listener-generation-retry',
            'configuration_version' => 6,
        ]]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['configuration_version' => 7]);
        DB::table('runtime_event_connection_epochs')->where('id', $epoch)->update(['status' => 'closed', 'closed_at' => now()]);

        $listener->workOnce('listener-generation-retry');

        $this->assertSame(6, $connections->getValue($listener)[$nodeId]['configuration_version']);
        $this->assertArrayHasKey($nodeId, $connections->getValue($listener));
    }

    public function test_listener_readiness_observation_projects_canonical_node_state_and_generation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', 'freeswitch-readiness');
        $receiptId = $receipts->ingest($tenantId, $nodeId, 'freeswitch-esl', $epoch, 'ready:2', 'runtime.readiness.observed', 1, [
            'observed_state' => 'ready',
            'configuration_generation' => 2,
        ])['id'];
        $receipt = DB::table('runtime_event_receipts')->where('id', $receiptId)->first();
        $observations = (new FreeSwitchEventNormalizer(new FreeSwitchCatalog, 'runtime.readiness.observed'))->normalize($receipt, [
            'observed_state' => 'ready',
            'configuration_generation' => 2,
        ]);

        (new ProjectionService)->apply($receipt, $observations);

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(2, (int) DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_configuration_version'));
        $this->assertDatabaseHas('runtime_observations', [
            'runtime_node_id' => $nodeId,
            'observation_type' => 'runtime.readiness.observed',
            'configuration_version' => 2,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function runtimeNode(int $configurationVersion = 1): array
    {
        $tenantId = Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'fs-'.substr($tenantId, 0, 8), 'display_name' => 'FreeSWITCH tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'FreeSWITCH', 'slug' => 'fs-'.substr($nodeId, 0, 8), 'runtime_family' => 'freeswitch', 'adapter_key' => 'freeswitch-esl', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => $configurationVersion, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenantId, $nodeId];
    }

    /** @return resource */
    private function eventStream(int $frames = 1)
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, str_repeat("Content-Type: text/event-plain\r\nContent-Length: 0\r\n\r\n", $frames));
        rewind($stream);

        return $stream;
    }

    private function receipt(string $tenantId, string $nodeId, string $key): object
    {
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'freeswitch-esl', $key);
        $id = $receipts->ingest($tenantId, $nodeId, 'freeswitch-esl', $epoch, $key, 'CHANNEL_ANSWER', 1, [])['id'];

        return DB::table('runtime_event_receipts')->where('id', $id)->first();
    }
}
