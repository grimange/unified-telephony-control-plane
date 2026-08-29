<?php

namespace Tests\Feature\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\PayloadSafety;
use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventListener;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskAriException;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskAriReconnectBackoff;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeNodeReconciler;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionService;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\RuntimeEngine\Sources\EventSourceRepository;
use App\RuntimeRegistry\RuntimeRegistryService;
use App\TelephonyDomain\RuntimeChannelIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class AsteriskAriAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_originate_uses_inherited_variables_for_the_outbound_predial_header_handler(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([['status' => 201]]);

        $client->executeCallOperation($tenantId, $nodeId, 'call.leg.originate', [
            'leg_id' => 'leg-1',
            'destination_uri' => '97001',
            'route_decision_id' => 'route-decision-1',
            'trunk_endpoint_id' => 'endpoint-1',
            'caller_identity_id' => 'caller-1',
            'caller_identity_address' => 'sip:utcp-v1@synthetic.example',
        ], [['id' => 'leg-1', 'call_id' => 'call-1', 'runtime_channel_id' => 'channel-1']]);

        $request = $client->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('channels', $request['resource']);
        $this->assertSame([
            'endpoint' => 'Local/97001@utcp-outbound',
            'app' => 'utcp',
            'timeout' => '30',
            'channelId' => RuntimeChannelIdentity::forCallLeg('leg-1'),
            'formats' => 'ulaw',
            'callerId' => 'utcp-v1 <utcp-v1>',
        ], $request['query']);
        $this->assertSame([
            'variables' => [
                '__UTCP_CALL_LEG_ID' => 'leg-1',
                '__UTCP_ROUTE_DECISION_ID' => 'route-decision-1',
                '__UTCP_TRUNK_ENDPOINT_ID' => 'endpoint-1',
                '__UTCP_CALLER_IDENTITY_ID' => 'caller-1',
            ],
        ], $request['body']);
        $this->assertArrayNotHasKey('extension', $request['query']);
        $this->assertArrayNotHasKey('context', $request['query']);
        $this->assertArrayNotHasKey('priority', $request['query']);
    }

    public function test_originate_rejects_selected_caller_identity_without_a_resolved_address(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([['status' => 201]]);

        try {
            $client->executeCallOperation($tenantId, $nodeId, 'call.leg.originate', [
                'leg_id' => 'leg-1',
                'destination_uri' => '97001',
                'caller_identity_id' => 'caller-1',
            ], [['id' => 'leg-1', 'call_id' => 'call-1', 'runtime_channel_id' => 'channel-1']]);
            $this->fail('selected caller identity without an address must fail before ARI execution');
        } catch (AsteriskAriException $exception) {
            $this->assertSame('ari_caller_identity_invalid', $exception->failureCode);
        }

        $this->assertSame([], $client->requests);
    }

    public function test_originate_derives_asterisk_caller_id_from_the_canonical_sip_address(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([['status' => 201]]);

        $client->executeCallOperation($tenantId, $nodeId, 'call.leg.originate', [
            'leg_id' => 'leg-1',
            'destination_uri' => '97001',
            'caller_identity_id' => 'caller-1',
            'caller_identity_address' => 'sip:utcp-v1@synthetic.example',
        ], [['id' => 'leg-1', 'call_id' => 'call-1', 'runtime_channel_id' => 'channel-1']]);

        $this->assertSame('utcp-v1 <utcp-v1>', $client->requests[0]['query']['callerId']);
        $this->assertSame('ulaw', $client->requests[0]['query']['formats']);
        $this->assertArrayNotHasKey('originator', $client->requests[0]['query']);
    }

    public function test_originate_fails_closed_when_canonical_execution_media_formats_are_invalid(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        config(['asterisk_ari.execution_media_formats' => []]);
        $client = $this->ariClientWithResponses([['status' => 201]]);

        try {
            $client->executeCallOperation($tenantId, $nodeId, 'call.leg.originate', [
                'leg_id' => 'leg-1',
                'destination_uri' => '97001',
            ], [['id' => 'leg-1', 'call_id' => 'call-1', 'runtime_channel_id' => 'channel-1']]);
            $this->fail('originate must reject missing execution media authority before ARI execution');
        } catch (AsteriskAriException $exception) {
            $this->assertSame('ari_execution_media_formats_invalid', $exception->failureCode);
        }

        $this->assertSame([], $client->requests);
    }

    public function test_managed_asterisk_execution_media_authority_matches_the_kamailio_facing_endpoint(): void
    {
        $this->assertSame(['ulaw'], config('asterisk_ari.execution_media_formats'));

        $pjsip = file_get_contents(base_path('../../infrastructure/docker/asterisk/config/pjsip.conf'));
        $this->assertIsString($pjsip);
        $this->assertMatchesRegularExpression('/\[kamailio-edge\][\s\S]*?allow=ulaw/', $pjsip);
        $this->assertStringNotContainsString('allow=slin', $pjsip);
        $this->assertStringNotContainsString('allow=alaw', $pjsip);
    }

    public function test_ari_request_serializes_json_body_and_passes_it_to_http_transport(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithTransportCapture();

        $method = new ReflectionMethod($client, 'requestAri');
        $method->invoke($client, $nodeId, 'POST', 'channels', [
            'endpoint' => 'Local/97001@utcp-outbound',
            'context' => 'utcp-outbound',
            'extension' => '97001',
            'priority' => '1',
            'timeout' => '30',
            'channelId' => 'utcp-call-leg-leg-1',
        ], 1000, [201], [
            'variables' => ['__UTCP_CALL_LEG_ID' => 'leg-1'],
        ]);

        $request = $client->transportRequests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('endpoint=Local%2F97001%40utcp-outbound', $request['url']);
        $this->assertStringContainsString('Content-Type: application/json', $request['headers']);
        $this->assertStringNotContainsString('Content-Length: 0', $request['headers']);
        $this->assertSame('{"variables":{"__UTCP_CALL_LEG_ID":"leg-1"}}', $request['body']);
    }

    public function test_bodyless_ari_request_retains_empty_transport_body(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithTransportCapture();

        $method = new ReflectionMethod($client, 'requestAri');
        $method->invoke($client, $nodeId, 'GET', 'channels/channel-1', [], 1000, [200]);

        $request = $client->transportRequests[0];
        $this->assertNull($request['body']);
        $this->assertStringNotContainsString('Content-Type: application/json', $request['headers']);
        $this->assertStringContainsString('Content-Length: 0', $request['headers']);
    }

    public function test_asterisk_playback_resolves_generic_media_and_rejects_invalid_syntax_before_ari_execution(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([['status' => 204]]);
        $legs = [['id' => 'leg-1', 'call_id' => 'call-1', 'runtime_channel_id' => 'channel-1']];

        $result = $client->executeCallOperation($tenantId, $nodeId, 'call.leg.play_media', ['media_ref' => 'utcp:media/reference-tone'], $legs);
        $this->assertSame('channels.play', $result['provider_action']);
        $this->assertSame('channels/channel-1/play', $client->requests[0]['resource']);

        $generic = $this->ariClientWithResponses([['status' => 204]]);
        $generic->executeCallOperation($tenantId, $nodeId, 'call.leg.play_media', ['media_ref' => 'utcp:media/welcome'], $legs);
        $this->assertSame('channels/channel-1/play', $generic->requests[0]['resource']);

        $invalid = $this->ariClientWithResponses([]);
        try {
            $invalid->executeCallOperation($tenantId, $nodeId, 'call.leg.play_media', ['media_ref' => 'sound:welcome'], $legs);
            $this->fail('invalid media syntax should fail before ARI execution');
        } catch (AsteriskAriException $exception) {
            $this->assertSame('invalid_media_ref', $exception->failureCode);
        }
        $this->assertSame([], $invalid->requests);
    }

    public function test_call_control_and_recording_routes_match_asterisk_ari_resource_contract(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 204],
            ['status' => 204],
            ['status' => 204],
            ['status' => 204],
            ['status' => 204],
            ['status' => 204],
        ]);
        $legs = [[
            'id' => 'leg-1',
            'call_id' => 'call-1',
            'runtime_channel_id' => 'channel-1',
        ]];

        $expected = [
            ['call.leg.hold', 'POST', 'channels/channel-1/hold', 'channels.hold'],
            ['call.leg.resume', 'DELETE', 'channels/channel-1/hold', 'channels.resume'],
            ['call.leg.mute', 'POST', 'channels/channel-1/mute', 'channels.mute'],
            ['call.leg.unmute', 'DELETE', 'channels/channel-1/mute', 'channels.unmute'],
            ['call.leg.start_recording', 'POST', 'channels/channel-1/record', 'channels.record'],
            ['call.leg.stop_recording', 'POST', 'recordings/live/leg-1/stop', 'recordings.stop'],
        ];

        foreach ($expected as [$operationType, $method, $resource, $providerAction]) {
            $result = $client->executeCallOperation($tenantId, $nodeId, $operationType, [], $legs);

            $this->assertSame($providerAction, $result['provider_action']);
            $this->assertSame($method, $client->requests[array_key_last($client->requests)]['method']);
            $this->assertSame($resource, $client->requests[array_key_last($client->requests)]['resource']);
        }

        $resources = implode('\n', array_column($client->requests, 'resource'));
        $this->assertStringNotContainsString('/unhold', $resources);
        $this->assertCount(0, array_filter(
            $client->requests,
            static fn (array $request): bool => $request['method'] === 'DELETE' && $request['resource'] === 'recordings/live/leg-1',
        ));
    }

    public function test_asterisk_adapter_rejects_unknown_conference_management_operations(): void
    {
        $catalog = new AsteriskCatalog;
        $adapter = new AsteriskRuntimeAdapter($catalog, new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class)));
        [, $nodeId] = $this->runtimeNode();

        $result = $adapter->execute([
            'operation_type' => 'conference.force_remove_channel',
            'runtime_node_id' => $nodeId,
            'payload' => ['conference_id' => 'conference-1'],
        ]);

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('unsupported_capability', $result['failure_class']);
        $this->assertSame('asterisk_operation_unsupported', $result['failure_code']);
    }

    public function test_asterisk_absence_verification_reports_absent_only_after_bridge_and_participants_absent(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext(withParticipant: true);
        [$adapter, $client] = $this->adapterWithRuntimeSummary(fn (string $participantId): array => $participantId === ''
            ? ['bridge_exists' => false]
            : ['bridge_exists' => false, 'participant_channel_exists' => false, 'participant_channel_in_bridge' => false]
        );

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('completed', $result['status']);
        $this->assertSame('conference.runtime_fence_verified', $result['event_type']);
        $this->assertSame('absent', $result['event_payload']['verification_result']);
        $this->assertFalse($result['event_payload']['bridge_present']);
        $this->assertFalse($result['event_payload']['participant_channel_present']);
        $this->assertCount(2, $client->calls);
    }

    public function test_asterisk_absence_verification_reports_present_when_bridge_exists(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext();
        [$adapter, $client] = $this->adapterWithRuntimeSummary(fn (): array => ['bridge_exists' => true]);

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('completed', $result['status']);
        $this->assertSame('present', $result['event_payload']['verification_result']);
        $this->assertTrue($result['event_payload']['bridge_present']);
        $this->assertCount(1, $client->calls);
    }

    public function test_asterisk_absence_verification_reports_present_when_participant_channel_exists(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext(withParticipant: true);
        [$adapter] = $this->adapterWithRuntimeSummary(fn (string $participantId): array => $participantId === ''
            ? ['bridge_exists' => false]
            : ['bridge_exists' => false, 'participant_channel_exists' => true, 'participant_channel_in_bridge' => false]
        );

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('completed', $result['status']);
        $this->assertSame('present', $result['event_payload']['verification_result']);
        $this->assertFalse($result['event_payload']['bridge_present']);
        $this->assertTrue($result['event_payload']['participant_channel_present']);
    }

    public function test_asterisk_absence_verification_treats_unreachable_runtime_as_unavailable_not_absent(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext();
        [$adapter] = $this->adapterWithRuntimeSummary(function (): array {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_unreachable', 'ARI endpoint is unavailable.', true);
        });

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame('runtime_unavailable', $result['failure_class']);
        $this->assertSame('ari_unreachable', $result['failure_code']);
        $this->assertArrayNotHasKey('event_payload', $result);
    }

    public function test_asterisk_absence_verification_treats_partial_inspection_failure_as_failed_not_absent(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext(withParticipant: true);
        [$adapter] = $this->adapterWithRuntimeSummary(function (string $participantId): array {
            if ($participantId === '') {
                return ['bridge_exists' => false];
            }

            throw new AsteriskAriException(FailureClass::InternalError, 'participant_inspection_failed', 'Participant inspection failed.', true);
        });

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame('internal_error', $result['failure_class']);
        $this->assertSame('participant_inspection_failed', $result['failure_code']);
        $this->assertArrayNotHasKey('event_payload', $result);
    }

    public function test_bridge_specific_404_with_healthy_family_is_authoritative_absence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ]);

        $summary = $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-healthy-absent');

        $this->assertFalse($summary['bridge_exists']);
        $this->assertSame('healthy_absent', $summary['runtime_reference_health']);
        $this->assertSame([
            ['method' => 'GET', 'resource' => 'bridges/'.$client->conferenceBridgeId('conference-healthy-absent'), 'timeout_ms' => 4000, 'accepted_statuses' => [200, 404]],
            ['method' => 'GET', 'resource' => 'bridges', 'timeout_ms' => 4000, 'accepted_statuses' => [200]],
        ], $client->requests);
    }

    public function test_bridge_specific_404_with_degraded_family_is_retryable_unavailability(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 500, 'body' => ''],
        ]);

        try {
            $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-degraded-family');
            $this->fail('Expected degraded ARI resource family to throw.');
        } catch (AsteriskAriException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame(FailureClass::RuntimeUnavailable, $exception->failureClass);
            $this->assertSame('ari_resource_family_degraded', $exception->failureCode);
        }

        $this->assertSame([
            'bridges/'.$client->conferenceBridgeId('conference-degraded-family'),
            'bridges',
        ], array_column($client->requests, 'resource'));
    }

    public function test_bridge_family_transport_failure_after_specific_404_is_not_absence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['throw' => new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_transport_failed', 'ARI HTTP transport failed.', true)],
        ]);

        $this->expectException(AsteriskAriException::class);
        $this->expectExceptionMessage('ARI resource family is degraded.');

        $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-family-transport');
    }

    public function test_channel_specific_404_with_healthy_family_is_authoritative_absence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 200, 'body' => json_encode(['id' => 'utcp-conf-conference-channel-absent', 'channels' => []], JSON_THROW_ON_ERROR)],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ]);

        $summary = $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-channel-absent', 'participant-channel-absent');

        $this->assertTrue($summary['bridge_exists']);
        $this->assertFalse($summary['participant_channel_exists']);
        $this->assertFalse($summary['participant_peer_channel_exists']);
        $this->assertFalse($summary['participant_any_channel_exists']);
        $this->assertSame('healthy_present', $summary['runtime_reference_health']);
        $this->assertSame('healthy_absent', $summary['participant_runtime_reference_health']);
        $this->assertSame([
            'bridges/'.$client->conferenceBridgeId('conference-channel-absent'),
            'channels/'.$client->participantChannelId('participant-channel-absent'),
            'channels',
            'channels/'.$client->participantPeerChannelId('participant-channel-absent'),
            'channels',
        ], array_column($client->requests, 'resource'));
    }

    public function test_channel_specific_404_with_degraded_family_is_retryable_unavailability(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 200, 'body' => json_encode(['id' => 'utcp-conf-conference-channel-degraded', 'channels' => []], JSON_THROW_ON_ERROR)],
            ['status' => 404, 'body' => ''],
            ['status' => 404, 'body' => ''],
        ]);

        try {
            $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-channel-degraded', 'participant-channel-degraded');
            $this->fail('Expected degraded channel resource family to throw.');
        } catch (AsteriskAriException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('ari_resource_family_degraded', $exception->failureCode);
        }
    }

    public function test_existing_bridge_present_does_not_probe_family(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 200, 'body' => json_encode(['id' => 'utcp-conf-conference-present', 'channels' => []], JSON_THROW_ON_ERROR)],
        ]);

        $summary = $client->conferenceRuntimeSummary($tenantId, $nodeId, 'conference-present');

        $this->assertTrue($summary['bridge_exists']);
        $this->assertSame('healthy_present', $summary['runtime_reference_health']);
        $this->assertSame(['bridges/'.$client->conferenceBridgeId('conference-present')], array_column($client->requests, 'resource'));
    }

    public function test_specific_transport_failure_is_inspection_unavailable_not_absent(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['throw' => new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_transport_failed', 'ARI HTTP transport failed.', true)],
        ]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $result = $adapter->inspectConferenceRuntime($tenantId, $nodeId, 'conference-transport');

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('ari_http_transport_failed', $result->failureCode);
        $this->assertSame('transport_unavailable', $result->runtimeReferenceHealth);
    }

    public function test_verification_operation_retries_when_bridge_family_is_degraded_then_recovers(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext();
        $this->configureAriNode($tenantId, $nodeId);
        $catalog = new AsteriskCatalog;
        $degradedClient = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 500, 'body' => ''],
        ]);
        $adapter = new AsteriskRuntimeAdapter($catalog, $degradedClient);

        $degraded = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('retry_scheduled', $degraded['status']);
        $this->assertSame('runtime_unavailable', $degraded['failure_class']);
        $this->assertSame('ari_resource_family_degraded', $degraded['failure_code']);
        $this->assertArrayNotHasKey('event_payload', $degraded);

        $recoveredClient = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ]);
        $recoveredAdapter = new AsteriskRuntimeAdapter($catalog, $recoveredClient);

        $recovered = $recoveredAdapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('completed', $recovered['status']);
        $this->assertSame('conference.runtime_fence_verified', $recovered['event_type']);
        $this->assertSame('absent', $recovered['event_payload']['verification_result']);
        $this->assertSame('healthy_absent', $recovered['event_payload']['runtime_reference_health']);
    }

    public function test_wrong_node_absence_verification_fails_before_ari_requests(): void
    {
        [$tenantId, $boundNodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext();
        [, $wrongNodeId] = $this->runtimeNodeForTenant($tenantId);
        $client = $this->ariClientWithResponses([]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);
        $operation = $this->fenceOperation($tenantId, $wrongNodeId, $conferenceId, $bindingId);

        $result = $adapter->execute($operation);

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('absence_verification_context_not_found', $result['failure_code']);
        $this->assertSame([], $client->requests);
        $this->assertNotSame($boundNodeId, $wrongNodeId);
    }

    public function test_stale_generation_absence_verification_completes_before_ari_requests(): void
    {
        [$tenantId, $nodeId, $conferenceId, $bindingId] = $this->conferenceFenceContext();
        DB::table('conferences')->where('id', $conferenceId)->update([
            'configuration_generation' => 8,
            'updated_at' => now(),
        ]);
        $client = $this->ariClientWithResponses([]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $result = $adapter->execute($this->fenceOperation($tenantId, $nodeId, $conferenceId, $bindingId));

        $this->assertSame('completed', $result['status']);
        $this->assertSame('runtime_operation.asterisk_conference_stale', $result['event_type']);
        $this->assertTrue($result['event_payload']['stale_operation']);
        $this->assertSame([], $client->requests);
    }

    public function test_cleanup_bridge_404_remains_idempotent_without_family_probe(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
        ]);

        $result = $client->closeConferenceBridge($tenantId, $nodeId, 'conference-cleanup', 7);

        $this->assertTrue($result['absent']);
        $this->assertSame(['bridges/'.$client->conferenceBridgeId('conference-cleanup')], array_column($client->requests, 'resource'));
    }

    public function test_cleanup_channel_404_remains_idempotent_without_family_probe(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 404, 'body' => ''],
        ]);

        $result = $client->removeParticipantChannel($tenantId, $nodeId, 'conference-cleanup', 'participant-cleanup', 7);

        $this->assertTrue($result['absent']);
        $this->assertSame([
            'bridges/'.$client->conferenceBridgeId('conference-cleanup').'/removeChannel',
            'channels/'.$client->participantChannelId('participant-cleanup'),
            'channels/'.$client->participantPeerChannelId('participant-cleanup'),
        ], array_column($client->requests, 'resource'));
    }

    public function test_participant_remove_operation_reinspects_both_local_legs_before_completion(): void
    {
        [$tenantId, $nodeId, $conferenceId, $participantId] = $this->participantCleanupContext('closed', 'removed', 'closed', 'joined');
        $client = $this->ariClientWithResponses([
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $result = $adapter->execute($this->participantRemoveOperation($tenantId, $nodeId, $conferenceId, $participantId));

        $this->assertSame('completed', $result['status']);
        $this->assertSame('runtime_operation.asterisk_conference_participant_removed', $result['event_type']);
        $this->assertFalse($result['event_payload']['runtime_reference_present']);
        $this->assertFalse($result['event_payload']['participant_channel_present']);
        $this->assertFalse($result['event_payload']['participant_peer_channel_present']);
        $this->assertSame([
            'bridges/'.$client->conferenceBridgeId($conferenceId).'/removeChannel',
            'channels/'.$client->participantChannelId($participantId),
            'channels/'.$client->participantPeerChannelId($participantId),
            'bridges/'.$client->conferenceBridgeId($conferenceId),
            'bridges',
            'channels/'.$client->participantChannelId($participantId),
            'channels',
            'channels/'.$client->participantPeerChannelId($participantId),
            'channels',
        ], array_column($client->requests, 'resource'));
    }

    public function test_participant_remove_operation_retries_when_local_peer_remains_present(): void
    {
        [$tenantId, $nodeId, $conferenceId, $participantId] = $this->participantCleanupContext('closed', 'removed', 'closed', 'joined');
        $client = $this->ariClientWithResponses([
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 200, 'body' => json_encode(['id' => 'peer'], JSON_THROW_ON_ERROR)],
        ]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $result = $adapter->execute($this->participantRemoveOperation($tenantId, $nodeId, $conferenceId, $participantId));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame('runtime_unavailable', $result['failure_class']);
        $this->assertSame('ari_participant_cleanup_pending', $result['failure_code']);
    }

    public function test_participant_remove_operation_defers_when_completion_inspection_family_degraded(): void
    {
        [$tenantId, $nodeId, $conferenceId, $participantId] = $this->participantCleanupContext('closed', 'removed', 'closed', 'joined');
        $client = $this->ariClientWithResponses([
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 500, 'body' => ''],
        ]);
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $result = $adapter->execute($this->participantRemoveOperation($tenantId, $nodeId, $conferenceId, $participantId));

        $this->assertSame('retry_scheduled', $result['status']);
        $this->assertSame('runtime_unavailable', $result['failure_class']);
        $this->assertSame('ari_resource_family_degraded', $result['failure_code']);
    }

    public function test_orphan_reclamation_event_is_emitted_once_after_proven_cleanup(): void
    {
        [$tenantId, $nodeId, $conferenceId, $participantId] = $this->participantCleanupContext('closed', 'removed', 'closed', 'left');
        $bindingId = (string) DB::table('conference_runtime_bindings')->where('conference_id', $conferenceId)->value('id');
        DB::table('conference_runtime_bindings')->where('id', $bindingId)->update([
            'status' => 'retired',
            'unbound_at' => now(),
            'updated_at' => now(),
        ]);
        $responses = [
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ];

        $operation = $this->participantRemoveOperation($tenantId, $nodeId, $conferenceId, $participantId, [
            'historical_runtime_binding_id' => $bindingId,
            'orphan_reclamation' => true,
        ]);
        $first = (new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->ariClientWithResponses($responses)))->execute($operation);
        $second = (new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->ariClientWithResponses($responses)))->execute($operation);

        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed', $second['status']);
        $this->assertDatabaseCount('control_plane_outbox_messages', 1);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'event_type' => 'conference_participant.channel_reclaimed',
            'aggregate_type' => 'conference_participant',
            'aggregate_id' => $participantId,
        ]);
        $this->assertDatabaseCount('control_plane_audit_records', 1);
        $this->assertDatabaseHas('control_plane_audit_records', [
            'action' => 'conference_participant.channel_reclaimed',
            'subject_type' => 'conference_participant',
            'subject_id' => $participantId,
        ]);
    }

    public function test_reconstruction_absence_and_create_conflict_remain_idempotent_without_family_probe(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $client = $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 409, 'body' => ''],
        ]);

        $result = $client->ensureConferenceBridge($tenantId, $nodeId, 'conference-reconstruct', 7);

        $this->assertFalse($result['already_existed']);
        $this->assertSame([
            'bridges/'.$client->conferenceBridgeId('conference-reconstruct'),
            'bridges',
        ], array_column($client->requests, 'resource'));
        $this->assertSame('POST', $client->requests[1]['method']);
    }

    public function test_inspection_metric_records_runtime_reference_health_classification(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $registry = app(RuntimeAdapterRegistry::class);
        $registry->register(new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->ariClientWithResponses([
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '[]'],
        ])));
        $service = app(RuntimeConferenceInspectionService::class);

        $result = $service->inspect($tenantId, $nodeId, 'conference-metric');

        $this->assertSame('observed', $result->status);
        $this->assertSame('healthy_absent', $result->runtimeReferenceHealth);
        $this->assertDatabaseHas('conference_recovery_metric_events', [
            'adapter_key' => 'asterisk-ari',
            'resource_type' => 'conference',
            'result' => 'observed',
            'reason' => 'healthy_absent',
        ]);
    }

    public function test_listener_leases_are_node_scoped_and_fenced(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $leases = new RuntimeListenerLeaseRepository;

        $first = $leases->claim($tenantId, $nodeId, 'asterisk-ari-events', 'listener-a', 60);
        $this->assertNotNull($first);
        $this->assertNull($leases->claim($tenantId, $nodeId, 'asterisk-ari-events', 'listener-b', 60));
        $this->assertTrue($leases->isCurrent($first->id, 'listener-a', $first->fencing_token));

        DB::table('runtime_listener_leases')->where('id', $first->id)->update(['lease_expires_at' => now()->subSecond()]);
        $takeover = $leases->claim($tenantId, $nodeId, 'asterisk-ari-events', 'listener-b', 60);
        $this->assertNotNull($takeover);
        $this->assertNotSame($first->fencing_token, $takeover->fencing_token);
        $this->assertFalse($leases->renew($first->id, 'listener-a', $first->fencing_token, 60));
        $this->assertTrue($leases->release($takeover->id, 'listener-b', $takeover->fencing_token));
    }

    public function test_profile_configuration_change_releases_the_listener_lease_through_its_canonical_event_source(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $sources = new EventSourceRepository;
        $leases = new RuntimeListenerLeaseRepository($sources);
        $catalog = new AsteriskCatalog;

        $claimed = $leases->claim($tenantId, $nodeId, $catalog->listenerKind(), 'listener-a', 60);
        $this->assertNotNull($claimed);
        $source = $sources->ensureRuntimeNodeSource($tenantId, $nodeId);
        $this->assertSame($source->id, $claimed->event_source_id);

        $profiles = app(AsteriskAriProfileService::class);
        $profiles->put(ExecutionContext::system(tenantId: $tenantId), $tenantId, $nodeId, [
            'application_name' => 'utcp-t0-observation',
            'connect_timeout_ms' => 2000,
            'request_timeout_ms' => 4000,
            'websocket_handshake_timeout_ms' => 4000,
            'heartbeat_interval_ms' => 15000,
            'reconnect_min_delay_ms' => 1000,
            'reconnect_max_delay_ms' => 30000,
        ]);

        $this->assertDatabaseHas('runtime_listener_leases', [
            'id' => $claimed->id,
            'event_source_id' => $source->id,
            'status' => 'released',
        ]);
        $this->assertDatabaseCount('event_sources', 1);
    }

    public function test_listener_ordinary_eligibility_still_uses_active_and_draining_nodes_only(): void
    {
        [$activeTenant, $activeNode] = $this->runtimeNode();
        [$drainingTenant, $drainingNode] = $this->runtimeNode();
        [$disabledTenant, $disabledNode] = $this->runtimeNode();
        [$retiredTenant, $retiredNode] = $this->runtimeNode();
        $this->configureAriNode($activeTenant, $activeNode);
        $this->configureAriNode($drainingTenant, $drainingNode);
        $this->configureAriNode($disabledTenant, $disabledNode);
        $this->configureAriNode($retiredTenant, $retiredNode);

        DB::table('runtime_nodes')->where('id', $drainingNode)->update(['desired_state' => 'draining']);
        DB::table('runtime_nodes')->where('id', $disabledNode)->update(['desired_state' => 'disabled']);
        DB::table('runtime_nodes')->where('id', $retiredNode)->update(['desired_state' => 'retired']);

        $eligible = $this->eligibleNodeIds();

        $this->assertContains($activeNode, $eligible);
        $this->assertContains($drainingNode, $eligible);
        $this->assertNotContains($disabledNode, $eligible);
        $this->assertNotContains($retiredNode, $eligible);
    }

    public function test_disabled_node_with_actionable_restore_operation_is_listener_eligible(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 4,
        ]);

        foreach ([
            OperationStatus::Pending,
            OperationStatus::Leased,
            OperationStatus::Running,
            OperationStatus::RetryScheduled,
        ] as $status) {
            DB::table('runtime_operations')->delete();
            $this->restoreAuthority($tenantId, $nodeId, 4, status: $status);

            $this->assertContains($nodeId, $this->eligibleNodeIds(), "{$status->value} restore operation should grant restoration listener eligibility");
        }
    }

    public function test_disabled_listener_eligibility_requires_matching_restore_authority(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$otherTenant, $otherNode] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $this->configureAriNode($otherTenant, $otherNode);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 7,
        ]);
        DB::table('runtime_nodes')->where('id', $otherNode)->update(['desired_state' => 'disabled']);

        $this->restoreAuthority($otherTenant, $nodeId, 7, payloadTenantId: $otherTenant);
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'cross-tenant restore authority must not grant listener eligibility');
        DB::table('runtime_operations')->delete();

        $this->restoreAuthority($tenantId, $otherNode, 7, payloadRuntimeNodeId: $otherNode);
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'restore authority for another RuntimeNode must not grant listener eligibility');
        DB::table('runtime_operations')->delete();

        $this->restoreAuthority($tenantId, $nodeId, 6);
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'stale configuration version must not grant listener eligibility');
        DB::table('runtime_operations')->delete();

        $this->restoreAuthority($tenantId, $nodeId, 7, operationType: 'runtime.node.runtime.fence');
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'wrong operation type must not grant listener eligibility');
        DB::table('runtime_operations')->delete();

        $this->restoreAuthority($tenantId, $nodeId, 7, requestedDesiredState: 'disabled');
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'non-active restore request must not grant listener eligibility');
        DB::table('runtime_operations')->delete();

        $this->restoreAuthority($tenantId, $nodeId, 7, sourceFenceOperationId: null);
        $this->assertNotContains($nodeId, $this->eligibleNodeIds(), 'restore authority without a source fence identity must not grant listener eligibility');
    }

    public function test_terminal_restore_operations_do_not_grant_disabled_listener_eligibility(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 3,
        ]);

        foreach ([
            OperationStatus::Succeeded,
            OperationStatus::TerminalFailed,
            OperationStatus::Cancelled,
            OperationStatus::Expired,
        ] as $status) {
            DB::table('runtime_operations')->delete();
            $this->restoreAuthority($tenantId, $nodeId, 3, status: $status);

            $this->assertNotContains($nodeId, $this->eligibleNodeIds(), "{$status->value} restore operation must not grant disabled-node listener eligibility");
        }
    }

    public function test_restore_authorized_listener_node_remains_placement_ineligible_while_disabled(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'observed_state' => 'ready',
            'configuration_version' => 5,
            'observed_at' => now(),
        ]);
        $this->restoreAuthority($tenantId, $nodeId, 5);

        $this->assertContains($nodeId, $this->eligibleNodeIds(), 'restore authority should permit listener attachment');
        $this->assertFalse(DB::table('runtime_nodes')
            ->where('id', $nodeId)
            ->where('tenant_id', $tenantId)
            ->whereIn('desired_state', ['active', 'draining'])
            ->where('observed_state', 'ready')
            ->exists(), 'disabled restore-authorized nodes must remain excluded from placement until restoration activates them');
    }

    public function test_asterisk_events_normalize_to_runtime_node_observations_without_raw_payloads(): void
    {
        [, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $normalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('runtime_info_observed'));

        $observations = $normalizer->normalize((object) [
            'runtime_node_id' => $nodeId,
            'connection_epoch_id' => 'epoch-1',
        ], [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
            'asterisk_version' => '20.6.0',
            'password' => 'must-not-project',
        ]);

        $this->assertCount(1, $observations);
        $this->assertSame('runtime.readiness.observed', $observations[0]['observation_type']);
        $this->assertSame('runtime_node', $observations[0]['subject_type']);
        $this->assertSame($nodeId, $observations[0]['subject_id']);
        $this->assertSame('ready', $observations[0]['observed_state']);
        $this->assertSame(3, $observations[0]['configuration_version']);
        $this->assertArrayNotHasKey('password', $observations[0]['payload']);
        $this->assertArrayNotHasKey('asterisk_version', $observations[0]['payload']);
    }

    public function test_unknown_native_ari_events_normalize_safely_without_touching_desired_state_or_conference_state(): void
    {
        [, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $normalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('unknown_event_observed'));

        $observations = $normalizer->normalize((object) [
            'runtime_node_id' => $nodeId,
            'connection_epoch_id' => 'epoch-1',
        ], [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
            'ari_event_type' => 'SomeFutureNativeAriEventNotYetSupported',
            'password' => 'must-not-project',
        ]);

        $this->assertCount(1, $observations);
        $this->assertSame('runtime_node', $observations[0]['subject_type'], 'an unknown Asterisk event remains generic runtime evidence, not conference or participant state');
        $this->assertSame($nodeId, $observations[0]['subject_id']);
        $this->assertSame('unknown', $observations[0]['observed_state'], 'an unrecognized event must not manufacture RuntimeNode degradation');
        $this->assertArrayNotHasKey('password', $observations[0]['payload']);

        // The projection pathway only ever writes runtime_nodes.observed_state / observed_at / observed_configuration_version
        // for a runtime_node-scoped observation; desired_state is never referenced by that code path, and conference /
        // conference_participant projection is only invoked for those specific subject types, so an Asterisk-sourced
        // unknown event structurally cannot reach C5 conference or participant state.
        $projection = new ProjectionService;
        $tenantId = DB::table('runtime_nodes')->where('id', $nodeId)->value('tenant_id');
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'ready']);
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'test-worker');
        $ingested = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, null, $catalog->eventType('unknown_event_observed'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 3,
            'ari_event_type' => 'SomeFutureNativeAriEventNotYetSupported',
            'occurred_at' => now()->toISOString(),
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $ingested['id'])->first();
        $beforeDesiredState = DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state');

        $projection->apply($receipt, $observations);

        $this->assertSame($beforeDesiredState, DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'), 'desired_state must never be mutated by observation projection');
        $this->assertSame(0, DB::table('conferences')->count(), 'no conference row may be created or touched by an Asterisk runtime_node observation');
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'), 'generic capability evidence must not mutate RuntimeNode readiness');

        // Supported receipt processing continues after an unknown event: a normal readiness observation still projects cleanly.
        $readyNormalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('runtime_info_observed'));
        $readyObservations = $readyNormalizer->normalize($receipt, [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
        ]);
        $projection->apply($receipt, $readyObservations);
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'), 'processing must continue normally after an unknown event was safely observed');
    }

    public function test_stasis_start_is_retained_as_generic_evidence_without_runtime_readiness_authority(): void
    {
        [, $nodeId] = $this->runtimeNode();
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'ready']);
        $catalog = new AsteriskCatalog;
        $normalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('stasis_start'));

        $observations = $normalizer->normalize((object) [
            'runtime_node_id' => $nodeId,
        ], [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
            'ari_event_type' => 'StasisStart',
            'channel_id' => '1786760286.1',
        ]);

        $this->assertCount(1, $observations);
        $this->assertSame('runtime.capability.observed', $observations[0]['observation_type']);
        $this->assertSame('unknown', $observations[0]['observed_state']);
        $this->assertSame('StasisStart', $observations[0]['payload']['ari_event_type']);

        $tenantId = DB::table('runtime_nodes')->where('id', $nodeId)->value('tenant_id');
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'stasis-normalizer-test');
        $ingested = $receipts->ingest(
            $tenantId,
            $nodeId,
            $catalog->adapterKey(),
            $epochId,
            null,
            $catalog->eventType('stasis_start'),
            1,
            [
                'configuration_generation' => 3,
                'occurred_at' => now()->toISOString(),
                'ari_event_type' => 'StasisStart',
                'channel_id' => '1786760286.1',
            ],
        );
        $receipt = DB::table('runtime_event_receipts')->where('id', $ingested['id'])->first();
        (new ProjectionService)->apply($receipt, $observations);

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
    }

    public function test_stasis_start_normalizes_trusted_inbound_correlation_without_leaking_provider_fields(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $addressId = '11111111-1111-4111-8111-111111111111';
        $trunkId = '22222222-2222-4222-8222-222222222222';
        $endpointId = '33333333-3333-4333-8333-333333333333';
        $runtimeNodeId = '44444444-4444-4444-8444-444444444444';
        $observations = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('stasis_start')))->normalize((object) [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
        ], [
            'channel_id' => 'ari-inbound-1',
            'application_args' => [
                'utcp-in-'.$addressId,
                strtoupper($trunkId),
                $addressId,
                $endpointId,
                $runtimeNodeId,
                'provider-internal-value',
            ],
        ]);

        $payload = $observations[0]['payload'];
        $this->assertSame('utcp-in-'.$addressId, $payload['called_address']);
        $this->assertSame($trunkId, $payload['ingress_external_trunk_id']);
        $this->assertSame($addressId, $payload['ingress_telephony_address_id']);
        $this->assertSame($endpointId, $payload['ingress_trunk_endpoint_id']);
        $this->assertSame($runtimeNodeId, $payload['ingress_runtime_node_id']);
        $this->assertArrayNotHasKey('provider-internal-value', $payload);

        $malformed = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('stasis_start')))->normalize((object) [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
        ], [
            'channel_id' => 'ari-inbound-2',
            'application_args' => ['utcp-in-'.$addressId, 'not-a-uuid', $addressId, $endpointId, $runtimeNodeId],
        ]);
        $this->assertArrayNotHasKey('ingress_external_trunk_id', $malformed[0]['payload']);
    }

    public function test_explicit_authentication_failure_retains_real_degraded_runtime_health_semantics(): void
    {
        [, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $normalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('authentication_failed'));

        $observations = $normalizer->normalize((object) [
            'runtime_node_id' => $nodeId,
        ], [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
            'failure_class' => 'authentication_failed',
        ]);

        $this->assertCount(1, $observations);
        $this->assertSame('degraded', $observations[0]['observed_state']);
        $this->assertSame('runtime.connection.observed', $observations[0]['observation_type']);
    }

    public function test_asterisk_bridge_and_channel_events_normalize_to_conference_observations_only_for_owned_runtime_references(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $userId = IdentityIds::new();
        $client = new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class));

        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'ari-normalizer@example.test',
            'normalized_email' => 'ari-normalizer@example.test',
            'display_name' => 'ARI Normalizer User',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'ari-normalizer',
            'display_name' => 'ARI Normalizer',
            'runtime_node_id' => $nodeId,
            'desired_state' => 'open',
            'observed_state' => 'unobserved',
            'configuration_generation' => 7,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'role' => 'participant',
            'desired_state' => 'admitted',
            'observed_state' => 'not_joined',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receipt = (object) [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'connection_epoch_id' => 'epoch-1',
        ];

        $bridgeNormalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('bridge_created'));
        $bridgeObservations = $bridgeNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'occurred_at' => now()->toISOString(),
            'contact' => 'must-not-project',
        ]);
        $this->assertCount(1, $bridgeObservations);
        $this->assertSame('conference', $bridgeObservations[0]['subject_type']);
        $this->assertSame($conferenceId, $bridgeObservations[0]['subject_id']);
        $this->assertSame('ready', $bridgeObservations[0]['observed_state']);
        $this->assertArrayNotHasKey('contact', $bridgeObservations[0]['payload']);

        $foreign = $bridgeNormalizer->normalize($receipt, [
            'bridge_id' => 'foreign-bridge',
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame([], $foreign);

        $participantNormalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('channel_entered_bridge'));
        $participantObservations = $participantNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
            'authorization' => 'must-not-project',
        ]);
        $this->assertCount(1, $participantObservations);
        $this->assertSame('conference_participant', $participantObservations[0]['subject_type']);
        $this->assertSame($participantId, $participantObservations[0]['subject_id']);
        $this->assertSame('joined', $participantObservations[0]['observed_state']);
        $this->assertArrayNotHasKey('authorization', $participantObservations[0]['payload']);

        $localChannelLegObservations = $participantNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId).';2',
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertCount(1, $localChannelLegObservations, 'a Local channel leg suffix must not prevent the participant channel from resolving');
        $this->assertSame($participantId, $localChannelLegObservations[0]['subject_id']);
        $this->assertSame('joined', $localChannelLegObservations[0]['observed_state']);

        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'test-listener');
        $staleJoinedReceipt = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'stale-joined-after-removal', $catalog->eventType('channel_entered_bridge'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 7,
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);

        $leftNormalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('channel_destroyed'));
        $leftObservations = $leftNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame([], $leftObservations, 'runtime channel disappearance must not project left while desired participant state is still admitted');

        DB::table('conference_participants')->where('id', $participantId)->update([
            'desired_state' => 'removed',
            'updated_at' => now(),
        ]);
        $leftObservations = $leftNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame('left', $leftObservations[0]['observed_state']);

        DB::table('conference_participants')->where('id', $participantId)->update([
            'observed_state' => 'left',
            'left_at' => now(),
            'updated_at' => now(),
        ]);
        (new ProjectionService)->apply(DB::table('runtime_event_receipts')->where('id', $staleJoinedReceipt['id'])->first(), $participantObservations);
        $this->assertSame('left', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'), 'projector must reject delayed joined observations after desired participant removal');

        DB::table('conference_participants')->where('id', $participantId)->update([
            'desired_state' => 'admitted',
            'updated_at' => now(),
        ]);
        DB::table('conferences')->where('id', $conferenceId)->update([
            'desired_state' => 'closed',
            'updated_at' => now(),
        ]);
        $staleJoined = $participantNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame([], $staleJoined, 'stale joined evidence must not reopen a closed conference');

        $staleReady = $bridgeNormalizer->normalize($receipt, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame([], $staleReady, 'stale bridge-created evidence must not project ready after desired close');

        DB::table('conferences')->where('id', $conferenceId)->update([
            'observed_state' => 'closed',
            'updated_at' => now(),
        ]);
        $staleReadyReceipt = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'stale-ready-after-close', $catalog->eventType('bridge_created'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 7,
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'occurred_at' => now()->toISOString(),
        ]);
        (new ProjectionService)->apply(DB::table('runtime_event_receipts')->where('id', $staleReadyReceipt['id'])->first(), $bridgeObservations);
        $this->assertSame('closed', DB::table('conferences')->where('id', $conferenceId)->value('observed_state'), 'projector must reject delayed ready observations after desired conference close');
    }

    public function test_participant_projection_reopens_reconciliation_without_losing_linked_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $userId = IdentityIds::new();
        $operationId = str_repeat('c', 32);
        $stateId = str_repeat('d', 32);
        $client = new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class));

        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'ari-projection-wake@example.test',
            'normalized_email' => 'ari-projection-wake@example.test',
            'display_name' => 'ARI Projection Wake User',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'ari-projection-wake',
            'display_name' => 'ARI Projection Wake',
            'runtime_node_id' => $nodeId,
            'desired_state' => 'open',
            'observed_state' => 'ready',
            'configuration_generation' => 7,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'role' => 'participant',
            'desired_state' => 'removed',
            'observed_state' => 'joined',
            'joined_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_operations')->insert([
            'id' => $operationId,
            'tenant_id' => $tenantId,
            'operation_type' => 'conference.participant.remove',
            'aggregate_type' => 'conference_participant',
            'aggregate_id' => $participantId,
            'runtime_node_id' => $nodeId,
            'payload_version' => 1,
            'payload' => json_encode(['conference_id' => $conferenceId, 'participant_id' => $participantId]),
            'status' => 'succeeded',
            'priority' => 100,
            'idempotency_key' => 'projection-wake-operation',
            'correlation_id' => str_repeat('e', 32),
            'request_id' => str_repeat('f', 32),
            'available_at' => now()->subMinute(),
            'completed_at' => now()->subSecond(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSecond(),
        ]);
        DB::table('runtime_reconciliation_states')->insert([
            'id' => $stateId,
            'tenant_id' => $tenantId,
            'target_type' => 'conference_participant',
            'target_id' => $participantId,
            'desired_generation' => 15,
            'status' => 'operation_required',
            'last_operation_id' => $operationId,
            'attempt_count' => 2,
            'next_check_at' => now()->addMinutes(5),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSecond(),
        ]);

        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'test-listener');
        $receipt = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'projection-wake-left', $catalog->eventType('channel_destroyed'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 7,
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);
        $receiptRow = DB::table('runtime_event_receipts')->where('id', $receipt['id'])->first();
        $normalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('channel_destroyed'));
        $observations = $normalizer->normalize($receiptRow, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);

        (new ProjectionService)->apply($receiptRow, $observations);

        $participant = DB::table('conference_participants')->where('id', $participantId)->first();
        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('left', $participant->observed_state);
        $this->assertSame('converged', $state->status, 'fresh observed participant evidence that matches desired state must converge without waiting for periodic polling');
        $this->assertSame($operationId, $state->last_operation_id, 'projection wake-up must preserve the linked terminal operation so duplicate command suppression remains intact');
        $this->assertTrue(now()->addMinutes(4)->lessThanOrEqualTo($state->next_check_at));
    }

    public function test_ari_event_websocket_query_subscribes_to_application_and_runtime_owned_resources(): void
    {
        $catalog = new AsteriskCatalog;
        $client = new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class));
        $method = new ReflectionMethod($client, 'eventWebSocketQuery');
        $method->setAccessible(true);

        parse_str((string) $method->invoke($client, 'utcp-t0-observation'), $query);

        $this->assertSame('utcp-t0-observation', $query['app'] ?? null);
        $this->assertSame('true', $query['subscribeAll'] ?? null);
    }

    public function test_asterisk_runtime_node_reconciler_blocks_incomplete_nodes_and_requests_inspection_for_configured_nodes(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $reconciler = new AsteriskRuntimeNodeReconciler(new AsteriskCatalog);
        $target = (object) ['target_id' => $nodeId, 'last_operation_id' => null];

        $blocked = $reconciler->evaluate($target);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame('asterisk_ari_configuration_incomplete', $blocked->reasonCode);

        $this->configureAriNode($tenantId, $nodeId);
        $required = $reconciler->evaluate($target);
        $this->assertSame('operation_required', $required->status);
        $this->assertSame('runtime.node.inspect', $required->operationType);
        $this->assertSame($nodeId, $required->operationPayload['runtime_node_id']);
    }

    public function test_managed_asterisk_reconciliation_converges_capabilities_and_leaves_external_nodes_unchanged(): void
    {
        config(['asterisk_ari.managed_image' => 'ghcr.io/grimange/utcp-asterisk@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        [$tenantId, $managedNodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $managedNodeId);
        $this->makeManaged($tenantId, $managedNodeId);

        $reconciler = new AsteriskRuntimeNodeReconciler(new AsteriskCatalog, app(RuntimeRegistryService::class));
        $target = (object) ['target_id' => $managedNodeId, 'last_operation_id' => null];
        $required = $reconciler->evaluate($target);
        $this->assertSame('runtime.node.workload.converge', $required->operationType);

        $this->assertSame(
            ['conference.lifecycle', 'conference.participation', 'event.stream', 'runtime.observation'],
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $managedNodeId)->orderBy('capability_key')->pluck('capability_key')->all(),
        );

        [$externalTenantId, $externalNodeId] = $this->runtimeNode();
        $this->configureAriNode($externalTenantId, $externalNodeId);
        $before = DB::table('runtime_node_capabilities')->where('runtime_node_id', $externalNodeId)->orderBy('capability_key')->pluck('capability_key')->all();
        (new AsteriskRuntimeNodeReconciler(new AsteriskCatalog, app(RuntimeRegistryService::class)))->evaluate((object) ['target_id' => $externalNodeId, 'last_operation_id' => null]);
        $this->assertSame($before, DB::table('runtime_node_capabilities')->where('runtime_node_id', $externalNodeId)->orderBy('capability_key')->pluck('capability_key')->all());
    }

    public function test_managed_asterisk_reconciler_requests_infrastructure_workload_convergence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $this->makeManaged($tenantId, $nodeId);
        $reconciler = new AsteriskRuntimeNodeReconciler(new AsteriskCatalog, app(RuntimeRegistryService::class));
        $target = (object) ['target_id' => $nodeId, 'last_operation_id' => null];

        $first = $reconciler->evaluate($target);
        $this->assertSame('operation_required', $first->status);
        $this->assertSame('runtime.node.workload.converge', $first->operationType);
    }

    public function test_managed_workload_drift_is_recovered_by_infrastructure_worker_without_a_convergence_loop(): void
    {
        config(['asterisk_ari.managed_image' => 'registry.example.test/utcp/asterisk@sha256:'.str_repeat('b', 64)]);
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $this->makeManaged($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'labels' => json_encode(['kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-managed-drift-test']]),
        ]);
        $imageId = 'docker-pullable://registry.example.test/utcp/asterisk@sha256:'.str_repeat('b', 64);

        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock) use ($imageId): void {
            $mock->shouldReceive('applyDeployment')->once()->andReturn([]);
            $mock->shouldReceive('listOwnedPods')->once()->andReturn([
                ['status' => ['containerStatuses' => [['name' => 'asterisk', 'imageID' => $imageId]]]],
            ]);
        });

        $reconciliation = app(ReconciliationRepository::class);
        $stateId = $reconciliation->ensureTarget($tenantId, 'runtime_node', $nodeId, 1);
        $reconciler = new AsteriskRuntimeNodeReconciler(new AsteriskCatalog, app(RuntimeRegistryService::class));
        $reconciliationWorker = new ReconciliationWorker(
            $reconciliation,
            new ReconcilerRegistry([$reconciler]),
            new RuntimeOperationRepository,
        );

        $this->assertSame(1, $reconciliationWorker->workOnce('telephony-reconciler', 1));
        $operationId = DB::table('runtime_reconciliation_states')->where('id', $stateId)->value('last_operation_id');
        $this->assertIsString($operationId);
        $this->assertSame('runtime.node.workload.converge', DB::table('runtime_operations')->where('id', $operationId)->value('operation_type'));

        $genericWorker = app(CommandWorker::class);
        $this->assertSame(0, $genericWorker->workOnce('generic-worker', 10, 60, [], ['runtime.node.workload.converge']));
        $this->assertSame('pending', DB::table('runtime_operations')->where('id', $operationId)->value('status'));

        $infrastructureWorker = app(CommandWorker::class);
        $this->assertSame(1, $infrastructureWorker->workOnce('infrastructure-worker', 10, 60, ['runtime.node.workload.converge']));
        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('sha256:'.str_repeat('b', 64), DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_execution_image'));

        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_configuration_version' => DB::table('runtime_nodes')->where('id', $nodeId)->value('configuration_version'),
        ]);
        $completedOperation = DB::table('runtime_operations')->where('id', $operationId)->first();
        $this->assertSame('runtime.node.workload.converge', $completedOperation->operation_type);
        $this->assertSame('succeeded', $completedOperation->status);
        $completedNode = DB::table('runtime_nodes')->where('id', $nodeId)->first();
        $this->assertSame($completedNode->desired_execution_image, 'registry.example.test/utcp/asterisk@sha256:'.str_repeat('b', 64));
        $this->assertSame($completedNode->observed_execution_image, 'sha256:'.str_repeat('b', 64));
        $next = $reconciler->evaluate((object) ['target_id' => $nodeId, 'last_operation_id' => $operationId]);
        $this->assertSame('operation_required', $next->status);
        $this->assertSame('runtime.node.inspect', $next->operationType, 'a successful workload convergence must advance to readiness observation, not emit another convergence');
        $this->assertSame(1, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->where('operation_type', 'runtime.node.workload.converge')->count());
    }

    public function test_taking_over_a_lease_closes_any_epoch_left_open_by_a_superseded_owner(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $catalog = new AsteriskCatalog;
        $receipts = new RuntimeEventReceiptRepository;

        $staleEpochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'dead-pod:asterisk-ari-events:1');
        $currentEpochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'new-pod:asterisk-ari-events:1');

        $closed = $receipts->closeStaleEpochs($nodeId, 'new-pod:asterisk-ari-events:1');

        $this->assertSame(1, $closed);
        $this->assertSame('expired', DB::table('runtime_event_connection_epochs')->where('id', $staleEpochId)->value('status'));
        $this->assertSame('open', DB::table('runtime_event_connection_epochs')->where('id', $currentEpochId)->value('status'));
        $this->assertSame(1, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->count());
    }

    public function test_reconnect_backoff_doubles_on_repeated_failure_and_caps_at_max(): void
    {
        $backoff = new AsteriskAriReconnectBackoff;
        $now = 1_000_000.0;

        $this->assertTrue($backoff->shouldAttempt('node-1', $now));

        $backoff->recordFailure('node-1', $now, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(1000, $backoff->currentDelayMs('node-1'));
        $this->assertFalse($backoff->shouldAttempt('node-1', $now));
        $this->assertFalse($backoff->shouldAttempt('node-1', $now + 0.5));
        $this->assertTrue($backoff->shouldAttempt('node-1', $now + 1.0));

        $backoff->recordFailure('node-1', $now + 1.0, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(2000, $backoff->currentDelayMs('node-1'));

        $backoff->recordFailure('node-1', $now + 3.0, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(4000, $backoff->currentDelayMs('node-1'));

        $backoff->recordFailure('node-1', $now + 7.0, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(8000, $backoff->currentDelayMs('node-1'));

        $backoff->recordFailure('node-1', $now + 15.0, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(8000, $backoff->currentDelayMs('node-1'), 'delay must not exceed the configured maximum');
    }

    public function test_reconnect_backoff_resets_immediately_on_credential_or_configuration_change(): void
    {
        $backoff = new AsteriskAriReconnectBackoff;
        $now = 2_000_000.0;

        $backoff->recordFailure('node-1', $now, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $backoff->recordFailure('node-1', $now + 1.0, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertSame(2000, $backoff->currentDelayMs('node-1'));

        $backoff->recordFailure('node-1', $now + 1.1, 1000, 8000, credentialVersion: 2, configurationVersion: 1);
        $this->assertSame(1000, $backoff->currentDelayMs('node-1'), 'a new credential generation must reset the backoff to the minimum delay');
        $this->assertFalse($backoff->shouldAttempt('node-1', $now + 1.1));
        $this->assertTrue($backoff->shouldAttempt('node-1', $now + 2.1), 'the reset minimum delay must be honored, not the prior doubled delay');
    }

    public function test_reconnect_backoff_clear_allows_immediate_retry(): void
    {
        $backoff = new AsteriskAriReconnectBackoff;
        $now = 3_000_000.0;

        $backoff->recordFailure('node-1', $now, 1000, 8000, credentialVersion: 1, configurationVersion: 1);
        $this->assertFalse($backoff->shouldAttempt('node-1', $now));

        $backoff->clear('node-1');
        $this->assertTrue($backoff->shouldAttempt('node-1', $now));
        $this->assertNull($backoff->currentDelayMs('node-1'));
    }

    public function test_listener_tears_down_connection_when_stasis_application_registration_is_lost(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);

        $catalog = new AsteriskCatalog;
        $client = new class($catalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public bool $webSocketClosed = false;

            public function inspect(string $tenantId, string $runtimeNodeId): array
            {
                return [
                    'runtime_node_id' => $runtimeNodeId,
                    'asterisk_version' => '20.20.1',
                    'system_name' => 'test',
                    'configuration_generation' => 1,
                    'auth_generation' => 1,
                ];
            }

            public function stasisApplicationRegistered(string $tenantId, string $runtimeNodeId): bool
            {
                return false;
            }

            public function closeWebSocket(mixed $stream): void
            {
                $this->webSocketClosed = true;
            }
        };

        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $lease = $leases->claim($tenantId, $nodeId, $catalog->listenerKind(), 'listener-subscription', 45);
        $this->assertNotNull($lease);
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'listener-subscription');

        $listener = new AsteriskAriEventListener(
            $catalog,
            $client,
            app(AsteriskAriProfileService::class),
            $leases,
            $receipts,
            new ReconciliationRepository,
        );
        $connections = new ReflectionProperty($listener, 'connections');
        $connections->setValue($listener, [
            $nodeId => [
                'tenant_id' => $tenantId,
                'stream' => fopen('php://temp', 'rb'),
                'lease_id' => (string) $lease->id,
                'fencing_token' => (string) $lease->fencing_token,
                'epoch_id' => $epochId,
                'configuration_version' => 1,
                'credential_version' => 1,
                'worker_id' => 'listener-subscription',
                'heartbeat_interval_ms' => 30000,
                'next_health_check_at' => microtime(true) - 1,
                'next_ping_at' => microtime(true) + 3600,
                'last_pong_at' => microtime(true),
                'last_signal_persisted_at' => 0.0,
                'missed_pong_since' => null,
            ],
        ]);

        $listener->workOnce('listener-subscription');

        $this->assertSame([], $connections->getValue($listener), 'a lost Stasis application registration must tear down the connection so it reconnects');
        $this->assertTrue($client->webSocketClosed, 'the stale WebSocket must be closed');
        $this->assertSame('released', DB::table('runtime_listener_leases')->where('id', (string) $lease->id)->value('status'));
        $this->assertSame(1, DB::table('runtime_event_receipts')
            ->where('runtime_node_id', $nodeId)
            ->where('external_event_key', 'like', 'failure:%:ari_stasis_subscription_lost')
            ->count(), 'the subscription loss must be recorded as a failure receipt');
    }

    public function test_same_owner_reconnect_supersedes_previous_open_epoch_without_closing_other_owner_epoch(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $receipts = new RuntimeEventReceiptRepository;

        $previous = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'listener-a');
        $staleOwner = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'listener-b');

        $this->assertSame(1, $receipts->closeSupersededOwnerEpochs($nodeId, 'listener-a'));
        $successor = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'listener-a');

        $this->assertSame('expired', DB::table('runtime_event_connection_epochs')->where('id', $previous)->value('status'));
        $this->assertSame('open', DB::table('runtime_event_connection_epochs')->where('id', $successor)->value('status'));
        $this->assertSame('open', DB::table('runtime_event_connection_epochs')->where('id', $staleOwner)->value('status'), 'same-owner reconnect must not close a different owner epoch');
        $this->assertSame(1, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('owner', 'listener-a')->where('status', 'open')->count());
    }

    public function test_listener_closes_epoch_when_inspection_fails_after_opening_connection(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $stream = fopen('php://temp', 'rb+');
        $catalog = new AsteriskCatalog;
        $client = new class($catalog, app(AsteriskAriProfileService::class), $stream) extends AsteriskAriClient
        {
            public bool $webSocketClosed = false;

            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, private mixed $stream)
            {
                parent::__construct($catalog, $profiles);
            }

            public function openWebSocket(string $tenantId, string $runtimeNodeId)
            {
                unset($tenantId, $runtimeNodeId);

                return $this->stream;
            }

            public function inspect(string $tenantId, string $runtimeNodeId): array
            {
                unset($tenantId, $runtimeNodeId);

                throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_test_inspect_failed', 'inspection failed after epoch open', true);
            }

            public function closeWebSocket(mixed $stream): void
            {
                unset($stream);
                $this->webSocketClosed = true;
            }
        };

        $listener = new AsteriskAriEventListener(
            $catalog,
            $client,
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            new RuntimeEventReceiptRepository,
            new ReconciliationRepository,
        );

        $listener->workOnce('listener-inspect-fail');

        $this->assertTrue($client->webSocketClosed);
        $this->assertSame(0, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->count());
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'failure:%:ari_test_inspect_failed')->count());
    }

    public function test_raw_websocket_client_handles_ping_pong_close_and_text_frames_without_forwarding_control_frames(): void
    {
        $catalog = new AsteriskCatalog;
        $client = new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class));
        [$local, $remote] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsResource($local);
        $this->assertIsResource($remote);

        $client->sendPing($local);
        $ping = $this->readClientFrame($remote);
        $this->assertSame(0x9, $ping['opcode']);
        $this->assertSame('utcp', $ping['payload']);

        fwrite($remote, $this->serverFrame(0xA, 'ok'));
        $this->assertSame(['type' => 'pong'], $client->readWebSocketMessage($local));

        fwrite($remote, $this->serverFrame(0x9, 'server-ping'));
        $this->assertSame(['type' => 'ping'], $client->readWebSocketMessage($local));
        $pong = $this->readClientFrame($remote);
        $this->assertSame(0xA, $pong['opcode']);
        $this->assertSame('server-ping', $pong['payload']);

        fwrite($remote, $this->serverFrame(0x1, json_encode([
            'type' => 'StasisStart',
            'timestamp' => '2026-07-21T00:00:00Z',
            'args' => ['conf-67d30af9-1234-4abc-8def-0123456789ab'],
        ], JSON_THROW_ON_ERROR)));
        $message = $client->readWebSocketMessage($local);
        $this->assertSame('event', $message['type']);
        $this->assertSame('StasisStart', $message['event']['type']);
        $this->assertSame(['conf-67d30af9-1234-4abc-8def-0123456789ab'], $message['event']['args']);

        fwrite($remote, $this->serverFrame(0x8, ''));
        $this->expectException(AsteriskAriException::class);
        $this->expectExceptionMessage('ARI event WebSocket closed.');
        $client->readWebSocketMessage($local);
    }

    public function test_listener_ping_pong_keeps_quiet_stream_healthy_without_event_receipts(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'ready']);
        config()->set('asterisk_ari.max_events_per_cycle', 3);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([
            (string) get_resource_id($stream) => [
                ['_ws_type' => 'pong'],
            ],
        ]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-quiet', $stream);
        $this->setListenerConnectionTimes($listener, $nodeId, ['next_ping_at' => microtime(true) - 1]);

        $listener->workOnce('listener-quiet');

        $this->assertSame(1, $client->pingCount);
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(0, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertNotNull(DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->value('last_authoritative_signal_at'));
    }

    public function test_pong_deadline_degrades_events_wakes_reinspection_and_pong_recovery_restores_eligibility(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $conferenceId = IdentityIds::new();
        $bindingId = IdentityIds::new();
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'events-degraded-'.substr($conferenceId, 0, 8),
            'display_name' => 'Events Degraded',
            'runtime_node_id' => $nodeId,
            'desired_state' => 'open',
            'observed_state' => 'ready',
            'configuration_generation' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => $bindingId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'ready']);
        config()->set('asterisk_ari.pong_deadline_ms', 1000);
        config()->set('asterisk_ari.events_degraded_grace_ms', 1000);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([(string) get_resource_id($stream) => []]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $lease = $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-degrade', $stream);
        $old = microtime(true) - 10;
        $this->setListenerConnectionTimes($listener, $nodeId, [
            'next_ping_at' => microtime(true) + 3600,
            'last_pong_at' => $old,
            'missed_pong_since' => $old,
        ]);

        $listener->workOnce('listener-degrade');

        $this->assertSame('events_degraded', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame('released', DB::table('runtime_listener_leases')->where('id', (string) $lease->id)->value('status'));
        $this->assertSame('waiting', DB::table('runtime_reconciliation_states')->where('target_type', 'conference')->where('target_id', $conferenceId)->value('status'));
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_degraded')->count());
        $this->assertSame(0, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->where('operation_type', 'runtime.node.runtime.fence')->count());
        $this->assertContains($nodeId, $this->eligibleNodeIds(), 'events-degraded nodes must remain listener-eligible for automatic reconnect');
        $this->assertNotContains($nodeId, $this->placementEligibleNodeIds($tenantId), 'events-degraded nodes must remain ineligible for new placement');

        $recoveryStream = fopen('php://temp', 'rb');
        $client = $this->queuedEventClient([
            (string) get_resource_id($recoveryStream) => [
                ['_ws_type' => 'pong'],
                ['_ws_type' => 'pong'],
            ],
        ]);
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), new RuntimeListenerLeaseRepository, new RuntimeEventReceiptRepository, new ReconciliationRepository);
        $this->attachListenerConnection($listener, new RuntimeListenerLeaseRepository, new RuntimeEventReceiptRepository, $tenantId, $nodeId, 'listener-recovery', $recoveryStream);

        $listener->workOnce('listener-recovery');

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertContains($nodeId, $this->placementEligibleNodeIds($tenantId), 'positive pong recovery must restore placement eligibility');
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_recovered')->count());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_degraded')->count());
    }

    public function test_reconnect_from_events_degraded_emits_one_recovered_event_and_restores_state(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'events_degraded']);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([], openStreamsByNode: [$nodeId => $stream]);
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), new RuntimeListenerLeaseRepository, new RuntimeEventReceiptRepository, new ReconciliationRepository);

        $listener->workOnce('listener-reconnect');
        app(EventNormalizerWorker::class)->workOnce('listener-reconnect-normalizer', 10);

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(1, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->count());
        $this->assertContains($nodeId, $this->placementEligibleNodeIds($tenantId), 'connection-opened recovery must restore placement eligibility');
        $this->assertStringContainsString('asterisk_ari_events_degraded_nodes{} 0', (string) $this->get('/api/metrics')->assertOk()->getContent());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_recovered')->count());
        $recoveredPayload = json_decode((string) DB::table('control_plane_outbox_messages')
            ->where('aggregate_id', $nodeId)
            ->where('event_type', 'runtime_node.event_listener_recovered')
            ->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'connection:opened:%')->value('id'),
            $recoveredPayload['source_event_id'] ?? null,
        );
    }

    public function test_repeated_connection_opened_recovery_does_not_duplicate_recovered_event(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'events_degraded']);
        $catalog = new AsteriskCatalog;

        $firstStream = fopen('php://temp', 'rb');
        $listener = new AsteriskAriEventListener(
            $catalog,
            $this->queuedEventClient([], openStreamsByNode: [$nodeId => $firstStream]),
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            new RuntimeEventReceiptRepository,
            new ReconciliationRepository,
        );
        $listener->workOnce('listener-reconnect-a');
        app(EventNormalizerWorker::class)->workOnce('listener-reconnect-normalizer-a', 10);

        DB::table('runtime_listener_leases')->update(['status' => 'released', 'released_at' => now()]);
        DB::table('runtime_event_connection_epochs')->update(['status' => 'closed', 'closed_at' => now()]);

        $secondStream = fopen('php://temp', 'rb');
        $listener = new AsteriskAriEventListener(
            $catalog,
            $this->queuedEventClient([], openStreamsByNode: [$nodeId => $secondStream]),
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            new RuntimeEventReceiptRepository,
            new ReconciliationRepository,
        );
        $listener->workOnce('listener-reconnect-b');
        app(EventNormalizerWorker::class)->workOnce('listener-reconnect-normalizer-b', 10);

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(1, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->count());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_recovered')->count());
    }

    public function test_initial_connection_from_non_degraded_state_does_not_emit_recovered_event(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([], openStreamsByNode: [$nodeId => $stream]);
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), new RuntimeListenerLeaseRepository, new RuntimeEventReceiptRepository, new ReconciliationRepository);

        $listener->workOnce('listener-initial');
        app(EventNormalizerWorker::class)->workOnce('listener-initial-normalizer', 10);

        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(1, DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $nodeId)->where('status', 'open')->count());
        $this->assertSame(0, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.event_listener_recovered')->count());
    }

    public function test_listener_drains_multiple_queued_frames_in_one_cycle_and_wakes_recovery(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        [, $participantId] = $this->conferenceFixture($tenantId, $nodeId);
        config()->set('asterisk_ari.max_events_per_cycle', 10);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $eventsByStream = [
            (string) get_resource_id($stream) => [
                $this->ariEvent('BridgeCreated', 'first'),
                $this->ariEvent('ChannelDestroyed', 'second', channelId: (new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class)))->participantChannelId($participantId)),
                $this->ariEvent('StasisEnd', 'third'),
            ],
        ];
        $client = $this->queuedEventClient($eventsByStream);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-drain', $stream);

        $listener->workOnce('listener-drain');

        $received = DB::table('runtime_event_receipts')
            ->where('runtime_node_id', $nodeId)
            ->where('external_event_key', 'like', 'ari:%')
            ->orderBy('occurred_at')
            ->pluck('event_type')
            ->all();

        $this->assertSame([
            $catalog->eventType('bridge_created'),
            $catalog->eventType('channel_destroyed'),
            $catalog->eventType('stasis_end'),
        ], $received);
        $this->assertSame('waiting', DB::table('runtime_reconciliation_states')->where('target_id', $participantId)->value('status'));
        $this->assertSame(4, $client->readAttempts, 'three frames plus the empty queue check must be attempted without an outer sleep');
    }

    public function test_listener_stops_draining_immediately_on_empty_queue(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        config()->set('asterisk_ari.max_events_per_cycle', 10);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([(string) get_resource_id($stream) => []]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-empty', $stream);

        $listener->workOnce('listener-empty');

        $this->assertSame(1, $client->readAttempts);
        $this->assertSame(0, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'ari:%')->count());
    }

    public function test_listener_enforces_configured_frame_cap_and_retains_remaining_frames_for_next_cycle(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        config()->set('asterisk_ari.max_events_per_cycle', 2);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([
            (string) get_resource_id($stream) => [
                $this->ariEvent('BridgeCreated', 'one'),
                $this->ariEvent('BridgeDestroyed', 'two'),
                $this->ariEvent('StasisEnd', 'three'),
            ],
        ]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-cap', $stream);

        $listener->workOnce('listener-cap');
        $this->assertSame(2, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertCount(1, $client->eventsByStream[(string) get_resource_id($stream)]);

        $listener->workOnce('listener-cap');
        $this->assertSame(3, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertSame([], $client->eventsByStream[(string) get_resource_id($stream)]);
    }

    public function test_listener_exception_during_drain_uses_existing_teardown_and_retry_path(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        config()->set('asterisk_ari.max_events_per_cycle', 10);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([
            (string) get_resource_id($stream) => [
                $this->ariEvent('BridgeCreated', 'first'),
                $this->ariEvent('BridgeDestroyed', 'second'),
                $this->ariEvent('StasisEnd', 'not-processed'),
            ],
        ], throwOnAttempt: 3);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $lease = $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-exception', $stream);
        $connections = new ReflectionProperty($listener, 'connections');

        $listener->workOnce('listener-exception');

        $this->assertSame(2, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('external_event_key', 'like', 'failure:%:ari_test_drain_failure')->count());
        $this->assertSame([], $connections->getValue($listener));
        $this->assertTrue($client->webSocketClosed);
        $this->assertSame('released', DB::table('runtime_listener_leases')->where('id', (string) $lease->id)->value('status'));
    }

    public function test_listener_applies_frame_cap_per_connection_without_starving_the_next_connection(): void
    {
        [$tenantA, $nodeA] = $this->runtimeNode();
        [$tenantB, $nodeB] = $this->runtimeNode();
        $this->configureAriNode($tenantA, $nodeA);
        $this->configureAriNode($tenantB, $nodeB);
        config()->set('asterisk_ari.max_events_per_cycle', 2);

        $streamA = fopen('php://temp', 'rb');
        $streamB = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([
            (string) get_resource_id($streamA) => [
                $this->ariEvent('BridgeCreated', 'a-one'),
                $this->ariEvent('BridgeDestroyed', 'a-two'),
                $this->ariEvent('StasisEnd', 'a-three'),
            ],
            (string) get_resource_id($streamB) => [
                $this->ariEvent('BridgeCreated', 'b-one'),
            ],
        ]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantA, $nodeA, 'listener-fair', $streamA);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantB, $nodeB, 'listener-fair', $streamB);

        $listener->workOnce('listener-fair');

        $this->assertSame(2, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeA)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeB)->where('external_event_key', 'like', 'ari:%')->count());
        $this->assertCount(1, $client->eventsByStream[(string) get_resource_id($streamA)]);
    }

    public function test_listener_rejects_invalid_frame_cap_configuration(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        config()->set('asterisk_ari.max_events_per_cycle', 0);

        $stream = fopen('php://temp', 'rb');
        $catalog = new AsteriskCatalog;
        $client = $this->queuedEventClient([(string) get_resource_id($stream) => []]);
        $leases = new RuntimeListenerLeaseRepository;
        $receipts = new RuntimeEventReceiptRepository;
        $listener = new AsteriskAriEventListener($catalog, $client, app(AsteriskAriProfileService::class), $leases, $receipts, new ReconciliationRepository);
        $this->attachListenerConnection($listener, $leases, $receipts, $tenantId, $nodeId, 'listener-invalid-config', $stream);

        $this->expectException(RuntimeException::class);
        $listener->workOnce('listener-invalid-config');
    }

    public function test_asterisk_runtime_payloads_do_not_use_sensitive_key_names(): void
    {
        $payload = PayloadSafety::assertSafe([
            'adapter_key' => 'asterisk-ari',
            'operation_type' => 'runtime.node.inspect',
            'configuration_generation' => 2,
            'asterisk_version_observed' => true,
            'auth_generation' => 3,
        ]);

        $this->assertSame(3, $payload['auth_generation']);
        $this->assertArrayNotHasKey('credential_version', $payload);
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function conferenceFenceContext(bool $withParticipant = false): array
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $conferenceId = IdentityIds::new();
        $bindingId = IdentityIds::new();
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'asterisk-fence-'.substr($conferenceId, 0, 8),
            'display_name' => 'Asterisk Fence',
            'runtime_node_id' => $nodeId,
            'desired_state' => 'open',
            'observed_state' => 'ready',
            'configuration_generation' => 7,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => $bindingId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withParticipant) {
            $userId = IdentityIds::new();
            $sessionId = IdentityIds::new();
            DB::table('users')->insert([
                'id' => $userId,
                'email' => 'asterisk-fence-'.substr($userId, 0, 8).'@utcp.local.test',
                'normalized_email' => 'asterisk-fence-'.substr($userId, 0, 8).'@utcp.local.test',
                'display_name' => 'Asterisk Fence User',
                'password' => 'not-used',
                'status' => 'active',
                'password_change_required' => false,
                'session_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('telephony_sessions')->insert([
                'id' => $sessionId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'active',
                'issued_at' => now(),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('conference_participants')->insert([
                'id' => IdentityIds::new(),
                'tenant_id' => $tenantId,
                'conference_id' => $conferenceId,
                'telephony_session_id' => $sessionId,
                'user_id' => $userId,
                'desired_state' => 'admitted',
                'observed_state' => 'joined',
                'role' => 'participant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$tenantId, $nodeId, $conferenceId, $bindingId];
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function participantCleanupContext(string $conferenceDesiredState, string $participantDesiredState, string $conferenceObservedState, string $participantObservedState): array
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $this->configureAriNode($tenantId, $nodeId);
        $userId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'cleanup-'.substr($userId, 0, 8).'@utcp.local.test',
            'normalized_email' => 'cleanup-'.substr($userId, 0, 8).'@utcp.local.test',
            'display_name' => 'Cleanup User',
            'password' => 'not-used',
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'ended',
            'issued_at' => now()->subMinutes(5),
            'expires_at' => now()->addHour(),
            'ended_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'cleanup-'.substr($conferenceId, 0, 8),
            'display_name' => 'Cleanup Conference',
            'runtime_node_id' => $nodeId,
            'desired_state' => $conferenceDesiredState,
            'observed_state' => $conferenceObservedState,
            'configuration_generation' => 1,
            'observed_generation' => $conferenceObservedState === 'closed' ? 1 : null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'desired_state' => $participantDesiredState,
            'observed_state' => $participantObservedState,
            'role' => 'participant',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        return [$tenantId, $nodeId, $conferenceId, $participantId];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantRemoveOperation(string $tenantId, string $nodeId, string $conferenceId, string $participantId, array $payload = []): array
    {
        return [
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'operation_type' => 'conference.participant.remove',
            'aggregate_type' => 'conference_participant',
            'aggregate_id' => $participantId,
            'runtime_node_id' => $nodeId,
            'payload' => array_merge([
                'conference_id' => $conferenceId,
                'participant_id' => $participantId,
                'telephony_session_id' => (string) DB::table('conference_participants')->where('id', $participantId)->value('telephony_session_id'),
                'runtime_node_id' => $nodeId,
                'configuration_generation' => 1,
                'desired_state' => 'removed',
            ], $payload),
        ];
    }

    /**
     * @return array{0:AsteriskRuntimeAdapter,1:AsteriskAriClient}
     */
    private function adapterWithRuntimeSummary(callable $summary): array
    {
        $catalog = new AsteriskCatalog;
        $client = new class($catalog, app(AsteriskAriProfileService::class), $summary) extends AsteriskAriClient
        {
            /**
             * @var list<array{conference_id:string,participant_id:string}>
             */
            public array $calls = [];

            private \Closure $summary;

            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, callable $summary)
            {
                parent::__construct($catalog, $profiles);
                $this->summary = $summary(...);
            }

            public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
            {
                unset($tenantId, $runtimeNodeId);
                $this->calls[] = ['conference_id' => $conferenceId, 'participant_id' => $participantId ?? ''];

                return ($this->summary)($participantId ?? '');
            }
        };

        return [new AsteriskRuntimeAdapter($catalog, $client), $client];
    }

    /**
     * @param  list<array{status?:int,body?:string,throw?:AsteriskAriException}>  $responses
     */
    private function ariClientWithResponses(array $responses): AsteriskAriClient
    {
        return new class(new AsteriskCatalog, app(AsteriskAriProfileService::class), $responses) extends AsteriskAriClient
        {
            /**
             * @var list<array{method:string,resource:string,query?:array<string,string>,body?:array<string,mixed>,timeout_ms:int,accepted_statuses:list<int>}>
             */
            public array $requests = [];

            /**
             * @param  list<array{status?:int,body?:string,throw?:AsteriskAriException}>  $responses
             */
            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, private array $responses)
            {
                parent::__construct($catalog, $profiles);
            }

            protected function ariRequest(string $runtimeNodeId, string $method, string $resource, array $query, int $timeoutMs, array $acceptedStatuses, ?array $body = null): array
            {
                unset($runtimeNodeId);
                $this->requests[] = [
                    'method' => $method,
                    'resource' => $resource,
                    ...($query === [] ? [] : ['query' => $query]),
                    ...($body === null ? [] : ['body' => $body]),
                    'timeout_ms' => $timeoutMs,
                    'accepted_statuses' => $acceptedStatuses,
                ];

                if ($this->responses === []) {
                    throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_test_response_missing', 'ARI fake response was missing.', true);
                }

                $next = array_shift($this->responses);
                if (($next['throw'] ?? null) instanceof AsteriskAriException) {
                    throw $next['throw'];
                }

                $status = (int) ($next['status'] ?? 200);
                $response = ['status' => $status, 'body' => (string) ($next['body'] ?? '')];
                if (in_array($status, $acceptedStatuses, true)) {
                    return $response;
                }
                if ($status === 401) {
                    throw new AsteriskAriException(FailureClass::AuthenticationFailed, 'ari_authentication_failed', 'ARI authentication failed.');
                }
                if ($status === 403) {
                    throw new AsteriskAriException(FailureClass::AuthorizationFailed, 'ari_authorization_failed', 'ARI authorization failed.');
                }
                if ($status === 404) {
                    throw new AsteriskAriException(FailureClass::Conflict, 'ari_resource_not_found', 'ARI resource was not found.');
                }
                if ($status === 409 || $status === 422) {
                    throw new AsteriskAriException(FailureClass::Conflict, 'ari_resource_conflict', 'ARI resource conflict.');
                }

                throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_unavailable', 'ARI HTTP request did not return success.', true);
            }
        };
    }

    private function ariClientWithTransportCapture(): AsteriskAriClient
    {
        return new class(new AsteriskCatalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public array $transportRequests = [];

            public function requestAri(string $runtimeNodeId, string $method, string $resource, array $query, int $timeoutMs, array $acceptedStatuses, ?array $body = null): array
            {
                return $this->ariRequest($runtimeNodeId, $method, $resource, $query, $timeoutMs, $acceptedStatuses, $body);
            }

            protected function request(string $method, string $url, array $headers, int $timeoutMs, ?string $body = null): array
            {
                $this->transportRequests[] = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => implode("\r\n", $headers),
                    'timeout_ms' => $timeoutMs,
                    'body' => $body,
                ];

                return ['status' => $method === 'GET' ? 200 : 201, 'body' => ''];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fenceOperation(string $tenantId, string $nodeId, string $conferenceId, string $bindingId): array
    {
        return [
            'id' => 'operation-fence-1',
            'tenant_id' => $tenantId,
            'operation_type' => 'runtime.node.verify_conference_absent',
            'aggregate_type' => 'conference',
            'aggregate_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'payload_version' => 1,
            'payload' => [
                'conference_id' => $conferenceId,
                'former_runtime_binding_id' => $bindingId,
                'former_runtime_node_id' => $nodeId,
                'runtime_node_id' => $nodeId,
                'configuration_generation' => 7,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function eligibleNodeIds(int $batchSize = 20): array
    {
        $listener = new AsteriskAriEventListener(
            new AsteriskCatalog,
            new AsteriskAriClient(new AsteriskCatalog, app(AsteriskAriProfileService::class)),
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            new RuntimeEventReceiptRepository,
            new ReconciliationRepository,
        );
        $method = new ReflectionMethod($listener, 'eligibleNodes');
        $method->setAccessible(true);

        return array_map(
            static fn (object $node): string => (string) $node->id,
            $method->invoke($listener, $batchSize),
        );
    }

    /**
     * @return list<string>
     */
    private function placementEligibleNodeIds(string $tenantId): array
    {
        return DB::table('runtime_nodes')
            ->where('tenant_id', $tenantId)
            ->where('desired_state', 'active')
            ->where('observed_state', 'ready')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('runtime_node_capabilities')
                    ->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_capabilities.capability_key', 'conference.lifecycle');
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('runtime_node_capabilities')
                    ->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_capabilities.capability_key', 'conference.participation');
            })
            ->orderBy('runtime_family')
            ->orderBy('adapter_key')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function restoreAuthority(
        string $tenantId,
        string $nodeId,
        int $configurationVersion,
        OperationStatus $status = OperationStatus::Pending,
        string $operationType = 'runtime.node.restore',
        ?string $payloadTenantId = null,
        ?string $payloadRuntimeNodeId = null,
        ?string $requestedDesiredState = 'active',
        ?string $sourceFenceOperationId = 'source-fence-operation',
    ): string {
        $payload = [
            'tenant_id' => $payloadTenantId ?? $tenantId,
            'runtime_node_id' => $payloadRuntimeNodeId ?? $nodeId,
            'requested_desired_state' => $requestedDesiredState,
            'source_fence_generation' => 39,
            'workload_namespace' => 'utcp-runtime',
            'deployment' => 'asterisk-ari-restore-listener',
            'target_replicas' => 1,
            'expected_runtime_node_configuration_version' => $configurationVersion,
        ];
        if ($sourceFenceOperationId !== null) {
            $payload['source_fence_operation_id'] = $sourceFenceOperationId;
        }

        $operationId = app(RuntimeOperationRepository::class)->create(
            $operationType,
            'runtime_node',
            $nodeId,
            $payload,
            ExecutionContext::system(tenantId: $tenantId, reason: 'restore listener eligibility test'),
            runtimeNodeId: $nodeId,
        );

        if ($status !== OperationStatus::Pending) {
            DB::table('runtime_operations')->where('id', $operationId)->update([
                'status' => $status->value,
                'updated_at' => now(),
            ]);
        }

        return $operationId;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNode(): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'asterisk-tenant-'.substr($tenantId, 0, 8),
            'display_name' => 'Asterisk Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Asterisk ARI',
            'slug' => 'asterisk-ari-'.substr($nodeId, 0, 8),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'unobserved',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $nodeId];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNodeForTenant(string $tenantId): array
    {
        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Asterisk ARI Sibling',
            'slug' => 'asterisk-ari-sibling-'.substr($nodeId, 0, 8),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'unobserved',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $nodeId];
    }

    private function configureAriNode(string $tenantId, string $nodeId): void
    {
        foreach ([
            ['control', 'http', '/ari'],
            ['events', 'ws', '/ari/events'],
        ] as [$purpose, $transport, $path]) {
            DB::table('runtime_node_endpoints')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'purpose' => $purpose,
                'transport' => $transport,
                'host' => 'asterisk-ari.utcp-runtime.svc.cluster.local',
                'port' => 8088,
                'path' => $path,
                'tls_mode' => 'disabled',
                'priority' => 100,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('runtime_node_credentials')->insert([
            'id' => IdentityIds::new(),
            'runtime_node_id' => $nodeId,
            'credential_type' => 'ari-basic',
            'identifier' => 'utcp_ari',
            'encrypted_secret' => Crypt::encryptString('secret'),
            'secret_fingerprint' => hash('sha256', $tenantId.':secret'),
            'status' => 'active',
            'version' => 1,
            'rotated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['runtime.observation', 'event.stream', 'conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('asterisk_ari_profiles')->insert([
            'runtime_node_id' => $nodeId,
            'configuration_version' => 1,
            'application_name' => 'utcp',
            'connect_timeout_ms' => 5000,
            'request_timeout_ms' => 4000,
            'websocket_handshake_timeout_ms' => 10000,
            'heartbeat_interval_ms' => 30000,
            'reconnect_min_delay_ms' => 1000,
            'reconnect_max_delay_ms' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeManaged(string $tenantId, string $nodeId): void
    {
        $targetId = IdentityIds::new();
        DB::table('deployment_targets')->insert(['id' => $targetId, 'tenant_id' => $tenantId, 'name' => 'Local Kubernetes', 'slug' => 'local-kubernetes', 'kind' => 'local_kubernetes', 'configuration' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_provisioning_requests')->insert([
            'id' => IdentityIds::new(), 'tenant_id' => $tenantId, 'deployment_target_id' => $targetId, 'runtime_node_id' => $nodeId,
            'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'requested_name' => 'Asterisk ARI', 'requested_slug' => 'asterisk-ari',
            'idempotency_key' => 'managed-'.$nodeId, 'request_fingerprint' => hash('sha256', $nodeId), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $eventsByStream
     * @param  array<string, mixed>  $openStreamsByNode
     */
    private function queuedEventClient(array $eventsByStream, ?int $throwOnAttempt = null, array $openStreamsByNode = []): AsteriskAriClient
    {
        return new class(new AsteriskCatalog, app(AsteriskAriProfileService::class), $eventsByStream, $throwOnAttempt, $openStreamsByNode) extends AsteriskAriClient
        {
            /** @var array<string, list<array<string, mixed>>> */
            public array $eventsByStream;

            public int $readAttempts = 0;

            public int $pingCount = 0;

            public bool $webSocketClosed = false;

            /**
             * @param  array<string, list<array<string, mixed>>>  $eventsByStream
             * @param  array<string, mixed>  $openStreamsByNode
             */
            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, array $eventsByStream, private readonly ?int $throwOnAttempt, private readonly array $openStreamsByNode)
            {
                parent::__construct($catalog, $profiles);
                $this->eventsByStream = $eventsByStream;
            }

            public function openWebSocket(string $tenantId, string $runtimeNodeId)
            {
                unset($tenantId);

                return $this->openStreamsByNode[$runtimeNodeId] ?? fopen('php://temp', 'rb');
            }

            public function inspect(string $tenantId, string $runtimeNodeId): array
            {
                unset($tenantId, $runtimeNodeId);

                return ['asterisk_version' => '20.0.0', 'auth_generation' => 1];
            }

            public function readEvent(mixed $stream): ?array
            {
                $message = $this->readWebSocketMessage($stream);

                return ($message['type'] ?? null) === 'event' ? $message['event'] : null;
            }

            public function readWebSocketMessage(mixed $stream): array
            {
                $this->readAttempts++;
                if ($this->throwOnAttempt !== null && $this->readAttempts === $this->throwOnAttempt) {
                    throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_test_drain_failure', 'test drain failure', true);
                }

                $streamId = is_resource($stream) ? (string) get_resource_id($stream) : '';
                if (! array_key_exists($streamId, $this->eventsByStream)) {
                    return ['type' => 'empty'];
                }

                $next = array_shift($this->eventsByStream[$streamId]);
                if ($next === null) {
                    return ['type' => 'empty'];
                }
                if (isset($next['_ws_type'])) {
                    $messageType = (string) $next['_ws_type'];
                    unset($next['_ws_type']);

                    return $messageType === 'event'
                        ? ['type' => 'event', 'event' => $next]
                        : ['type' => $messageType];
                }

                return ['type' => 'event', 'event' => $next];
            }

            public function sendPing(mixed $stream): void
            {
                unset($stream);
                $this->pingCount++;
            }

            public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
            {
                unset($tenantId, $runtimeNodeId, $conferenceId);

                return $participantId === null
                    ? ['bridge_exists' => false]
                    : ['bridge_exists' => false, 'participant_channel_exists' => false, 'participant_channel_in_bridge' => false];
            }

            public function closeWebSocket(mixed $stream): void
            {
                $this->webSocketClosed = true;
            }
        };
    }

    private function attachListenerConnection(
        AsteriskAriEventListener $listener,
        RuntimeListenerLeaseRepository $leases,
        RuntimeEventReceiptRepository $receipts,
        string $tenantId,
        string $nodeId,
        string $workerId,
        mixed $stream,
    ): object {
        $catalog = new AsteriskCatalog;
        $lease = $leases->claim($tenantId, $nodeId, $catalog->listenerKind(), $workerId, 45);
        $this->assertNotNull($lease);
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), $workerId);

        $connections = new ReflectionProperty($listener, 'connections');
        $current = $connections->getValue($listener);
        $current[$nodeId] = [
            'tenant_id' => $tenantId,
            'stream' => $stream,
            'lease_id' => (string) $lease->id,
            'fencing_token' => (string) $lease->fencing_token,
            'epoch_id' => $epochId,
            'configuration_version' => 1,
            'credential_version' => 1,
            'worker_id' => $workerId,
            'heartbeat_interval_ms' => 30000,
            'next_health_check_at' => microtime(true) + 3600,
            'next_ping_at' => microtime(true) + 3600,
            'last_pong_at' => microtime(true),
            'last_signal_persisted_at' => 0.0,
            'missed_pong_since' => null,
        ];
        $connections->setValue($listener, $current);

        return $lease;
    }

    /**
     * @param  array<string, float|null>  $overrides
     */
    private function setListenerConnectionTimes(AsteriskAriEventListener $listener, string $nodeId, array $overrides): void
    {
        $connections = new ReflectionProperty($listener, 'connections');
        $current = $connections->getValue($listener);
        foreach ($overrides as $key => $value) {
            $current[$nodeId][$key] = $value;
        }
        $connections->setValue($listener, $current);
    }

    private function serverFrame(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $this->assertLessThanOrEqual(125, $length);

        return chr(0x80 | $opcode).chr($length).$payload;
    }

    /**
     * @return array{opcode:int,payload:string}
     */
    private function readClientFrame(mixed $stream): array
    {
        $header = fread($stream, 2);
        $this->assertIsString($header);
        $this->assertSame(2, strlen($header));
        $bytes = array_values(unpack('C2', $header));
        $opcode = $bytes[0] & 0x0F;
        $length = $bytes[1] & 0x7F;
        $masked = ($bytes[1] & 0x80) === 0x80;
        $mask = $masked ? fread($stream, 4) : '';
        $this->assertIsString($mask);
        $payload = $length > 0 ? fread($stream, $length) : '';
        $this->assertIsString($payload);
        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function ariEvent(string $type, string $label, ?string $channelId = null): array
    {
        $second = match ($label) {
            'first', 'one', 'a-one', 'b-one' => '01',
            'second', 'two', 'a-two' => '02',
            'third', 'three', 'a-three', 'not-processed' => '03',
            default => '00',
        };
        $event = [
            'type' => $type,
            'timestamp' => '2026-07-18T00:00:'.$second.'Z',
            'bridge' => [
                'id' => 'utcp-conf-'.$label,
                'name' => 'UTCP conference '.$label,
            ],
        ];
        if ($channelId !== null) {
            $event['channel'] = [
                'id' => $channelId,
                'name' => 'UTCP participant '.$label,
            ];
        }

        return $event;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function conferenceFixture(string $tenantId, string $nodeId): array
    {
        $userId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'listener-participant@example.test',
            'normalized_email' => 'listener-participant@example.test',
            'display_name' => 'Listener Participant',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'listener-conference',
            'display_name' => 'Listener Conference',
            'runtime_node_id' => $nodeId,
            'desired_state' => 'open',
            'observed_state' => 'ready',
            'configuration_generation' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'role' => 'participant',
            'desired_state' => 'admitted',
            'observed_state' => 'joined',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new ReconciliationRepository)->ensureTarget($tenantId, 'conference_participant', $participantId, 2, 300);

        return [$conferenceId, $participantId];
    }
}
