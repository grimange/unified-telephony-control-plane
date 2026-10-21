<?php

// Isolated proof only. No application autoloading or production test registration.
use App\Infrastructure\Kubernetes\KubernetesHostVisibilityService;
use App\Infrastructure\Kubernetes\KubernetesInfrastructureClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PhpContractParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents('/contracts/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_shared_normalized_fixture_preserves_existing_host_and_pod_subset(): void
    {
        $snapshot = $this->fixture('fixtures/observation-complete.json');
        $nodes = array_map(function (array $node): array {
            $labels = [];
            foreach (['region' => 'topology.kubernetes.io/region', 'zone' => 'topology.kubernetes.io/zone', 'hostname' => 'kubernetes.io/hostname'] as $key => $label) {
                if ($node['topology'][$key] !== null) $labels[$label] = $node['topology'][$key];
            }
            return ['metadata' => ['name' => $node['name'], 'uid' => $node['uid'], 'labels' => $labels], 'spec' => ['unschedulable' => $node['unschedulable'], 'taints' => $node['taints']], 'status' => ['conditions' => $node['conditions'], 'addresses' => $node['addresses'], 'capacity' => $node['capacity'], 'allocatable' => $node['allocatable']]];
        }, $snapshot['nodes']);
        $pods = array_map(fn (array $pod): array => ['metadata' => ['name' => $pod['name'], 'namespace' => $pod['namespace'], 'labels' => $pod['labels']], 'spec' => ['nodeName' => $pod['node_name']], 'status' => ['phase' => $pod['phase']]], $snapshot['pods']);
        $this->app->instance(KubernetesInfrastructureClient::class, new class($nodes, $pods) implements KubernetesInfrastructureClient {
            public function __construct(private array $nodes, private array $pods) {}
            public function listNodes(): array { return $this->nodes; }
            public function listPods(): array { return $this->pods; }
        });
        $hosts = app(KubernetesHostVisibilityService::class)->hosts();
        $this->assertCount(1, $hosts);
        $this->assertSame($snapshot['nodes'][0]['uid'], $hosts[0]['uid']);
        $this->assertSame($snapshot['nodes'][0]['name'], $hosts[0]['name']);
        $this->assertSame($snapshot['nodes'][0]['ready'] === 'True', $hosts[0]['ready']);
        $this->assertSame($snapshot['nodes'][0]['unschedulable'], $hosts[0]['unschedulable']);
        $this->assertSame($snapshot['pods'][0]['name'], $hosts[0]['workloads'][0]['name']);
        $this->assertSame($snapshot['pods'][0]['namespace'], $hosts[0]['workloads'][0]['namespace']);
        $this->assertSame($snapshot['pods'][0]['phase'], $hosts[0]['workloads'][0]['phase']);
        $this->assertNull($hosts[0]['workloads'][0]['runtime_node_id']);
        foreach (['conditions', 'addresses', 'capacity', 'allocatable', 'labels', 'taints'] as $key) {
            $this->assertEquals($snapshot['nodes'][0][$key], $hosts[0][$key], $key);
        }
    }

    public function test_fingerprint_has_lossless_php_go_encoding_parity(): void
    {
        $a = $this->fixture('fixtures/action-admit.json');
        $t = $a['target'];
        $fields = [$a['contract_version'], $a['tenant_id'], $a['runtime_node_id'], $t['cluster_id'], $t['namespace'], $t['kind'], $t['name'], $t['runtime_node_slug'], $t['target_id'], $t['incarnation_id'], $t['expected_uid'], $a['target_generation'], 'set_replicas', (string) $a['replicas']];
        $input = implode('', array_map(fn (string $s): string => strlen($s).':'.$s, $fields));
        $this->assertSame($a['intent_fingerprint'], hash('sha256', $input));
    }

    public function test_compatibility_error_projection_uses_existing_php_exception_reasons(): void
    {
        $known = [
            KubernetesWorkloadClientException::unavailable()->reason,
            KubernetesWorkloadClientException::forbidden()->reason,
            KubernetesWorkloadClientException::targetMismatch()->reason,
            KubernetesWorkloadClientException::conflict()->reason,
            KubernetesWorkloadClientException::ownershipConflict()->reason,
            KubernetesWorkloadClientException::invalidRequest()->reason,
            KubernetesWorkloadClientException::failed()->reason,
        ];
        foreach ($this->fixture('errors.json')['php_exception_reasons'] as $outcome => $reason) {
            if ($reason !== null) $this->assertContains($reason, $known, $outcome);
        }
        $this->assertSame('unavailable_to_control', $this->fixture('errors.json')['php_exception_reasons']['outcome_unknown']);
        $this->assertSame('observe_first', $this->fixture('fixtures/result-outcome_unknown.json')['retry']);
    }
}
