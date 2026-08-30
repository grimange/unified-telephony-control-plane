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
