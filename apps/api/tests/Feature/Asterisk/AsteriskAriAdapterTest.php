<?php

namespace Tests\Feature\Asterisk;

use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\PayloadSafety;
use App\Identity\IdentityIds;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventListener;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskAriReconnectBackoff;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeNodeReconciler;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Sources\EventSourceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

final class AsteriskAriAdapterTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('runtime_node', $observations[0]['subject_type'], 'an unknown Asterisk event must never be attributed to a conference or participant');
        $this->assertSame($nodeId, $observations[0]['subject_id']);
        $this->assertSame('degraded', $observations[0]['observed_state'], 'an unrecognized event is conservatively treated as a degraded observation, not a crash or a silent drop');
        $this->assertArrayNotHasKey('password', $observations[0]['payload']);

        // The projection pathway only ever writes runtime_nodes.observed_state / observed_at / observed_configuration_version
        // for a runtime_node-scoped observation; desired_state is never referenced by that code path, and conference /
        // conference_participant projection is only invoked for those specific subject types, so an Asterisk-sourced
        // unknown event structurally cannot reach C5 conference or participant state.
        $projection = new ProjectionService;
        $tenantId = DB::table('runtime_nodes')->where('id', $nodeId)->value('tenant_id');
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
        $this->assertSame('degraded', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));

        // Supported receipt processing continues after an unknown event: a normal readiness observation still projects cleanly.
        $readyNormalizer = new AsteriskAriEventNormalizer($catalog, $catalog->eventType('runtime_info_observed'));
        $readyObservations = $readyNormalizer->normalize($receipt, [
            'configuration_generation' => 3,
            'occurred_at' => now()->toISOString(),
        ]);
        $projection->apply($receipt, $readyObservations);
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'), 'processing must continue normally after an unknown event was safely observed');
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
        $method = new \ReflectionMethod($client, 'eventWebSocketQuery');
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
     * @return array{0:string,1:string}
     */
    private function runtimeNode(): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'asterisk-tenant',
            'display_name' => 'Asterisk Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Asterisk ARI',
            'slug' => 'asterisk-ari',
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
            'request_timeout_ms' => 10000,
            'websocket_handshake_timeout_ms' => 10000,
            'heartbeat_interval_ms' => 30000,
            'reconnect_min_delay_ms' => 1000,
            'reconnect_max_delay_ms' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
