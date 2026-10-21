<?php

declare(strict_types=1);

use App\Identity\IdentityIds;
use App\Infrastructure\Kubernetes\HttpKubernetesInfrastructureClient;
use App\Infrastructure\Kubernetes\K5CPlacementObservationService;
use App\Infrastructure\Kubernetes\KubernetesHostVisibilityService;
use App\Infrastructure\Kubernetes\KubernetesInfrastructureClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Utcp\GnG1Proof\SnapshotInfrastructureClient;

require_once __DIR__.'/SnapshotInfrastructureClient.php';

final class ObservationParityTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) unlink($path);
        parent::tearDown();
    }

    public static function completeCases(): array
    {
        return array_map(static fn (string $s): array => [$s], ['healthy', 'missing-topology', 'multiple', 'missing-pod', 'terminated', 'ownership-mismatch', 'missing-node', 'empty', 'multipage']);
    }

    #[DataProvider('completeCases')]
    public function test_actual_go_snapshot_preserves_existing_php_host_and_placement_meaning(string $case): void
    {
        [$runtimeA, $tenantA] = $this->runtimeNode('runtime-a');
        [$runtimeB, $tenantB] = $this->runtimeNode('runtime-b');
        $canonical = DB::table('runtime_nodes')->orderBy('id')->get()->toJson();
        $rawNodes = $this->raw($case, 'nodes')['items'];
        // Intentional v1 restriction: arbitrary Node labels are removed. No existing
        // host/placement policy reads anything beyond these five permitted labels.
        foreach ($rawNodes as &$node) $node['metadata']['labels'] = array_intersect_key($node['metadata']['labels'], array_flip(['kubernetes.io/hostname', 'kubernetes.io/os', 'kubernetes.io/arch', 'topology.kubernetes.io/region', 'topology.kubernetes.io/zone']));
        unset($node);
        $oldClient = $this->httpClient($rawNodes, $this->raw($case, 'pods')['items']);
        $this->bind($oldClient);
        $expectedHosts = app(KubernetesHostVisibilityService::class)->hosts();
        $expectedA = app(KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeA, $tenantA);
        $expectedB = app(KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeB, $tenantB);
        $bridge = $this->bridge($case);
        $this->bind($bridge);
        $actualHosts = app(KubernetesHostVisibilityService::class)->hosts();
        $actualA = app(KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeA, $tenantA);
        $this->assertEquals($expectedHosts, $actualHosts, $case.' host meaning');
        $this->assertEquals($expectedA, $actualA, $case.' runtime-a placement');
        $this->assertEquals($expectedB, app(KubernetesHostVisibilityService::class)->placementForRuntimeNode($runtimeB, $tenantB));
        $this->assertSame($canonical, DB::table('runtime_nodes')->orderBy('id')->get()->toJson(), 'read-only bridge/visibility cannot change desired state');
        $this->assertSame(0, DB::table('runtime_node_k5c_placement_observations')->count(), 'Go/bridge do not persist observations');
        $result = app(K5CPlacementObservationService::class)->refresh();
        $this->assertSame(['observed' => 2, 'unavailable' => 0], $result);
        $this->assertSame(2, DB::table('runtime_node_k5c_placement_observations')->count(), 'only existing PHP engine persists observations');
        $this->assertSame($actualA['status'], DB::table('runtime_node_k5c_placement_observations')->where('runtime_node_id', $runtimeA)->value('status'));
        $this->assertStringNotContainsString('unsafe-fixture-marker', json_encode($actualHosts));
        if ($case === 'ownership-mismatch') {
            $this->assertNull($actualHosts[0]['workloads'][0]['runtime_node_id']);
            $this->assertSame('identity_present_but_not_currently_observed', $actualA['status']);
        }
        if ($case === 'terminated') {
            $this->assertSame('Succeeded', $actualA['workload']['pods'][0]['phase']);
            $this->assertSame('placed', $actualA['status'], 'current PHP placement does not equate phase with telephony termination');
        }
        if ($case === 'missing-node') $this->assertSame('identity_present_but_not_currently_observed', $actualA['status']);
        if ($case === 'missing-topology') {
            $this->assertSame([], $actualA['kubernetes_node']['topology']);
            $this->assertNull(DB::table('runtime_node_k5c_placement_observations')->where('runtime_node_id', $runtimeA)->value('observed_zone'));
        }
        if ($case === 'empty') $this->assertSame([], $actualHosts);
        if ($case === 'multipage') {
            $this->assertGreaterThan(1, $this->snapshot($case)['nodes_status']['pages']);
            $this->assertGreaterThan(1, $this->snapshot($case)['pods_status']['pages']);
        }
    }

    public static function rejectedCases(): array
    {
        return [['incomplete', 'unavailable_to_control'], ['unavailable', 'unavailable_to_control'], ['invalid', 'failed'], ['stale', 'unavailable_to_control']];
    }

    #[DataProvider('rejectedCases')]
    public function test_failed_invalid_or_stale_observation_never_becomes_empty_healthy_state(string $case, string $reason): void
    {
        [$id] = $this->runtimeNode('runtime-a');
        $this->bind($this->bridge($case));
        try {
            app(KubernetesHostVisibilityService::class)->hosts();
            $this->fail('Unavailable snapshot was accepted.');
        } catch (KubernetesWorkloadClientException $e) {
            $this->assertSame($reason, $e->reason);
        }
        $this->assertSame(['observed' => 0, 'unavailable' => 1], app(K5CPlacementObservationService::class)->refresh());
        $row = DB::table('runtime_node_k5c_placement_observations')->where('runtime_node_id', $id)->first();
        $this->assertSame('kubernetes_observation_unavailable', $row->status);
        $this->assertNull($row->observed_kubernetes_node_uid);
        $this->assertNull($row->observed_zone);
    }

    public static function normalizedErrorCases(): array
    {
        $taxonomy = json_decode(file_get_contents('/contracts/errors.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($taxonomy['php_exception_reasons'] as $code => $reason) {
            if ($reason !== null) $cases[$code] = [$code, $reason];
        }
        $cases['unknown-code-fails-closed'] = ['unknown-fixture-error', 'failed'];
        return $cases;
    }

    #[DataProvider('normalizedErrorCases')]
    public function test_every_normalized_error_preserves_normative_php_exception_reason(string $code, string $expectedReason): void
    {
        $snapshot = $this->snapshot('incomplete');
        $snapshot['error']['code'] = $code;
        $bridge = new SnapshotInfrastructureClient($snapshot, new DateTimeImmutable());
        foreach (['listNodes', 'listPods'] as $method) {
            try {
                $bridge->{$method}();
                $this->fail('Normalized observation error was accepted: '.$code);
            } catch (KubernetesWorkloadClientException $e) {
                $this->assertSame($expectedReason, $e->reason, $code.' '.$method);
            }
        }
    }

    public function test_tenant_authorization_and_identity_correlation_remain_in_existing_php_api(): void
    {
        [$id, $tenantId] = $this->runtimeNode('runtime-a');
        [$otherId, $otherTenant] = $this->runtimeNode('runtime-b');
        $this->bind($this->bridge('healthy'));
        $user = User::query()->create(['id' => IdentityIds::new(), 'email' => 'gn-g1@utcp.local.test', 'normalized_email' => 'gn-g1@utcp.local.test', 'display_name' => 'GN-G1 synthetic operator', 'password' => Hash::make('password'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
        $membership = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membership, 'user_id' => $user->id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membership, 'role_key' => 'tenant-admin', 'created_at' => now()]);
        $this->actingAs($user)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])->getJson("/api/v1/admin/runtime-nodes/{$id}/placement")
            ->assertOk()->assertJsonPath('placement.status', 'placed')->assertJsonPath('placement.co_resident_runtime_nodes.0.id', $id);
        $this->actingAs($user)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])->getJson("/api/v1/admin/runtime-nodes/{$otherId}/placement")->assertNotFound();
        $this->actingAs($user)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])->postJson("/api/v1/admin/runtime-nodes/{$id}/placement")->assertMethodNotAllowed();
        $this->assertNotSame('untrusted-tenant-label', $tenantId);
        $this->assertNotSame($tenantId, $otherTenant);
    }

    public function test_bridge_translation_has_no_database_access(): void
    {
        $bridge = $this->bridge('healthy');
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertCount(1, $bridge->listNodes());
        $this->assertCount(1, $bridge->listPods());
        $this->assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public function test_repeated_snapshot_does_not_refresh_its_collection_freshness(): void
    {
        $snapshot = $this->snapshot('healthy');
        $received = new DateTimeImmutable($snapshot['finished_at']);
        $bridge = new SnapshotInfrastructureClient($snapshot, $received);
        $this->assertSame($bridge->listNodes(), $bridge->listNodes());
        $this->expectException(KubernetesWorkloadClientException::class);
        (new SnapshotInfrastructureClient($snapshot, $received->modify('+301 seconds')))->listNodes();
    }

    public function test_partial_facts_and_unsupported_envelopes_are_rejected(): void
    {
        $snapshot = $this->snapshot('healthy');
        $snapshot['complete'] = false;
        $snapshot['error'] = ['code' => 'forbidden'];
        try {
            (new SnapshotInfrastructureClient($snapshot, new DateTimeImmutable()))->listNodes();
            $this->fail('Partial facts accepted.');
        } catch (KubernetesWorkloadClientException $e) {
            $this->assertSame('failed', $e->reason);
        }
        $snapshot['nodes'] = $snapshot['pods'] = null;
        try {
            (new SnapshotInfrastructureClient($snapshot, new DateTimeImmutable()))->listNodes();
            $this->fail('Forbidden snapshot accepted.');
        } catch (KubernetesWorkloadClientException $e) {
            $this->assertSame('permission_denied', $e->reason);
        }
    }

    public function test_actual_current_refresh_request_counts_against_shared_go_snapshot(): void
    {
        $measurements = [];
        foreach ([1, 10, 100] as $count) {
            DB::table('runtime_node_k5c_placement_observations')->delete();
            DB::table('runtime_nodes')->delete();
            DB::table('tenants')->delete();
            for ($i = 0; $i < $count; $i++) $this->runtimeNode(sprintf('runtime-%03d', $i));
            $case = 'performance-'.$count;
            $nodes = $this->raw($case, 'nodes')['items'];
            $pods = $this->raw($case, 'pods')['items'];
            $calls = 0;
            $client = $this->httpClient($nodes, $pods, $calls);
            $this->bind($client);
            $old = $this->measure(fn () => app(K5CPlacementObservationService::class)->refresh());
            $this->assertSame(2 * $count, $calls);
            $this->assertSame(['observed' => $count, 'unavailable' => 0], $old['result']);
            $bridge = $this->bridge($case);
            $this->bind($bridge);
            $shared = $this->measure(fn () => app(K5CPlacementObservationService::class)->refresh());
            $this->assertSame(['observed' => $count, 'unavailable' => 0], $shared['result']);
            $this->assertSame(2 * $count, $bridge->reads);
            $this->assertSame(2 * $count, $calls, 'shared snapshot causes no additional Kubernetes HTTP calls');
            $snapshot = $this->snapshot($case);
            $pages = $snapshot['nodes_status']['pages'] + $snapshot['pods_status']['pages'];
            $this->assertSame(1 + (int) ceil($count / 5), $pages);
            $measurements[] = ['runtime_nodes' => $count, 'fixture_nodes' => 3, 'fixture_pods' => $count,
                'legacy_http_calls' => $calls, 'legacy_paginated_equivalent_calls' => $count * $pages,
                'shared_go_acquisition_pages' => $pages, 'shared_php_additional_kubernetes_calls' => 0,
                'legacy_php_refresh' => $old, 'shared_php_correlation_and_persistence' => $shared,
                'measurement_kind' => 'single-process SQLite/HTTP-fake developer simulation; Go acquisition measured separately'];
        }
        file_put_contents('/output/php-measurements.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    public function test_current_php_http_client_does_not_follow_list_continuation(): void
    {
        $raw = $this->raw('multipage', 'nodes');
        $client = $this->httpClient([], []);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['kind' => 'NodeList', 'metadata' => ['resourceVersion' => 'rv', 'continue' => 'opaque-next'], 'items' => [$raw['items'][0]]])]);
        $this->assertCount(1, $client->listNodes(), 'source-inspected legacy pagination gap is executable');
        Http::assertSentCount(1);
        $snapshot = $this->snapshot('multipage');
        $this->assertCount(3, $snapshot['nodes'], 'actual Go acquisition completes all fixture pages');
        $this->assertSame(3, $snapshot['nodes_status']['pages']);
    }

    private function measure(callable $work): array
    {
        $cpuBefore = getrusage();
        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);
        $result = $work();
        $wall = (hrtime(true) - $start) / 1e6;
        $cpuAfter = getrusage();
        $cpu = static fn (array $r): float => ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
        return ['wall_ms' => round($wall, 3), 'cpu_ms' => round($cpu($cpuAfter) - $cpu($cpuBefore), 3), 'memory_delta_bytes' => memory_get_usage(true) - $memoryBefore, 'process_peak_memory_bytes' => memory_get_peak_usage(true), 'result' => $result];
    }

    private function raw(string $case, string $kind): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/'.$case.'/'.$kind.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function snapshot(string $case): array
    {
        return json_decode(file_get_contents('/output/scenarios/'.$case.'/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function bridge(string $case): SnapshotInfrastructureClient
    {
        return new SnapshotInfrastructureClient($this->snapshot($case), new DateTimeImmutable());
    }

    private function bind(KubernetesInfrastructureClient $client): void
    {
        $this->app->instance(KubernetesInfrastructureClient::class, $client);
    }

    private function httpClient(array $nodes, array $pods, ?int &$calls = null): HttpKubernetesInfrastructureClient
    {
        $token = tempnam(sys_get_temp_dir(), 'gn-g1-token-');
        $ca = tempnam(sys_get_temp_dir(), 'gn-g1-ca-');
        file_put_contents($token, 'synthetic-test-token');
        file_put_contents($ca, 'synthetic-Http-fake-CA');
        $this->temporaryFiles[] = $token;
        $this->temporaryFiles[] = $ca;
        config(['runtime_engine.kubernetes.token_path' => $token, 'runtime_engine.kubernetes.ca_path' => $ca, 'runtime_engine.kubernetes.service_host' => 'kubernetes.fixture.invalid', 'runtime_engine.kubernetes.service_port' => 443]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function ($request) use ($nodes, $pods, &$calls) {
            $calls++;
            if (str_ends_with($request->url(), '/api/v1/nodes')) return Http::response(['kind' => 'NodeList', 'items' => $nodes]);
            if (str_ends_with($request->url(), '/api/v1/pods')) return Http::response(['kind' => 'PodList', 'items' => $pods]);
            throw new RuntimeException('Unexpected HTTP destination.');
        });
        Http::preventStrayRequests();
        return new HttpKubernetesInfrastructureClient;
    }

    private function runtimeNode(string $slug): array
    {
        $tenant = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => $slug, 'display_name' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id = IdentityIds::new();
        DB::table('runtime_nodes')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => $slug, 'slug' => $slug, 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'labels' => json_encode(['kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => $slug]]), 'created_at' => now(), 'updated_at' => now()]);
        return [$id, $tenant];
    }
}
