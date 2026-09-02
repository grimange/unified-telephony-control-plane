<?php

namespace Tests\Feature\FreeSwitch;

use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslClient;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslTransport;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\TelephonyDomain\RuntimeChannelIdentity;
use Tests\TestCase;

final class FreeSwitchEslAdapterTest extends TestCase
{
    public function test_freeswitch_adapter_is_registered_with_the_exact_core_capability_catalog(): void
    {
        $adapter = app(RuntimeAdapterRegistry::class)->get('freeswitch-esl');
        $this->assertInstanceOf(FreeSwitchRuntimeAdapter::class, $adapter);
        $capabilities = config('runtime_registry.adapter_keys.freeswitch-esl.supported_capabilities');
        $this->assertSame(['call.origination', 'call.control', 'call.hold', 'call.dtmf.send', 'media.playback'], $capabilities);
        foreach (['call.transfer', 'recording', 'event.stream', 'runtime.observation', 'conference.lifecycle', 'conference.participation'] as $unsupportedCapability) {
            $this->assertNotContains($unsupportedCapability, $capabilities);
        }
    }

    public function test_exact_t4a_command_contract(): void
    {
        $transport = new RecordingTransport;
        $client = new FreeSwitchEslClient($transport);
        $id = '11111111-2222-3333-4444-555555555555';
        $uuid = RuntimeChannelIdentity::forCallLeg($id);
        $one = [['id' => $id, 'call_id' => 'call-1', 'runtime_channel_id' => $uuid]];
        $two = [$one[0], ['id' => 'leg-2', 'call_id' => 'call-1', 'runtime_channel_id' => 'utcp-call-leg-leg-2']];
        $cases = [
            ['call.leg.originate', [
                'leg_id' => $id,
                'destination_ref' => 'telephony_address:97001',
                'destination_uri' => '97001',
                'route_decision_id' => 'route-decision-1',
                'trunk_endpoint_id' => 'endpoint-1',
                'caller_identity_id' => 'caller-1',
            ], [], 'bgapi', 'originate {origination_uuid='.$uuid.',timer_name=soft,sip_h_X-UTCP-Call-Leg-ID='.$id.',sip_h_X-UTCP-Route-Decision-ID=route-decision-1,sip_h_X-UTCP-Trunk-Endpoint-ID=endpoint-1,sip_h_X-UTCP-Caller-Identity-ID=caller-1}sofia/utcp-internal/97001@kamailio-sip-internal.utcp-platform.svc.cluster.local &park', 'freeswitch.originate'],
            ['call.leg.cancel_origination', [], $one, 'api', 'uuid_kill '.$uuid, 'freeswitch.uuid_kill'],
            ['call.leg.answer', [], $one, 'api', 'uuid_answer '.$uuid, 'freeswitch.uuid_answer'],
            ['call.leg.hangup', [], $one, 'api', 'uuid_kill '.$uuid, 'freeswitch.uuid_kill'],
            ['call.leg.hold', [], $one, 'api', 'uuid_hold '.$uuid, 'freeswitch.uuid_hold'],
            ['call.leg.resume', [], $one, 'api', 'uuid_hold off '.$uuid, 'freeswitch.uuid_hold'],
            ['call.legs.bridge', [], $two, 'api', 'uuid_bridge '.$uuid.' utcp-call-leg-leg-2', 'freeswitch.uuid_bridge'],
            ['call.leg.blind_transfer', ['destination_ref' => '1001'], $one, 'api', 'uuid_transfer '.$uuid.' 1001', 'freeswitch.uuid_transfer'],
            ['call.leg.redirect', ['destination_ref' => 'sip:1001@example.test'], $one, 'api', 'uuid_deflect '.$uuid.' sip:1001@example.test', 'freeswitch.uuid_deflect'],
            ['call.leg.mute', [], $one, 'api', 'uuid_audio '.$uuid.' start read mute 0', 'freeswitch.uuid_audio'],
            ['call.leg.unmute', [], $one, 'api', 'uuid_audio '.$uuid.' stop', 'freeswitch.uuid_audio'],
            ['call.leg.send_dtmf', ['digit' => '5'], $one, 'api', 'uuid_send_dtmf '.$uuid.' 5', 'freeswitch.uuid_send_dtmf'],
            ['call.leg.play_media', ['media_ref' => 'utcp:media/reference-tone'], $one, 'api', 'uuid_broadcast '.$uuid.' /usr/share/freeswitch/sounds/reference-tone.wav aleg', 'freeswitch.uuid_broadcast'],
            ['call.leg.stop_media', ['media_ref' => 'utcp:media/reference-tone'], $one, 'api', 'uuid_break '.$uuid, 'freeswitch.uuid_break'],
        ];
        foreach ($cases as [$operation, $payload, $legs, $mode, $command, $action]) {
            $transport->requests = [];
            $result = $client->executeCallOperation('tenant-1', 'node-1', $operation, $payload, $legs);
            $this->assertSame('completed', $result['status'], $operation);
            $expectedRequests = [['tenant_id' => 'tenant-1', 'runtime_node_id' => 'node-1', 'mode' => $mode, 'command' => $command]];
            $this->assertSame($expectedRequests, $transport->requests, $operation);
            $this->assertSame($action, $result['provider_action'], $operation);
        }
        $this->assertSame(RuntimeChannelIdentity::forCallLeg($id), $uuid);
    }

    public function test_unbridge_prepares_both_channels_before_parking_the_relationship(): void
    {
        $transport = new RecordingTransport;
        $client = new FreeSwitchEslClient($transport);
        $result = $client->executeCallOperation('tenant-1', 'node-1', 'call.legs.unbridge', [], [
            ['id' => 'leg-a', 'call_id' => 'call-1', 'runtime_channel_id' => 'uuid-a'],
            ['id' => 'leg-b', 'call_id' => 'call-1', 'runtime_channel_id' => 'uuid-b'],
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('freeswitch.uuid_park', $result['provider_action']);
        $this->assertSame(['uuid_setvar uuid-a park_after_bridge true', 'uuid_setvar uuid-b park_after_bridge true', 'uuid_park uuid-a'], array_column($transport->requests, 'command'));
    }

    public function test_unbridge_does_not_park_when_first_channel_preparation_fails(): void
    {
        $transport = new FailingCommandTransport('uuid_setvar uuid-a park_after_bridge true');
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('tenant-1', 'node-1', 'call.legs.unbridge', [], $this->unbridgeLegs());

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('freeswitch_esl_command_failed', $result['failure_code']);
        $this->assertSame(['uuid_setvar uuid-a park_after_bridge true'], array_column($transport->requests, 'command'));
    }

    public function test_unbridge_does_not_park_when_second_channel_preparation_fails(): void
    {
        $transport = new FailingCommandTransport('uuid_setvar uuid-b park_after_bridge true');
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('tenant-1', 'node-1', 'call.legs.unbridge', [], $this->unbridgeLegs());

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('freeswitch_esl_command_failed', $result['failure_code']);
        $this->assertSame(['uuid_setvar uuid-a park_after_bridge true', 'uuid_setvar uuid-b park_after_bridge true'], array_column($transport->requests, 'command'));
    }

    public function test_unbridge_reports_park_failure_without_false_success(): void
    {
        $transport = new FailingCommandTransport('uuid_park uuid-a');
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('tenant-1', 'node-1', 'call.legs.unbridge', [], $this->unbridgeLegs());

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('freeswitch_esl_command_failed', $result['failure_code']);
        $this->assertSame(['uuid_setvar uuid-a park_after_bridge true', 'uuid_setvar uuid-b park_after_bridge true', 'uuid_park uuid-a'], array_column($transport->requests, 'command'));
    }

    /** @return list<array{id:string,call_id:string,runtime_channel_id:string}> */
    private function unbridgeLegs(): array
    {
        return [
            ['id' => 'leg-a', 'call_id' => 'call-1', 'runtime_channel_id' => 'uuid-a'],
            ['id' => 'leg-b', 'call_id' => 'call-1', 'runtime_channel_id' => 'uuid-b'],
        ];
    }

    public function test_call_hangup_fans_out_all_active_channels(): void
    {
        $transport = new RecordingTransport;
        $client = new FreeSwitchEslClient($transport);
        $result = $client->executeCallOperation('t', 'n', 'call.hangup', [], [
            ['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a'], ['id' => 'b', 'call_id' => 'c', 'runtime_channel_id' => 'u-b'],
        ]);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['uuid_kill u-a', 'uuid_kill u-b'], array_column($transport->requests, 'command'));
    }

    public function test_unsupported_media_and_recording_operations_remain_explicitly_unsupported(): void
    {
        $transport = new RecordingTransport;
        $client = new FreeSwitchEslClient($transport);
        foreach (['call.leg.attended_transfer', 'call.leg.start_recording', 'call.leg.stop_recording'] as $operation) {
            $result = $client->executeCallOperation('t', 'n', $operation, [], [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']]);
            $this->assertSame('terminal_failure', $result['status']);
            $this->assertSame('freeswitch_call_operation_unsupported', $result['failure_code']);
        }
        $this->assertSame([], $transport->requests);
    }

    public function test_generic_media_reference_is_resolved_before_esl_execution(): void
    {
        $transport = new RecordingTransport;
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('t', 'n', 'call.leg.play_media', ['media_ref' => 'utcp:media/welcome'], [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('uuid_broadcast u-a /usr/share/freeswitch/sounds/welcome.wav aleg', $transport->requests[0]['command']);
    }

    public function test_invalid_media_reference_is_rejected_before_esl_execution(): void
    {
        $transport = new RecordingTransport;
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('t', 'n', 'call.leg.play_media', ['media_ref' => 'sound:welcome'], [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']]);

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('invalid_media_ref', $result['failure_code']);
        $this->assertSame([], $transport->requests);
    }

    public function test_invalid_stop_media_reference_is_rejected_before_esl_execution(): void
    {
        $transport = new RecordingTransport;
        $result = (new FreeSwitchEslClient($transport))->executeCallOperation('t', 'n', 'call.leg.stop_media', ['media_ref' => 'sound:welcome'], [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']]);

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('invalid_media_ref', $result['failure_code']);
        $this->assertSame([], $transport->requests);
    }

    public function test_playback_plus_ok_does_not_fabricate_a_runtime_observation(): void
    {
        $transport = new class implements FreeSwitchEslTransport {
            /** @var list<array<string,string>> */
            public array $requests = [];

            public function execute(string $tenantId, string $runtimeNodeId, string $mode, string $command): array
            {
                $this->requests[] = ['tenant_id' => $tenantId, 'runtime_node_id' => $runtimeNodeId, 'mode' => $mode, 'command' => $command];

                return ['response' => '+OK accepted'];
            }
        };

        $result = (new FreeSwitchEslClient($transport))->executeCallOperation(
            't',
            'n',
            'call.leg.play_media',
            ['media_ref' => 'utcp:media/reference-tone'],
            [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']],
        );

        $this->assertSame('completed', $result['status']);
        $this->assertArrayNotHasKey('runtime_observation', $result);
        $this->assertSame(['uuid_broadcast u-a /usr/share/freeswitch/sounds/reference-tone.wav aleg'], array_column($transport->requests, 'command'));
    }

    public function test_provider_error_is_not_downgraded_to_success(): void
    {
        $result = (new FreeSwitchEslClient(new RecordingTransport('-ERR no such channel')))->executeCallOperation('t', 'n', 'call.leg.hold', [], [['id' => 'a', 'call_id' => 'c', 'runtime_channel_id' => 'u-a']]);
        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('freeswitch_esl_command_failed', $result['failure_code']);
    }
}

final class RecordingTransport implements FreeSwitchEslTransport
{
    /** @var list<array<string,string>> */
    public array $requests = [];

    public function __construct(private readonly string $response = '+OK accepted') {}

    public function execute(string $tenantId, string $runtimeNodeId, string $mode, string $command): array
    {
        $this->requests[] = ['tenant_id' => $tenantId, 'runtime_node_id' => $runtimeNodeId, 'mode' => $mode, 'command' => $command];

        return ['response' => $this->response];
    }
}

final class FailingCommandTransport implements FreeSwitchEslTransport
{
    /** @var list<array<string,string>> */
    public array $requests = [];

    public function __construct(private readonly string $failedCommand) {}

    public function execute(string $tenantId, string $runtimeNodeId, string $mode, string $command): array
    {
        $this->requests[] = ['tenant_id' => $tenantId, 'runtime_node_id' => $runtimeNodeId, 'mode' => $mode, 'command' => $command];

        return ['response' => $command === $this->failedCommand ? '-ERR rejected' : '+OK accepted'];
    }
}
