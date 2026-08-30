<?php

namespace Tests\Feature\Infrastructure;

use App\Identity\IdentityIds;
use App\Infrastructure\Kubernetes\KubernetesInfrastructureClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KubernetesHostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_nodes_workloads_and_runtime_nodes_in_stable_order(): void
    {
        $runtimeA = $this->runtimeNode('runtime-a', 'Runtime A');
        $runtimeB = $this->runtimeNode('runtime-b', 'Runtime B');
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(
            nodes: [$this->node('node-b', 'uid-b', 'False'), $this->node('node-a', 'uid-a', 'True')],
            pods: [
                $this->pod('pod-b', 'node-a', 'runtime-b', 'Succeeded'),
                $this->pod('pod-a', 'node-a', 'runtime-a', 'Running'),
                ['metadata' => ['name' => 'unrelated', 'namespace' => 'other', 'labels' => ['app.kubernetes.io/part-of' => 'other']], 'spec' => ['nodeName' => 'node-a'], 'status' => ['phase' => 'Running']],
            ],
        ));

        $hosts = app(\App\Infrastructure\Kubernetes\KubernetesHostVisibilityService::class)->hosts();

        $this->assertSame(['node-a', 'node-b'], array_column($hosts, 'name'));
        $this->assertTrue($hosts[0]['ready']);
        $this->assertFalse($hosts[1]['ready']);
        $this->assertSame([['type' => 'Hostname', 'address' => 'node-a.local'], ['type' => 'InternalIP', 'address' => '10.0.0.2']], $hosts[0]['addresses']);
        $this->assertSame(['cpu' => '2', 'memory' => '4Gi'], $hosts[0]['capacity']);
        $this->assertSame(['pod-a', 'pod-b'], array_column($hosts[0]['workloads'], 'name'));
        $this->assertSame([$runtimeA, $runtimeB], array_column($hosts[0]['runtime_nodes'], 'id'));
        $this->assertSame(['Runtime A', 'Runtime B'], array_column($hosts[0]['runtime_nodes'], 'name'));
    }

    public function test_kubernetes_unavailability_is_not_presented_as_an_empty_cluster(): void
    {
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(exception: KubernetesWorkloadClientException::unavailable()));

        $this->expectException(KubernetesWorkloadClientException::class);
        $this->expectExceptionMessage('Kubernetes API is unavailable');

        app(\App\Infrastructure\Kubernetes\KubernetesHostVisibilityService::class)->hosts();
    }

    public function test_runtime_node_placement_is_derived_from_its_managed_workload_and_node(): void
    {
        $runtimeId = $this->runtimeNode('runtime-a', 'Runtime A');
        $tenantId = (string) DB::table('runtime_nodes')->where('id', $runtimeId)->value('tenant_id');
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(
            nodes: [$this->node('node-a', 'uid-a', 'True')],
            pods: [$this->pod('pod-a', 'node-a', 'runtime-a', 'Running')],
        ));

        $placement = app(\App\Infrastructure\Kubernetes\KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeId, $tenantId);

        $this->assertSame('placed', $placement['status']);
        $this->assertSame('node-a', $placement['kubernetes_node']['name']);
        $this->assertSame('zone-a', $placement['kubernetes_node']['topology']['topology.kubernetes.io/zone']);
        $this->assertSame([['id' => $runtimeId, 'name' => 'Runtime A']], $placement['co_resident_runtime_nodes']);
        $this->assertSame('node-a', $placement['workload']['pods'][0]['node_name']);
    }

    public function test_runtime_node_placement_distinguishes_missing_identity_and_unobserved_workload(): void
    {
        $withoutIdentity = IdentityIds::new();
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'placement', 'display_name' => 'Placement', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $withoutIdentity, 'tenant_id' => $tenantId, 'name' => 'No identity', 'slug' => 'no-identity', 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'draft', 'observed_state' => 'unobserved', 'configuration_version' => 1, 'labels' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        $unobserved = $this->runtimeNode('unobserved', 'Unobserved');
        $unobservedTenant = (string) DB::table('runtime_nodes')->where('id', $unobserved)->value('tenant_id');
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(nodes: [$this->node('node-a', 'uid-a', 'True')], pods: []));

        $service = app(\App\Infrastructure\Kubernetes\KubernetesHostVisibilityService::class);
        $this->assertSame('no_managed_kubernetes_identity', $service->placementForRuntimeNode($withoutIdentity, $tenantId)['status']);
        $this->assertSame('identity_present_but_not_currently_observed', $service->placementForRuntimeNode($unobserved, $unobservedTenant)['status']);
    }

    public function test_runtime_node_placement_does_not_select_arbitrarily_when_pods_are_on_multiple_nodes(): void
    {
        $runtimeId = $this->runtimeNode('runtime-a', 'Runtime A');
        $tenantId = (string) DB::table('runtime_nodes')->where('id', $runtimeId)->value('tenant_id');
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(
            nodes: [$this->node('node-a', 'uid-a', 'True'), $this->node('node-b', 'uid-b', 'True')],
            pods: [$this->pod('pod-a', 'node-a', 'runtime-a', 'Running'), $this->pod('pod-b', 'node-b', 'runtime-a', 'Running')],
        ));

        $placement = app(\App\Infrastructure\Kubernetes\KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeId, $tenantId);

        $this->assertSame('ambiguous_multiple_nodes_observed', $placement['status']);
        $this->assertNull($placement['kubernetes_node']);
        $this->assertSame(['pod-a', 'pod-b'], array_column($placement['workload']['pods'], 'name'));
    }

    public function test_runtime_node_placement_api_is_tenant_authorized_and_read_only(): void
    {
        $runtimeId = $this->runtimeNode('runtime-api', 'Runtime API');
        $tenantId = (string) DB::table('runtime_nodes')->where('id', $runtimeId)->value('tenant_id');
        $admin = User::query()->create(['id' => IdentityIds::new(), 'email' => 'placement-admin@utcp.local.test', 'normalized_email' => 'placement-admin@utcp.local.test', 'display_name' => 'Placement Admin', 'password' => Hash::make('password'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $admin->id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(nodes: [$this->node('node-a', 'uid-a', 'True')], pods: [$this->pod('pod-a', 'node-a', 'runtime-api', 'Running')]));

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$runtimeId}/placement")
            ->assertOk()
            ->assertJsonPath('placement.status', 'placed')
            ->assertJsonPath('placement.kubernetes_node.name', 'node-a');
        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$runtimeId}/placement")
            ->assertMethodNotAllowed();
    }

    public function test_hosts_endpoint_is_platform_authorized_and_read_only(): void
    {
        [$admin] = $this->platformAdmin();
        $regular = User::query()->create([
            'id' => IdentityIds::new(), 'email' => 'member@utcp.local.test', 'normalized_email' => 'member@utcp.local.test',
            'display_name' => 'Member', 'password' => Hash::make('password'), 'status' => 'active',
            'password_change_required' => false, 'session_version' => 1,
        ]);
        $this->app->instance(KubernetesInfrastructureClient::class, new FakeKubernetesInfrastructureClient(nodes: [$this->node('node-a', 'uid-a', 'True')]));

        $this->actingAs($regular)->withSession(['user_session_version' => 1])->getJson('/api/v1/admin/infrastructure/hosts')->assertForbidden();
        $response = $this->actingAs($admin)->withSession(['user_session_version' => 1])->getJson('/api/v1/admin/infrastructure/hosts')->assertOk();
        $response->assertJsonPath('hosts.0.name', 'node-a');
        $this->actingAs($admin)->withSession(['user_session_version' => 1])->postJson('/api/v1/admin/infrastructure/hosts')->assertMethodNotAllowed();
    }

    private function runtimeNode(string $slug, string $name): string
    {
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id = IdentityIds::new();
        DB::table('runtime_nodes')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug, 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'labels' => json_encode(['kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => $slug]]), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function node(string $name, string $uid, string $ready): array
    {
        return ['metadata' => ['name' => $name, 'uid' => $uid, 'labels' => ['topology.kubernetes.io/zone' => 'zone-a']], 'status' => ['conditions' => [['type' => 'Ready', 'status' => $ready]], 'addresses' => [['type' => 'Hostname', 'address' => $name.'.local'], ['type' => 'InternalIP', 'address' => '10.0.0.2']], 'capacity' => ['cpu' => '2', 'memory' => '4Gi'], 'allocatable' => ['cpu' => '1500m']], 'spec' => ['unschedulable' => false]];
    }

    private function pod(string $name, string $node, ?string $runtime, string $phase): array
    {
        return ['metadata' => ['name' => $name, 'namespace' => 'utcp-runtime', 'labels' => array_filter(['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/instance' => $runtime, 'utcp.dev/runtime-node' => $runtime])], 'spec' => ['nodeName' => $node], 'status' => ['phase' => $phase]];
    }

    private function platformAdmin(): array
    {
        $user = User::query()->create(['id' => IdentityIds::new(), 'email' => 'admin@utcp.local.test', 'normalized_email' => 'admin@utcp.local.test', 'display_name' => 'Admin', 'password' => Hash::make('password'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
        DB::table('platform_role_assignments')->insert(['id' => IdentityIds::new(), 'user_id' => $user->id, 'role_key' => 'platform-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        return [$user];
    }
}

final class FakeKubernetesInfrastructureClient implements KubernetesInfrastructureClient
{
    public function __construct(private array $nodes = [], private array $pods = [], private ?KubernetesWorkloadClientException $exception = null) {}

    public function listNodes(): array
    {
        if ($this->exception !== null) throw $this->exception;
        return $this->nodes;
    }

    public function listPods(): array
    {
        if ($this->exception !== null) throw $this->exception;
        return $this->pods;
    }
}
