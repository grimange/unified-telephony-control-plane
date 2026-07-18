<?php

namespace Tests\Feature\Infrastructure;

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
use App\RuntimeEngine\Commands\CommandWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        DB::table('runtime_operations')->where('id', $operationId)->update(['available_at' => now()->subSecond()]);
        $fake->deployment = $this->deployment('asterisk-ari-worker', 'conference-runtime-worker', 0, 0, 0);
        $fake->pods = [];
        app(CommandWorker::class)->workOnce('runtime-fence-worker-2', 1);

        $this->assertSame('succeeded', DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-worker', 0]], $fake->scaleCalls);
    }

    private function adapter(FakeKubernetesWorkloadClient $fake): KubernetesRuntimeFenceAdapter
    {
        return new KubernetesRuntimeFenceAdapter($fake, app(RuntimeNodeWorkloadIdentityResolver::class), app(KubernetesRuntimeWorkloadInspector::class));
    }

    private function bindFakeClient(FakeKubernetesWorkloadClient $fake): void
    {
        $this->app->instance(KubernetesWorkloadClient::class, $fake);
        $this->app->forgetInstance(InfrastructureAdapterRegistry::class);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNode(string $slug, string $observedState = 'unavailable', ?array $labels = null): array
    {
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
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
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
}

final class FakeKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    /** @var list<array{0:string,1:string}> */
    public array $getCalls = [];

    /** @var list<array{0:string,1:string,2:int}> */
    public array $scaleCalls = [];

    /** @var list<array<string, mixed>> */
    public array $pods = [];

    /** @var list<array<string, mixed>> */
    public array $afterScalePods = [];

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
        }
    }

    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        unset($namespace, $identity);

        return $this->pods;
    }
}
