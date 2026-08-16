<?php

namespace Tests\Feature\Infrastructure;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\InfrastructureAdapterRegistry;
use App\Infrastructure\RuntimeFencing\KubernetesRuntimeFenceAdapter;
use App\Infrastructure\RuntimeFencing\KubernetesRuntimeWorkloadInspector;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityResolver;
use App\Models\User;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventListener;
use App\RuntimeAdapters\Asterisk\AsteriskAriException;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KubernetesRuntimeFenceAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_trusted_runtime_node_workload_identity(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('identity');
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->first();

        $identity = app(RuntimeNodeWorkloadIdentityResolver::class)->resolve($node);

        $this->assertSame('utcp-runtime', $identity->namespace);
        $this->assertSame('asterisk-ari-identity', $identity->deployment);
        unset($tenantId);
    }

    public function test_malformed_workload_identity_is_target_mismatch(): void
    {
        [, $nodeId] = $this->runtimeNode('bad-identity', labels: ['kubernetes_workload' => ['namespace' => 'default', 'deployment' => 'asterisk-ari']]);
        $this->expectException(RuntimeNodeWorkloadIdentityException::class);

        app(RuntimeNodeWorkloadIdentityResolver::class)->resolve(DB::table('runtime_nodes')->where('id', $nodeId)->first());
    }

    public function test_successful_fence_scales_owned_deployment_to_zero_and_requires_no_owned_pods(): void
    {
        [, $nodeId] = $this->runtimeNode('success');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-success', 'conference-runtime-success', 1, 1, 1));
        $fake->pods = [['metadata' => ['name' => 'asterisk-old']]];
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-success', 'conference-runtime-success', 0, 0, 0);
        $fake->afterScalePods = [];

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('fenced', $result['status']);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-success', 0]], $fake->scaleCalls);
    }

    public function test_already_fenced_does_not_scale_again(): void
    {
        [, $nodeId] = $this->runtimeNode('already');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-already', 'conference-runtime-already', 0, 0, 0));

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('already_fenced', $result['status']);
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_terminating_owned_pod_keeps_fence_in_progress(): void
    {
        [, $nodeId] = $this->runtimeNode('terminating');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-terminating', 'conference-runtime-terminating', 0, 0, 0));
        $fake->pods = [['metadata' => ['name' => 'terminating-pod', 'deletionTimestamp' => now()->toIso8601String()]]];

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('fence_in_progress', $result['status']);
        $this->assertSame(1, $result['details']['owned_pods_remaining']);
    }

    public function test_old_pod_disappearing_while_new_owned_pod_exists_is_not_fenced(): void
    {
        [, $nodeId] = $this->runtimeNode('recreated');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-recreated', 'conference-runtime-recreated', 0, 0, 0));
        $fake->pods = [['metadata' => ['name' => 'new-owned-pod', 'uid' => 'new']]];

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('fence_in_progress', $result['status']);
    }

    public function test_empty_endpoint_slice_signal_is_insufficient_when_owned_pod_remains(): void
    {
        [, $nodeId] = $this->runtimeNode('endpoints');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-endpoints', 'conference-runtime-endpoints', 0, 0, 0));
        $fake->pods = [['metadata' => ['name' => 'still-running']]];

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), ['endpoint_slice_ready_endpoints' => 0]);

        $this->assertSame('fence_in_progress', $result['status']);
    }

    public function test_target_mismatch_prevents_scale_mutation(): void
    {
        [, $nodeId] = $this->runtimeNode('mismatch');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-mismatch', 'other-runtime-node', 1, 1, 1));

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('target_mismatch', $result['status']);
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_permission_denied_and_api_unavailable_block_fencing(): void
    {
        [, $deniedNode] = $this->runtimeNode('denied');
        $denied = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-denied', 'conference-runtime-denied', 1, 1, 1));
        $denied->scaleException = KubernetesWorkloadClientException::forbidden();
        $this->assertSame('permission_denied', $this->adapter($denied)->fence(DB::table('runtime_nodes')->where('id', $deniedNode)->first(), [])['status']);

        [, $unavailableNode] = $this->runtimeNode('unavailable');
        $unavailable = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-unavailable', 'conference-runtime-unavailable', 1, 1, 1));
        $unavailable->getException = KubernetesWorkloadClientException::unavailable();
        $this->assertSame('unavailable_to_control', $this->adapter($unavailable)->fence(DB::table('runtime_nodes')->where('id', $unavailableNode)->first(), [])['status']);
    }

    public function test_target_recovered_before_mutation_prevents_scale(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('recovered', observedState: 'unavailable');
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-recovered', 'conference-runtime-recovered', 1, 1, 1));
        DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update(['observed_state' => 'ready']);

        $result = $this->adapter($fake)->fence(DB::table('runtime_nodes')->where('id', $nodeId)->first(), []);

        $this->assertSame('target_recovered', $result['status']);
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_runtime_fence_operation_requires_distinct_replacement_before_adapter_invocation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('single-node', observedState: 'unavailable');
        [$conferenceId, $bindingId, $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'single-node', 7);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-single-node', 'conference-runtime-single-node', 1, 1, 1));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-single-node', 'conference-runtime-single-node', 0, 0, 0);
        $fake->afterScalePods = [];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-no-replacement', 1);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('no_replacement_available', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->getCalls);
        $this->assertSame([], $fake->scaleCalls);
        $this->assertDatabaseMissing('control_plane_outbox_messages', [
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
        $this->assertDatabaseHas('conference_runtime_bindings', ['id' => $bindingId, 'status' => 'active']);
        $this->assertSame($nodeId, DB::table('conferences')->where('id', $conferenceId)->value('runtime_node_id'));
        $this->assertSame(7, (int) DB::table('conferences')->where('id', $conferenceId)->value('configuration_generation'));
    }

    public function test_runtime_fence_operation_calls_adapter_when_distinct_replacement_is_available(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('with-replacement', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'with-replacement-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'with-replacement', 8);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-with-replacement', 'conference-runtime-with-replacement', 0, 0, 0));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-with-replacement', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([
            ['utcp-runtime', 'asterisk-ari-with-replacement'],
            ['utcp-runtime', 'asterisk-ari-with-replacement'],
        ], $fake->getCalls);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
        $this->assertSame('already_fenced', $this->terminationEventPayload($conferenceId)['fence_result']);
        $this->assertNull($this->selfScaleProvenance($operationId));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertTrue((bool) data_get($this->operationPayload($operationId), 'runtime_fence_provenance.runtime_node_disabled.by_operation'));
    }

    public function test_replacement_becoming_unavailable_before_runtime_fence_execute_prevents_scale(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('replacement-lost', observedState: 'unavailable');
        $replacementId = $this->replacementRuntimeNode($tenantId, 'replacement-lost-b');
        [, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'replacement-lost', 9);
        DB::table('runtime_nodes')->where('id', $replacementId)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-replacement-lost', 'conference-runtime-replacement-lost', 1, 1, 1));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-replacement-lost', 1);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('no_replacement_available', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->getCalls);
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_later_replacement_readiness_allows_runtime_fence_retry(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('replacement-later', observedState: 'unavailable');
        $replacementId = $this->replacementRuntimeNode($tenantId, 'replacement-later-b', observedState: 'degraded');
        [, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'replacement-later', 10);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-replacement-later', 'conference-runtime-replacement-later', 0, 0, 0));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-replacement-later-1', 1);
        $this->assertSame('no_replacement_available', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->getCalls);

        DB::table('runtime_nodes')->where('id', $replacementId)->update(['observed_state' => 'ready', 'updated_at' => now()]);
        DB::table('runtime_operations')->where('id', $operationId)->update(['available_at' => now()->subSecond()]);
        app(CommandWorker::class)->workOnce('runtime-fence-replacement-later-2', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([
            ['utcp-runtime', 'asterisk-ari-replacement-later'],
            ['utcp-runtime', 'asterisk-ari-replacement-later'],
        ], $fake->getCalls);
    }

    public function test_runtime_fence_operation_completes_after_worker_restart_poll_observes_zero_replicas(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('worker', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'worker-b');
        $conferenceId = IdentityIds::new();
        $bindingId = IdentityIds::new();
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'worker',
            'display_name' => 'Worker',
            'desired_state' => 'open',
            'observed_state' => 'unobserved',
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 3,
            'created_by' => null,
            'updated_by' => null,
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
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operationId = app(RuntimeOperationRepository::class)->create(
            'runtime.node.runtime.fence',
            'conference',
            $conferenceId,
            [
                'conference_id' => $conferenceId,
                'former_runtime_binding_id' => $bindingId,
                'former_runtime_node_id' => $nodeId,
                'runtime_node_id' => $nodeId,
                'configuration_generation' => 3,
            ],
            ExecutionContext::system(tenantId: $tenantId, reason: 'runtime fence worker test'),
            runtimeNodeId: $nodeId,
        );

        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-worker', 'conference-runtime-worker', 1, 1, 1));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-worker', 'conference-runtime-worker', 0, 1, 0);
        $fake->afterScalePods = [['metadata' => ['name' => 'terminating']]];
        $this->bindFakeClient($fake);
        app(CommandWorker::class)->workOnce('runtime-fence-worker-1', 1);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $provenance = $this->selfScaleProvenance($operationId);
        $this->assertIsArray($provenance);
        $this->assertTrue($provenance['by_operation']);
        $this->assertSame($operationId, $provenance['operation_id']);
        $this->assertSame('utcp-runtime', $provenance['namespace']);
        $this->assertSame('asterisk-ari-worker', $provenance['deployment']);
        $this->assertSame(1, $provenance['pre_scale_replicas']);

        DB::table('runtime_operations')->where('id', $operationId)->update(['available_at' => now()->subSecond()]);
        $fake->deployment = $this->deployment('asterisk-ari-worker', 'conference-runtime-worker', 0, 0, 0);
        $fake->pods = [];
        $this->app->forgetInstance(CommandWorker::class);
        app(CommandWorker::class)->workOnce('runtime-fence-worker-2', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $eventPayload = $this->terminationEventPayload($conferenceId);
        $this->assertSame('fenced', $eventPayload['fence_result']);
        $this->assertSame($operationId, $eventPayload['operation_id']);
        $this->assertSame($bindingId, $eventPayload['former_runtime_binding_id']);
        $this->assertSame($nodeId, $eventPayload['former_runtime_node_id']);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-worker', 0]], $fake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $conferenceId)->where('event_type', 'conference.runtime_fence_terminated')->count());
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertTrue((bool) data_get($this->operationPayload($operationId), 'runtime_fence_provenance.runtime_node_disabled.by_operation'));

        $processed = app(CommandWorker::class)->workOnce('runtime-fence-worker-3', 1);
        $this->assertSame(0, $processed);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-worker', 0]], $fake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $conferenceId)->where('event_type', 'conference.runtime_fence_terminated')->count());
    }

    public function test_runtime_fence_operation_scales_and_completes_as_fenced_in_one_attempt(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('one-attempt', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'one-attempt-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'one-attempt', 11);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-one-attempt', 'conference-runtime-one-attempt', 1, 1, 1));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-one-attempt', 'conference-runtime-one-attempt', 0, 0, 0);
        $fake->afterScalePods = [];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-one-attempt', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-one-attempt', 0]], $fake->scaleCalls);
        $this->assertSame('fenced', $this->terminationEventPayload($conferenceId)['fence_result']);
        $this->assertIsArray($this->selfScaleProvenance($operationId));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
    }

    public function test_runtime_fence_operation_already_zero_with_terminating_pod_remains_already_fenced(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('already-terminating', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'already-terminating-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'already-terminating', 12);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-already-terminating', 'conference-runtime-already-terminating', 0, 0, 0));
        $fake->pods = [['metadata' => ['name' => 'terminating-pod', 'deletionTimestamp' => now()->toIso8601String()]]];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-already-terminating-1', 1);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('fence_in_progress', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertNull($this->selfScaleProvenance($operationId));

        DB::table('runtime_operations')->where('id', $operationId)->update(['available_at' => now()->subSecond()]);
        $fake->pods = [];
        app(CommandWorker::class)->workOnce('runtime-fence-already-terminating-2', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertSame('already_fenced', $this->terminationEventPayload($conferenceId)['fence_result']);
        $this->assertNull($this->selfScaleProvenance($operationId));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
    }

    public function test_runtime_fence_operation_does_not_claim_external_zero_after_scale_request_fails(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('scale-fails', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'scale-fails-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'scale-fails', 13);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-scale-fails', 'conference-runtime-scale-fails', 1, 1, 1));
        $fake->scaleException = KubernetesWorkloadClientException::unavailable();
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-scale-fails-1', 1);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('unavailable_to_control', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertNull($this->selfScaleProvenance($operationId));

        DB::table('runtime_operations')->where('id', $operationId)->update(['available_at' => now()->subSecond()]);
        $fake->scaleException = null;
        $fake->deployment = $this->deployment('asterisk-ari-scale-fails', 'conference-runtime-scale-fails', 0, 0, 0);
        $fake->pods = [];
        app(CommandWorker::class)->workOnce('runtime-fence-scale-fails-2', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertSame('already_fenced', $this->terminationEventPayload($conferenceId)['fence_result']);
        $this->assertNull($this->selfScaleProvenance($operationId));
    }

    public function test_runtime_fence_operation_target_mismatch_records_no_self_scale_provenance(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('handler-mismatch', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'handler-mismatch-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'handler-mismatch', 14);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-handler-mismatch', 'other-runtime-node', 1, 1, 1));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-handler-mismatch', 1);

        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('target_mismatch', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertNull($this->selfScaleProvenance($operationId));
        $this->assertDatabaseMissing('control_plane_outbox_messages', [
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
    }

    public function test_runtime_restore_operation_scales_to_source_target_and_completes_after_worker_restart(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('restore', observedState: 'unavailable');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore', 23, 1);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore', 23, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore', 'conference-runtime-restore', 0, 0, 0));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-restore', 'conference-runtime-restore', 1, 0, 0);
        $fake->afterScalePods = [];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-worker-1', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_deployment_not_ready', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore', 1]], $fake->scaleCalls);
        $provenance = $this->restoreScaleProvenance($restoreId);
        $this->assertIsArray($provenance);
        $this->assertSame($restoreId, $provenance['operation_id']);
        $this->assertSame($sourceFenceId, $provenance['source_fence_operation_id']);
        $this->assertSame(1, $provenance['target_replicas']);
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->claimAsteriskLease($tenantId, $nodeId);
        $epochId = $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        $fake->deployment = $this->deployment('asterisk-ari-restore', 'conference-runtime-restore', 1, 1, 1);
        $fake->pods = [$this->readyPod('asterisk-ari-restore-new', 'pod-new-restore')];
        $this->app->forgetInstance(CommandWorker::class);

        app(CommandWorker::class)->workOnce('runtime-restore-worker-2', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore', 1]], $fake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.restored')->count());
        $payload = $this->restoredEventPayload($nodeId);
        $this->assertSame($restoreId, $payload['operation_id']);
        $this->assertSame($sourceFenceId, $payload['source_fence_operation_id']);
        $this->assertSame(1, $payload['target_replicas']);
        $this->assertSame(['pod-new-restore'], $payload['new_pod_uids']);
        $this->assertSame($epochId, $payload['new_event_epoch_id']);

        $processed = app(CommandWorker::class)->workOnce('runtime-restore-worker-3', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame(0, $processed);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore', 1]], $fake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.restored')->count());
    }

    public function test_runtime_restore_missing_source_provenance_fails_without_scale(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('restore-missing', observedState: 'unavailable');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-missing', 24, 1, includeScaleProvenance: false);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-missing', 24, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-missing', 'conference-runtime-restore-missing', 0, 0, 0));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-missing', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_source_fence_invalid', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertNull($this->restoreScaleProvenance($restoreId));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
    }

    public function test_runtime_restore_waits_for_lease_epoch_and_ready_projection_before_activation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('restore-gates', observedState: 'unavailable');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-gates', 25, 1);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-gates', 25, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-gates', 'conference-runtime-restore-gates', 0, 0, 0));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-restore-gates', 'conference-runtime-restore-gates', 1, 1, 1);
        $fake->afterScalePods = [$this->readyPod('asterisk-ari-restore-gates-new', 'pod-new-gates')];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-gates-1', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('runtime_restore_listener_lease_missing', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $this->claimAsteriskLease($tenantId, $nodeId);
        app(CommandWorker::class)->workOnce('runtime-restore-gates-2', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('runtime_restore_event_epoch_missing', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        app(CommandWorker::class)->workOnce('runtime-restore-gates-3', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('runtime_restore_node_not_ready', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-gates', 1]], $fake->scaleCalls);
    }

    public function test_runtime_restore_listener_eligibility_allows_full_disabled_node_recovery_after_listener_retry(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(
            'restore-listener',
            observedState: 'unavailable',
            runtimeFamily: 'asterisk',
            adapterKey: 'asterisk-ari',
        );
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $this->configureAriListenerNode($nodeId, configurationVersion: 2);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-listener', 39, 1);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-listener', 39, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-listener', 'conference-runtime-restore-listener', 0, 0, 0));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-restore-listener', 'conference-runtime-restore-listener', 1, 1, 1);
        $fake->afterScalePods = [$this->readyPod('asterisk-ari-restore-listener-new', 'pod-new-listener')];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('restore-listener-worker-1', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_listener_lease_missing', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-listener', 1]], $fake->scaleCalls);
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));

        $failingClient = new FakeRestoreAriClient(failOpenAttempts: 1);
        $failingListener = $this->restoreListener($failingClient);
        $this->assertSame(0, $failingListener->workOnce('restore-listener-events-fail', 5));
        $this->assertSame('released', DB::table('runtime_listener_leases')->where('runtime_node_id', $nodeId)->value('status'));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));

        $recoveringClient = new FakeRestoreAriClient;
        $recoveringListener = $this->restoreListener($recoveringClient);
        $this->assertSame(1, $recoveringListener->workOnce('restore-listener-events-ok', 5));
        $this->assertSame(1, $recoveringClient->openAttempts);
        $this->assertSame('claimed', DB::table('runtime_listener_leases')->where('runtime_node_id', $nodeId)->where('status', 'claimed')->value('status'));
        $epochId = (string) DB::table('runtime_event_connection_epochs')
            ->where('runtime_node_id', $nodeId)
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->value('id');
        $this->assertNotSame('', $epochId);

        app(EventNormalizerWorker::class)->workOnce('restore-listener-normalizer', 10);
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'), 'listener projection must not activate placement before the restore handler completes');

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        app(CommandWorker::class)->workOnce('restore-listener-worker-2', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-listener', 1]], $fake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.restored')->count());
        $payload = $this->restoredEventPayload($nodeId);
        $this->assertSame($restoreId, $payload['operation_id']);
        $this->assertSame($sourceFenceId, $payload['source_fence_operation_id']);
        $this->assertSame($epochId, $payload['new_event_epoch_id']);
        $this->assertSame(['pod-new-listener'], $payload['new_pod_uids']);

        $processed = app(CommandWorker::class)->workOnce('restore-listener-worker-3', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame(0, $processed);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.restored')->count());
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-listener', 1]], $fake->scaleCalls);
    }

    public function test_runtime_restore_delayed_pod_and_listener_convergence_succeeds_with_expanded_window(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('restore-delayed', observedState: 'unavailable');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-delayed', 43, 1);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-delayed', 43, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-delayed', 'conference-runtime-restore-delayed', 0, 0, 0));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-restore-delayed', 'conference-runtime-restore-delayed', 1, 0, 0);
        $fake->afterScalePods = [];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-delayed-1', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_deployment_not_ready', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $fake->deployment = $this->deployment('asterisk-ari-restore-delayed', 'conference-runtime-restore-delayed', 1, 1, 1);
        $fake->pods = [];
        app(CommandWorker::class)->workOnce('runtime-restore-delayed-2', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_pods_not_ready', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $fake->pods = [$this->readyPod('asterisk-ari-restore-delayed-new', 'pod-new-delayed')];
        app(CommandWorker::class)->workOnce('runtime-restore-delayed-3', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_listener_lease_missing', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $this->claimAsteriskLease($tenantId, $nodeId);
        app(CommandWorker::class)->workOnce('runtime-restore-delayed-4', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_event_epoch_missing', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        $epochId = $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        app(CommandWorker::class)->workOnce('runtime-restore-delayed-5', 1, includeOperationTypes: ['runtime.node.restore']);
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_node_not_ready', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));

        DB::table('runtime_operations')->where('id', $restoreId)->update(['available_at' => now()->subSecond()]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_at' => now(),
            'updated_at' => now(),
        ]);
        app(CommandWorker::class)->workOnce('runtime-restore-delayed-6', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertSame(6, (int) DB::table('runtime_operations')->where('id', $restoreId)->value('attempt_count'));
        $this->assertSame(8, (int) DB::table('runtime_operations')->where('id', $restoreId)->value('max_attempts'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-delayed', 1]], $fake->scaleCalls);
        $this->assertSame($epochId, $this->restoredEventPayload($nodeId)['new_event_epoch_id']);
    }

    public function test_two_runtime_nodes_restore_concurrently_with_independent_authority(): void
    {
        [$tenantA, $nodeA] = $this->runtimeNode('restore-concurrent-a', observedState: 'unavailable');
        [$tenantB, $nodeB] = $this->runtimeNode('restore-concurrent-b', observedState: 'unavailable');
        foreach ([[$tenantA, $nodeA, 'restore-concurrent-a'], [$tenantB, $nodeB, 'restore-concurrent-b']] as [$tenantId, $nodeId, $slug]) {
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_state' => 'disabled',
                'configuration_version' => 2,
                'observed_state' => 'ready',
                'observed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->claimAsteriskLease($tenantId, $nodeId);
        }
        $sourceFenceA = $this->sourceFenceOperation($tenantA, $nodeA, 'restore-concurrent-a', 44, 1);
        $sourceFenceB = $this->sourceFenceOperation($tenantB, $nodeB, 'restore-concurrent-b', 45, 1);
        $restoreA = $this->restoreOperation($tenantA, $nodeA, $sourceFenceA, 'restore-concurrent-a', 44, 1, 2);
        $restoreB = $this->restoreOperation($tenantB, $nodeB, $sourceFenceB, 'restore-concurrent-b', 45, 1, 2);
        DB::table('runtime_operations')->where('id', $restoreB)->update(['available_at' => now()->addMinute()]);
        $epochA = $this->openAsteriskEpoch($tenantA, $nodeA, now()->addSecond());
        $epochB = $this->openAsteriskEpoch($tenantB, $nodeB, now()->addSecond());

        $fakeA = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-concurrent-a', 'conference-runtime-restore-concurrent-a', 0, 0, 0));
        $fakeA->afterScaleDeployment = $this->deployment('asterisk-ari-restore-concurrent-a', 'conference-runtime-restore-concurrent-a', 1, 1, 1);
        $fakeA->afterScalePods = [$this->readyPod('asterisk-ari-restore-concurrent-a-new', 'pod-new-concurrent-a')];
        $this->bindFakeClient($fakeA);
        app(CommandWorker::class)->workOnce('runtime-restore-concurrent-a', 1, includeOperationTypes: ['runtime.node.restore']);

        $fakeB = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-concurrent-b', 'conference-runtime-restore-concurrent-b', 0, 0, 0));
        $fakeB->afterScaleDeployment = $this->deployment('asterisk-ari-restore-concurrent-b', 'conference-runtime-restore-concurrent-b', 1, 1, 1);
        $fakeB->afterScalePods = [$this->readyPod('asterisk-ari-restore-concurrent-b-new', 'pod-new-concurrent-b')];
        $this->bindFakeClient($fakeB);
        DB::table('runtime_operations')->where('id', $restoreB)->update(['available_at' => now()->subSecond()]);
        $this->app->forgetInstance(CommandWorker::class);
        app(CommandWorker::class)->workOnce('runtime-restore-concurrent-b', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreA)->value('status'));
        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreB)->value('status'), (string) DB::table('runtime_operations')->where('id', $restoreB)->value('last_failure_code'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeA)->value('desired_state'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeB)->value('desired_state'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-concurrent-a', 1]], $fakeA->scaleCalls);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-concurrent-b', 1]], $fakeB->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeA)->where('event_type', 'runtime_node.restored')->count());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeB)->where('event_type', 'runtime_node.restored')->count());
        $this->assertSame($epochA, $this->restoredEventPayload($nodeA)['new_event_epoch_id']);
        $this->assertSame($epochB, $this->restoredEventPayload($nodeB)['new_event_epoch_id']);
    }

    public function test_desired_state_recovery_from_terminal_restore_successor_completes_real_path(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('restore-successor-chain@utcp.local.test', 'restore-successor-chain');
        $nodeId = $this->runtimeNodeForTenant($tenantId, 'restore-successor-chain');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-successor-chain', 46, 1);

        $originalId = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$nodeId}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'disabled')
            ->json('runtime_operation.id');
        DB::table('runtime_operations')->where('id', $originalId)->update(['max_attempts' => 1]);

        $firstFake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-successor-chain', 'conference-runtime-restore-successor-chain', 0, 0, 0));
        $firstFake->afterScaleDeployment = $this->deployment('asterisk-ari-restore-successor-chain', 'conference-runtime-restore-successor-chain', 1, 0, 0);
        $firstFake->afterScalePods = [];
        $this->bindFakeClient($firstFake);
        app(CommandWorker::class)->workOnce('runtime-restore-successor-original', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $originalId)->value('status'));
        $this->assertSame('runtime_restore_deployment_not_ready', DB::table('runtime_operations')->where('id', $originalId)->value('last_failure_code'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-restore-successor-chain', 1]], $firstFake->scaleCalls);

        $successorId = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$nodeId}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'disabled')
            ->json('runtime_operation.id');
        $this->assertNotSame($originalId, $successorId);
        $successorPayload = $this->operationPayload($successorId);
        $this->assertSame($originalId, $successorPayload['supersedes_restore_operation_id']);
        $this->assertSame(2, $successorPayload['restore_attempt_generation']);
        $this->assertSame($successorId, $successorPayload['runtime_restore_provenance']['scale_to_target_requested']['operation_id']);
        $this->assertSame($originalId, $successorPayload['runtime_restore_provenance']['scale_to_target_requested']['scale_requested_by_restore_operation_id']);
        $this->assertSame($originalId, $successorPayload['runtime_restore_provenance']['scale_to_target_requested']['inherited_from_restore_operation_id']);

        $secondFake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-successor-chain', 'conference-runtime-restore-successor-chain', 1, 1, 1));
        $secondFake->pods = [$this->readyPod('asterisk-ari-restore-successor-chain-new', 'pod-new-successor-chain')];
        $this->bindFakeClient($secondFake);
        $this->claimAsteriskLease($tenantId, $nodeId);
        $epochId = $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_at' => now(),
            'updated_at' => now(),
        ]);
        app(CommandWorker::class)->workOnce('runtime-restore-successor-complete', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $successorId)->value('status'));
        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $originalId)->value('status'));
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $nodeId)->value('desired_state'));
        $this->assertSame([], $secondFake->scaleCalls);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $nodeId)->where('event_type', 'runtime_node.restored')->count());
        $this->assertSame($epochId, $this->restoredEventPayload($nodeId)['new_event_epoch_id']);
    }

    public function test_runtime_restore_authority_change_prevents_mutation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('restore-stale', observedState: 'unavailable');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 3,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-stale', 26, 1);
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-stale', 26, 1, 2);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-restore-stale', 'conference-runtime-restore-stale', 0, 0, 0));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-stale', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame('runtime_restore_configuration_stale', DB::table('runtime_operations')->where('id', $restoreId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_runtime_fence_operation_recovery_before_mutation_records_no_self_scale_provenance(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('handler-recovered', observedState: 'unavailable');
        $this->replacementRuntimeNode($tenantId, 'handler-recovered-b');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'handler-recovered', 15);
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['observed_state' => 'ready', 'updated_at' => now()]);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-handler-recovered', 'conference-runtime-handler-recovered', 1, 1, 1));
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-handler-recovered', 1);

        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame('target_recovered', DB::table('runtime_operations')->where('id', $operationId)->value('last_failure_code'));
        $this->assertSame([], $fake->scaleCalls);
        $this->assertNull($this->selfScaleProvenance($operationId));
        $this->assertDatabaseMissing('control_plane_outbox_messages', [
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
    }

    public function test_runtime_fence_operation_for_asterisk_ari_completes_while_asterisk_ari_b_pod_runs(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('a', observedState: 'unavailable', labels: [
            'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari'],
        ]);
        $this->replacementRuntimeNode($tenantId, 'a-replacement');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'fence-a-prefix-collision', 50);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari', 'conference-runtime-a', 1, 1, 1));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari', 'conference-runtime-a', 0, 0, 0);
        $fake->afterScaleOwnedPodsByDeployment = [
            'asterisk-ari' => [],
            'asterisk-ari-b' => [$this->readyPod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-running')],
        ];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-a-prefix-collision', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari', 0]], $fake->scaleCalls);
        $payload = $this->terminationEventPayload($conferenceId);
        $this->assertSame('fenced', $payload['fence_result']);
        $this->assertSame(0, $payload['owned_pods_remaining']);
    }

    public function test_runtime_fence_operation_for_asterisk_ari_b_completes_while_asterisk_ari_pod_runs(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('b', observedState: 'unavailable', labels: [
            'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari-b'],
        ]);
        $this->replacementRuntimeNode($tenantId, 'b-replacement');
        [$conferenceId, , $operationId] = $this->runtimeFenceOperation($tenantId, $nodeId, 'fence-b-prefix-collision', 51);
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-b', 'conference-runtime-b', 1, 1, 1));
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-b', 'conference-runtime-b', 0, 0, 0);
        $fake->afterScaleOwnedPodsByDeployment = [
            'asterisk-ari' => [$this->readyPod('asterisk-ari-74b5d7d9-aa111', 'pod-a-running')],
            'asterisk-ari-b' => [],
        ];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-fence-b-prefix-collision', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-b', 0]], $fake->scaleCalls);
        $payload = $this->terminationEventPayload($conferenceId);
        $this->assertSame('fenced', $payload['fence_result']);
        $this->assertSame(0, $payload['owned_pods_remaining']);
    }

    public function test_runtime_restore_operation_for_asterisk_ari_ignores_running_asterisk_ari_b_pod(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('a', observedState: 'unavailable', labels: [
            'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari'],
        ]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'observed_state' => 'ready',
            'observed_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-a-prefix-collision', 52, 1, deploymentName: 'asterisk-ari');
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-a-prefix-collision', 52, 1, 2, deploymentName: 'asterisk-ari');
        $this->claimAsteriskLease($tenantId, $nodeId);
        $epochId = $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari', 'conference-runtime-a', 0, 0, 0));
        $fake->ownedPodsByDeployment = [
            'asterisk-ari' => [],
            'asterisk-ari-b' => [$this->readyPod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-running')],
        ];
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari', 'conference-runtime-a', 1, 1, 1);
        $fake->afterScaleOwnedPodsByDeployment = [
            'asterisk-ari' => [$this->readyPod('asterisk-ari-74b5d7d9-aa111', 'pod-a-new')],
            'asterisk-ari-b' => [$this->readyPod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-running')],
        ];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-a-prefix-collision', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari', 1]], $fake->scaleCalls);
        $payload = $this->restoredEventPayload($nodeId);
        $this->assertSame(['pod-a-new'], $payload['new_pod_uids']);
        $this->assertSame($epochId, $payload['new_event_epoch_id']);
    }

    public function test_runtime_restore_operation_for_asterisk_ari_b_ignores_running_asterisk_ari_pod(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('b', observedState: 'unavailable', labels: [
            'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari-b'],
        ]);
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'observed_state' => 'ready',
            'observed_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $nodeId, 'restore-b-prefix-collision', 53, 1, deploymentName: 'asterisk-ari-b');
        $restoreId = $this->restoreOperation($tenantId, $nodeId, $sourceFenceId, 'restore-b-prefix-collision', 53, 1, 2, deploymentName: 'asterisk-ari-b');
        $this->claimAsteriskLease($tenantId, $nodeId);
        $epochId = $this->openAsteriskEpoch($tenantId, $nodeId, now()->addSecond());
        $fake = FakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-b', 'conference-runtime-b', 0, 0, 0));
        $fake->ownedPodsByDeployment = [
            'asterisk-ari' => [$this->readyPod('asterisk-ari-74b5d7d9-aa111', 'pod-a-running')],
            'asterisk-ari-b' => [],
        ];
        $fake->afterScaleDeployment = $this->deployment('asterisk-ari-b', 'conference-runtime-b', 1, 1, 1);
        $fake->afterScaleOwnedPodsByDeployment = [
            'asterisk-ari' => [$this->readyPod('asterisk-ari-74b5d7d9-aa111', 'pod-a-running')],
            'asterisk-ari-b' => [$this->readyPod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-new')],
        ];
        $this->bindFakeClient($fake);

        app(CommandWorker::class)->workOnce('runtime-restore-b-prefix-collision', 1, includeOperationTypes: ['runtime.node.restore']);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $restoreId)->value('status'));
        $this->assertSame([['utcp-runtime', 'asterisk-ari-b', 1]], $fake->scaleCalls);
        $payload = $this->restoredEventPayload($nodeId);
        $this->assertSame(['pod-b-new'], $payload['new_pod_uids']);
        $this->assertSame($epochId, $payload['new_event_epoch_id']);
    }

    private function adapter(FakeKubernetesWorkloadClient $fake): KubernetesRuntimeFenceAdapter
    {
        return new KubernetesRuntimeFenceAdapter($fake, app(RuntimeNodeWorkloadIdentityResolver::class), app(KubernetesRuntimeWorkloadInspector::class));
    }

    private function bindFakeClient(FakeKubernetesWorkloadClient $fake): void
    {
        $this->app->instance(KubernetesWorkloadClient::class, $fake);
        $this->app->forgetInstance(InfrastructureAdapterRegistry::class);
        $this->app->forgetInstance(RuntimeOperationHandlerRegistry::class);
    }

    private function restoreListener(AsteriskAriClient $client): AsteriskAriEventListener
    {
        return new AsteriskAriEventListener(
            new AsteriskCatalog,
            $client,
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            new RuntimeEventReceiptRepository,
            new ReconciliationRepository,
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNode(
        string $slug,
        string $observedState = 'unavailable',
        ?array $labels = null,
        string $runtimeFamily = 'simulator',
        string $adapterKey = 'simulator-deterministic',
    ): array {
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'tenant-'.$slug,
            'display_name' => 'Tenant '.$slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nodeId = IdentityIds::new();
        $nodeSlug = 'conference-runtime-'.$slug;
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime '.$slug,
            'slug' => $nodeSlug,
            'runtime_family' => $runtimeFamily,
            'adapter_key' => $adapterKey,
            'desired_state' => 'active',
            'observed_state' => $observedState,
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode($labels ?? [
                'kubernetes_workload' => [
                    'namespace' => 'utcp-runtime',
                    'deployment' => 'asterisk-ari-'.$slug,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $nodeId];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Runtime Restore User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => 'Tenant '.$slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return [$user, $tenantId];
    }

    private function runtimeNodeForTenant(string $tenantId, string $slug): string
    {
        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime '.$slug,
            'slug' => 'conference-runtime-'.$slug,
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'unavailable',
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode([
                'kubernetes_workload' => [
                    'namespace' => 'utcp-runtime',
                    'deployment' => 'asterisk-ari-'.$slug,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $nodeId;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function replacementRuntimeNode(
        string $tenantId,
        string $slug,
        string $observedState = 'ready',
        string $desiredState = 'active',
        array $capabilities = ['conference.lifecycle', 'conference.participation'],
    ): string {
        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Replacement '.$slug,
            'slug' => 'conference-runtime-'.$slug,
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => $desiredState,
            'observed_state' => $observedState,
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode(['purpose' => 'replacement-guard-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($capabilities as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $nodeId;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function runtimeFenceOperation(string $tenantId, string $nodeId, string $slug, int $generation): array
    {
        $conferenceId = IdentityIds::new();
        $bindingId = IdentityIds::new();
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'display_name' => 'Fence '.$slug,
            'desired_state' => 'open',
            'observed_state' => 'unobserved',
            'runtime_node_id' => $nodeId,
            'configuration_generation' => $generation,
            'created_by' => null,
            'updated_by' => null,
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
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operationId = app(RuntimeOperationRepository::class)->create(
            'runtime.node.runtime.fence',
            'conference',
            $conferenceId,
            [
                'conference_id' => $conferenceId,
                'former_runtime_binding_id' => $bindingId,
                'former_runtime_node_id' => $nodeId,
                'runtime_node_id' => $nodeId,
                'configuration_generation' => $generation,
            ],
            ExecutionContext::system(tenantId: $tenantId, reason: 'runtime fence replacement guard test'),
            runtimeNodeId: $nodeId,
        );

        return [$conferenceId, $bindingId, $operationId];
    }

    private function sourceFenceOperation(
        string $tenantId,
        string $nodeId,
        string $slug,
        int $generation,
        int $preScaleReplicas,
        bool $includeScaleProvenance = true,
        ?string $deploymentName = null,
    ): string {
        $payload = [
            'conference_id' => IdentityIds::new(),
            'former_runtime_binding_id' => IdentityIds::new(),
            'former_runtime_node_id' => $nodeId,
            'runtime_node_id' => $nodeId,
            'configuration_generation' => $generation,
            'runtime_fence_provenance' => [
                'runtime_node_disabled' => [
                    'by_operation' => true,
                    'operation_id' => 'source-fence',
                    'runtime_node_id' => $nodeId,
                    'disabled_at' => now()->toJSON(),
                ],
            ],
        ];
        if ($includeScaleProvenance) {
            $payload['runtime_fence_provenance']['scale_to_zero_requested'] = [
                'by_operation' => true,
                'operation_id' => 'source-fence',
                'namespace' => 'utcp-runtime',
                'deployment' => $deploymentName ?? 'asterisk-ari-'.$slug,
                'pre_scale_replicas' => $preScaleReplicas,
                'attempt_count' => 1,
                'requested_at' => now()->subMinutes(2)->toJSON(),
            ];
        }

        $operationId = app(RuntimeOperationRepository::class)->create(
            'runtime.node.runtime.fence',
            'conference',
            (string) $payload['conference_id'],
            $payload,
            ExecutionContext::system(tenantId: $tenantId, reason: 'source runtime fence'),
            runtimeNodeId: $nodeId,
        );
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'status' => 'succeeded',
            'completed_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $payload['runtime_fence_provenance']['runtime_node_disabled']['operation_id'] = $operationId;
        if ($includeScaleProvenance) {
            $payload['runtime_fence_provenance']['scale_to_zero_requested']['operation_id'] = $operationId;
        }
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $operationId;
    }

    private function restoreOperation(
        string $tenantId,
        string $nodeId,
        string $sourceFenceId,
        string $slug,
        int $generation,
        int $targetReplicas,
        int $configurationVersion,
        ?string $deploymentName = null,
    ): string {
        return app(RuntimeOperationRepository::class)->create(
            'runtime.node.restore',
            'runtime_node',
            $nodeId,
            [
                'tenant_id' => $tenantId,
                'runtime_node_id' => $nodeId,
                'requested_desired_state' => 'active',
                'source_fence_operation_id' => $sourceFenceId,
                'source_fence_generation' => $generation,
                'workload_namespace' => 'utcp-runtime',
                'deployment' => $deploymentName ?? 'asterisk-ari-'.$slug,
                'target_replicas' => $targetReplicas,
                'requesting_actor' => null,
                'reason' => 'test restore',
                'expected_runtime_node_configuration_version' => $configurationVersion,
            ],
            ExecutionContext::system(tenantId: $tenantId, reason: 'runtime restore test'),
            maxAttempts: (int) config('telephony_domain.operation_max_attempts.runtime_node_restore', 8),
            runtimeNodeId: $nodeId,
        );
    }

    private function configureAriListenerNode(string $nodeId, int $configurationVersion): void
    {
        DB::table('asterisk_ari_profiles')->insert([
            'runtime_node_id' => $nodeId,
            'configuration_version' => $configurationVersion,
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

    /**
     * @return array<string, mixed>
     */
    private function operationPayload(string $operationId): array
    {
        $payload = DB::table('runtime_operations')->where('id', $operationId)->value('payload');
        $this->assertIsString($payload);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selfScaleProvenance(string $operationId): ?array
    {
        $provenance = $this->operationPayload($operationId)['runtime_fence_provenance']['scale_to_zero_requested'] ?? null;
        if ($provenance === null) {
            return null;
        }
        $this->assertIsArray($provenance);

        return $provenance;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreScaleProvenance(string $operationId): ?array
    {
        $provenance = $this->operationPayload($operationId)['runtime_restore_provenance']['scale_to_target_requested'] ?? null;
        if ($provenance === null) {
            return null;
        }
        $this->assertIsArray($provenance);

        return $provenance;
    }

    /**
     * @return array<string, mixed>
     */
    private function terminationEventPayload(string $conferenceId): array
    {
        $payload = DB::table('control_plane_outbox_messages')
            ->where('aggregate_id', $conferenceId)
            ->where('event_type', 'conference.runtime_fence_terminated')
            ->value('payload');
        $this->assertIsString($payload);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function restoredEventPayload(string $nodeId): array
    {
        $payload = DB::table('control_plane_outbox_messages')
            ->where('aggregate_id', $nodeId)
            ->where('event_type', 'runtime_node.restored')
            ->value('payload');
        $this->assertIsString($payload);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function deployment(string $name, string $runtimeNodeSlug, int $desired, int $status, int $available): array
    {
        return [
            'metadata' => [
                'namespace' => 'utcp-runtime',
                'name' => $name,
                'labels' => [
                    'app.kubernetes.io/part-of' => 'utcp',
                    'app.kubernetes.io/component' => 'asterisk-ari',
                    'utcp.dev/runtime-node' => $runtimeNodeSlug,
                ],
            ],
            'spec' => ['replicas' => $desired],
            'status' => ['replicas' => $status, 'availableReplicas' => $available],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readyPod(string $name, string $uid): array
    {
        return [
            'metadata' => [
                'name' => $name,
                'uid' => $uid,
            ],
            'status' => [
                'conditions' => [
                    ['type' => 'Ready', 'status' => 'True'],
                ],
            ],
        ];
    }

    private function claimAsteriskLease(string $tenantId, string $nodeId): void
    {
        $eventSourceId = IdentityIds::new();
        DB::table('event_sources')->insert([
            'id' => $eventSourceId,
            'source_kind' => 'runtime-node',
            'source_key' => 'restore-'.$nodeId,
            'runtime_node_id' => $nodeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_listener_leases')->insert([
            'id' => IdentityIds::new(),
            'event_source_id' => $eventSourceId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'listener_kind' => 'asterisk-ari-events',
            'status' => 'claimed',
            'owner' => 'restore-test',
            'fencing_token' => IdentityIds::new(),
            'claimed_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
            'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openAsteriskEpoch(string $tenantId, string $nodeId, mixed $openedAt): string
    {
        $eventSourceId = (string) DB::table('event_sources')->where('runtime_node_id', $nodeId)->value('id');
        $epochId = IdentityIds::new();
        DB::table('runtime_event_connection_epochs')->insert([
            'id' => $epochId,
            'event_source_id' => $eventSourceId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'adapter_key' => 'asterisk-ari',
            'status' => 'open',
            'owner' => 'restore-test',
            'fencing_token' => IdentityIds::new(),
            'opened_at' => $openedAt,
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $epochId;
    }
}

final class FakeKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    public function inspectResource(string $kind, string $name): ?array
    {
        unset($kind, $name);

        return null;
    }

    /** @var list<array{0:string,1:string}> */
    public array $getCalls = [];

    /** @var list<array{0:string,1:string,2:int}> */
    public array $scaleCalls = [];

    /** @var list<array<string, mixed>> */
    public array $pods = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $ownedPodsByDeployment = [];

    /** @var list<array<string, mixed>> */
    public array $afterScalePods = [];

    /** @var array<string, list<array<string, mixed>>>|null */
    public ?array $afterScaleOwnedPodsByDeployment = null;

    /** @var array<string, mixed>|null */
    public ?array $afterScaleDeployment = null;

    public ?KubernetesWorkloadClientException $getException = null;

    public ?KubernetesWorkloadClientException $scaleException = null;

    public function __construct(
        public array $deployment,
    ) {}

    public static function withDeployment(array $deployment): self
    {
        return new self($deployment);
    }

    public function getDeployment(string $namespace, string $name): ?array
    {
        $this->getCalls[] = [$namespace, $name];
        if ($this->getException !== null) {
            throw $this->getException;
        }

        if ((string) data_get($this->deployment, 'metadata.namespace') !== $namespace || (string) data_get($this->deployment, 'metadata.name') !== $name) {
            return null;
        }

        return $this->deployment;
    }

    public function scaleDeployment(string $namespace, string $name, int $replicas): void
    {
        if ($this->scaleException !== null) {
            throw $this->scaleException;
        }

        $this->scaleCalls[] = [$namespace, $name, $replicas];
        if ($this->afterScaleDeployment !== null) {
            $this->deployment = $this->afterScaleDeployment;
            $this->pods = $this->afterScalePods;
            if ($this->afterScaleOwnedPodsByDeployment !== null) {
                $this->ownedPodsByDeployment = $this->afterScaleOwnedPodsByDeployment;
            }
        }
    }

    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        unset($namespace);
        if ($this->ownedPodsByDeployment !== []) {
            return $this->ownedPodsByDeployment[$identity->deployment] ?? [];
        }

        return $this->pods;
    }

    public function applySecret(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function applyDeployment(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function applyService(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function deleteSecret(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function deleteDeployment(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function deleteService(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable();
    }
}

final class FakeRestoreAriClient extends AsteriskAriClient
{
    public int $openAttempts = 0;

    public function __construct(
        private readonly int $failOpenAttempts = 0,
    ) {
        parent::__construct(new AsteriskCatalog, app(AsteriskAriProfileService::class));
    }

    public function inspect(string $tenantId, string $runtimeNodeId): array
    {
        return [
            'runtime_node_id' => $runtimeNodeId,
            'asterisk_version' => '20.20.1',
            'system_name' => 'restore-listener-test',
            'configuration_generation' => 2,
            'auth_generation' => 1,
        ];
    }

    public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
    {
        return [
            'bridge_exists' => false,
            'bridge_channel_count' => 0,
            'owned_bridge' => false,
            'participant_channel_checked' => $participantId !== null,
            'participant_channel_exists' => false,
            'participant_channel_in_bridge' => false,
        ];
    }

    public function openWebSocket(string $tenantId, string $runtimeNodeId)
    {
        $this->openAttempts++;
        if ($this->openAttempts <= $this->failOpenAttempts) {
            throw new AsteriskAriException(FailureClass::TransientTransport, 'ari_restore_listener_test_unavailable', 'Test ARI listener unavailable.', true);
        }

        return fopen('php://temp', 'rb+');
    }

    public function closeWebSocket(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function stasisApplicationRegistered(string $tenantId, string $runtimeNodeId): bool
    {
        return true;
    }

    public function readEvent(mixed $stream): ?array
    {
        return null;
    }
}
