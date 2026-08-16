<?php

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\RuntimeFencing\HttpKubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KubernetesResourceWriterTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_secret_is_created_owned_converged_and_sanitized(): void
    {
        $this->configureCredentials();
        $requests = [];
        $requestNumber = 0;
        Http::fake(function (Request $request) use (&$requests, &$requestNumber) {
            $requestNumber++;
            $requests[] = [$request->method(), $request->url(), $request->data()];
            if ($requestNumber === 1 || $requestNumber === 3) {
                if ($requestNumber === 3) {
                    return Http::response([
                        'apiVersion' => 'v1',
                        'kind' => 'Secret',
                        'metadata' => [
                            'name' => 'ari-credentials',
                            'namespace' => 'utcp-runtime',
                            'labels' => [
                                'app.kubernetes.io/part-of' => 'utcp',
                                'utcp.dev/runtime-node' => 'runtime-a',
                            ],
                        ],
                    ]);
                }

                return Http::response(['kind' => 'Status'], 404);
            }

            return Http::response([
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => 'ari-credentials', 'namespace' => 'utcp-runtime'],
                'type' => 'Opaque',
                'data' => ['password' => base64_encode('sensitive-secret')],
            ]);
        });

        $result = (new HttpKubernetesWorkloadClient)->applySecret($this->secret(), 'runtime-a');

        $this->assertArrayNotHasKey('data', $result);
        $this->assertStringNotContainsString('sensitive-secret', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('POST', $requests[1][0]);
        $this->assertSame('utcp-runtime', $requests[1][2]['metadata']['namespace']);
        $this->assertSame('utcp', $requests[1][2]['metadata']['labels']['app.kubernetes.io/part-of']);
        $this->assertSame('runtime-a', $requests[1][2]['metadata']['labels']['utcp.dev/runtime-node']);

        $converged = (new HttpKubernetesWorkloadClient)->applySecret($this->secret(), 'runtime-a');

        $this->assertArrayNotHasKey('data', $converged);
        $this->assertSame(4, $requestNumber);
        $this->assertSame('PATCH', $requests[3][0]);
    }

    public function test_unowned_resource_is_never_adopted_or_mutated(): void
    {
        $this->configureCredentials();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'apiVersion' => 'v1',
                    'kind' => 'Secret',
                    'metadata' => [
                        'name' => 'ari-credentials',
                        'namespace' => 'utcp-runtime',
                        'labels' => ['utcp.dev/runtime-node' => 'other-runtime'],
                    ],
                ]);
            }

            return Http::response(['kind' => 'Status'], 500);
        });

        try {
            (new HttpKubernetesWorkloadClient)->applySecret($this->secret(), 'runtime-a');
            $this->fail('unowned resource was adopted');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('ownership_conflict', $exception->reason);
        }

        Http::assertSentCount(1);
    }

    public function test_namespace_is_hard_bounded_before_any_request(): void
    {
        $this->configureCredentials();
        Http::fake();

        $desired = $this->secret();
        $desired['metadata']['namespace'] = 'other-namespace';

        try {
            (new HttpKubernetesWorkloadClient)->applySecret($desired, 'runtime-a');
            $this->fail('outside namespace was accepted');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('target_mismatch', $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_deployment_and_service_apply_and_delete_are_owned_and_idempotent(): void
    {
        $this->configureCredentials();
        $deployment = $this->deployment();
        $ownedDeployment = $deployment;
        $ownedDeployment['metadata']['labels'] = [
            'app.kubernetes.io/part-of' => 'utcp',
            'utcp.dev/runtime-node' => 'runtime-a',
        ];
        $getCount = 0;
        Http::fake(function (Request $request) use (&$getCount, $ownedDeployment) {
            if ($request->method() === 'GET') {
                $getCount++;
                if (str_contains($request->url(), '/services/')) {
                    return Http::response(['kind' => 'Status'], 404);
                }
                $response = $getCount === 1
                    ? Http::response(['kind' => 'Status'], 404)
                    : Http::response(['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => $ownedDeployment['metadata']]);

                return $response;
            }

            if ($request->method() === 'POST') {
                return Http::response(['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => ['name' => 'asterisk-ari', 'namespace' => 'utcp-runtime']]);
            }

            return Http::response(['kind' => 'Status']);
        });

        $client = new HttpKubernetesWorkloadClient;
        $client->applyDeployment($deployment, 'runtime-a');
        $this->assertTrue($client->deleteDeployment('asterisk-ari', 'runtime-a'));

        $this->assertFalse($client->deleteService('asterisk-ari', 'runtime-a'));
    }

    public function test_owned_service_converge_preserves_server_managed_fields(): void
    {
        $this->configureCredentials();
        $existing = $this->service();
        $existing['metadata']['labels']['app.kubernetes.io/part-of'] = 'utcp';
        $existing['metadata']['labels']['utcp.dev/runtime-node'] = 'runtime-a';
        $requests = [];
        Http::fake(function (Request $request) use (&$requests, $existing) {
            $requests[] = [$request->method(), $request->data()];

            return $request->method() === 'GET'
                ? Http::response($existing)
                : Http::response(['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => 'asterisk-ari', 'namespace' => 'utcp-runtime']]);
        });

        (new HttpKubernetesWorkloadClient)->applyService($this->service(), 'runtime-a');

        $patch = $requests[1][1];
        $this->assertArrayNotHasKey('clusterIP', $patch['spec']);
        $this->assertArrayNotHasKey('clusterIPs', $patch['spec']);
        $this->assertArrayNotHasKey('ipFamilies', $patch['spec']);
        $this->assertArrayNotHasKey('ipFamilyPolicy', $patch['spec']);
        $this->assertArrayNotHasKey('healthCheckNodePort', $patch['spec']);
    }

    public function test_http_failures_are_classified_without_secret_payloads(): void
    {
        $this->configureCredentials();

        $status = 0;
        Http::fake(function () use (&$status) {
            return Http::response(['message' => 'sensitive-secret'], $status);
        });

        foreach ([401 => 'permission_denied', 403 => 'permission_denied', 409 => 'fence_in_progress', 422 => 'invalid_request', 429 => 'unavailable_to_control', 500 => 'unavailable_to_control'] as $currentStatus => $reason) {
            $status = $currentStatus;

            try {
                (new HttpKubernetesWorkloadClient)->applySecret($this->secret(), 'runtime-a');
                $this->fail('HTTP '.$status.' did not fail');
            } catch (KubernetesWorkloadClientException $exception) {
                $this->assertSame($reason, $exception->reason);
                $this->assertStringNotContainsString('sensitive-secret', $exception->getMessage());
            }
        }

        Http::fake(fn () => throw new ConnectionException('timeout sensitive-secret'));
        try {
            (new HttpKubernetesWorkloadClient)->applySecret($this->secret(), 'runtime-a');
            $this->fail('transport failure did not fail');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('unavailable_to_control', $exception->reason);
            $this->assertStringNotContainsString('sensitive-secret', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function secret(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => 'ari-credentials', 'namespace' => 'utcp-runtime'],
            'type' => 'Opaque',
            'data' => ['password' => base64_encode('sensitive-secret')],
        ];
    }

    /** @return array<string, mixed> */
    private function deployment(): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'asterisk-ari', 'namespace' => 'utcp-runtime'],
            'spec' => ['replicas' => 1, 'selector' => ['matchLabels' => ['app' => 'asterisk']], 'template' => ['metadata' => ['labels' => ['app' => 'asterisk']]]],
        ];
    }

    /** @return array<string, mixed> */
    private function service(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => 'asterisk-ari', 'namespace' => 'utcp-runtime'],
            'spec' => [
                'type' => 'ClusterIP',
                'clusterIP' => '10.43.0.99',
                'clusterIPs' => ['10.43.0.99'],
                'ipFamilies' => ['IPv4'],
                'ipFamilyPolicy' => 'SingleStack',
                'healthCheckNodePort' => 32000,
                'selector' => ['app' => 'asterisk'],
                'ports' => [['name' => 'ari', 'port' => 8088, 'targetPort' => 8088]],
            ],
        ];
    }

    private function configureCredentials(): void
    {
        $tokenPath = tempnam(sys_get_temp_dir(), 'utcp-kube-writer-token-');
        $caPath = tempnam(sys_get_temp_dir(), 'utcp-kube-writer-ca-');
        $this->assertIsString($tokenPath);
        $this->assertIsString($caPath);
        file_put_contents($tokenPath, 'token');
        file_put_contents($caPath, 'ca');
        $this->temporaryFiles = [$tokenPath, $caPath];
        config()->set('runtime_engine.kubernetes.service_host', 'kubernetes.default.svc');
        config()->set('runtime_engine.kubernetes.service_port', 443);
        config()->set('runtime_engine.kubernetes.token_path', $tokenPath);
        config()->set('runtime_engine.kubernetes.ca_path', $caPath);
    }
}
