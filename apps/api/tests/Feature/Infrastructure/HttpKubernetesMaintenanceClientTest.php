<?php

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Kubernetes\HttpKubernetesMaintenanceClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpKubernetesMaintenanceClientTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) unlink($path);
        }
        parent::tearDown();
    }

    public function test_drainable_pods_are_scoped_to_affected_runtime_workloads_on_target_node(): void
    {
        $this->configureCredentials();
        Http::fake(fn (Request $request) => Http::response(['items' => [
            $this->pod('runtime-a', 'asterisk-a', 'Running', owner: 'ReplicaSet'),
            $this->pod('runtime-b', 'asterisk-b', 'Running', owner: 'ReplicaSet'),
            $this->pod('api', 'api', 'Running', owner: 'ReplicaSet'),
            $this->pod('scheduler', 'scheduler', 'Running', owner: 'ReplicaSet'),
            $this->pod('reconciler', 'telephony-reconciler', 'Running', owner: 'ReplicaSet'),
            $this->pod('postgres', 'postgres', 'Running', owner: 'StatefulSet'),
            $this->pod('redis', 'redis', 'Running', owner: 'StatefulSet'),
            $this->pod('wrong-node', 'asterisk-a', 'Running', owner: 'ReplicaSet', node: 'utcp-dev02'),
        ]]));

        $pods = (new HttpKubernetesMaintenanceClient)->drainablePods('utcp-dev01', [
            ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-a'],
        ]);

        $this->assertSame([
            ['namespace' => 'utcp-runtime', 'name' => 'runtime-a'],
        ], $pods);
    }

    public function test_existing_drain_exclusions_remain_applied_to_subject_workloads(): void
    {
        $this->configureCredentials();
        Http::fake(fn () => Http::response(['items' => [
            $this->pod('daemon', 'asterisk-a', 'Running', owner: 'DaemonSet'),
            $this->pod('mirror', 'asterisk-a', 'Running', mirror: true),
            $this->pod('terminating', 'asterisk-a', 'Running', deleting: true),
            $this->pod('succeeded', 'asterisk-a', 'Succeeded'),
            $this->pod('failed', 'asterisk-a', 'Failed'),
            $this->pod('eligible', 'asterisk-a', 'Running'),
        ]]));

        $pods = (new HttpKubernetesMaintenanceClient)->drainablePods('utcp-dev01', [
            ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-a'],
        ]);

        $this->assertSame([
            ['namespace' => 'utcp-runtime', 'name' => 'eligible'],
        ], $pods);
    }

    /** @return array<string, mixed> */
    private function pod(string $name, string $deployment, string $phase, string $owner = 'Deployment', string $node = 'utcp-dev01', bool $mirror = false, bool $deleting = false): array
    {
        $pod = [
            'metadata' => [
                'name' => $name,
                'namespace' => 'utcp-runtime',
                'labels' => [
                    'app.kubernetes.io/part-of' => 'utcp',
                    'app.kubernetes.io/instance' => $deployment,
                ],
                'ownerReferences' => [['kind' => $owner]],
            ],
            'spec' => ['nodeName' => $node],
            'status' => ['phase' => $phase],
        ];
        if ($mirror) $pod['metadata']['annotations'] = ['kubernetes.io/config.mirror' => 'mirror-uid'];
        if ($deleting) $pod['metadata']['deletionTimestamp'] = '2026-08-31T00:00:00Z';
        return $pod;
    }

    private function configureCredentials(): void
    {
        $token = tempnam(sys_get_temp_dir(), 'utcp-k5d-token-');
        $ca = tempnam(sys_get_temp_dir(), 'utcp-k5d-ca-');
        $this->temporaryFiles = array_values(array_filter([$token, $ca], is_string(...)));
        file_put_contents($token, 'token');
        file_put_contents($ca, 'ca');
        config()->set('runtime_engine.kubernetes.service_host', 'kubernetes.default.svc');
        config()->set('runtime_engine.kubernetes.service_port', 443);
        config()->set('runtime_engine.kubernetes.token_path', $token);
        config()->set('runtime_engine.kubernetes.ca_path', $ca);
    }
}
