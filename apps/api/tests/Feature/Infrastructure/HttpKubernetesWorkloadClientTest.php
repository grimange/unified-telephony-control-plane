<?php

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\RuntimeFencing\HttpKubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpKubernetesWorkloadClientTest extends TestCase
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

    public function test_get_deployment_parses_the_expected_object(): void
    {
        $this->configureCredentials('token');
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = [$request->method(), $request->url()];

            return Http::response([
                'kind' => 'Deployment',
                'metadata' => ['namespace' => 'utcp-runtime', 'name' => 'asterisk-ari-a'],
                'spec' => ['replicas' => 1],
            ]);
        });

        $deployment = (new HttpKubernetesWorkloadClient)->getDeployment('utcp-runtime', 'asterisk-ari-a');

        $this->assertSame('Deployment', $deployment['kind']);
        $this->assertSame([['GET', 'https://kubernetes.default.svc/apis/apps/v1/namespaces/utcp-runtime/deployments/asterisk-ari-a']], $urls);
    }

    public function test_scale_deployment_uses_only_the_scale_subresource(): void
    {
        $this->configureCredentials('token');
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = [$request->method(), $request->url(), $request->data()];

            return Http::response([
                'kind' => 'Scale',
                'spec' => ['replicas' => 0],
            ]);
        });

        (new HttpKubernetesWorkloadClient)->scaleDeployment('utcp-runtime', 'asterisk-ari-a', 0);

        $this->assertSame([[
            'PATCH',
            'https://kubernetes.default.svc/apis/apps/v1/namespaces/utcp-runtime/deployments/asterisk-ari-a/scale',
            ['spec' => ['replicas' => 0]],
        ]], $requests);
    }

    public function test_owned_pod_list_uses_fixed_utcp_selector_and_filters_to_the_deployment(): void
    {
        $this->configureCredentials('token');
        Http::fake(fn () => Http::response([
            'kind' => 'PodList',
            'items' => [
                [
                    'metadata' => [
                        'name' => 'owned',
                        'ownerReferences' => [['kind' => 'ReplicaSet', 'name' => 'asterisk-ari-a-74b5d7d9']],
                    ],
                ],
                [
                    'metadata' => [
                        'name' => 'other',
                        'ownerReferences' => [['kind' => 'ReplicaSet', 'name' => 'asterisk-ari-b-74b5d7d9']],
                    ],
                ],
            ],
        ]));

        $pods = (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari-a'));

        $this->assertCount(1, $pods);
        $this->assertSame('owned', $pods[0]['metadata']['name']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'labelSelector=app.kubernetes.io%2Fpart-of%3Dutcp%2Capp.kubernetes.io%2Fcomponent%3Dasterisk-ari'));
    }

    public function test_missing_token_or_ca_fails_closed_without_sending_requests(): void
    {
        $ca = $this->temporaryFile('ca');
        config()->set('runtime_engine.kubernetes.service_host', 'kubernetes.default.svc');
        config()->set('runtime_engine.kubernetes.service_port', 443);
        config()->set('runtime_engine.kubernetes.token_path', '/tmp/utcp-missing-token');
        config()->set('runtime_engine.kubernetes.ca_path', $ca);
        Http::fake();

        try {
            (new HttpKubernetesWorkloadClient)->getDeployment('utcp-runtime', 'asterisk-ari-a');
            $this->fail('missing token did not fail closed');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('unavailable_to_control', $exception->reason);
        }
        Http::assertNothingSent();

        $token = $this->temporaryFile('token');
        config()->set('runtime_engine.kubernetes.token_path', $token);
        config()->set('runtime_engine.kubernetes.ca_path', '/tmp/utcp-missing-ca');

        try {
            (new HttpKubernetesWorkloadClient)->getDeployment('utcp-runtime', 'asterisk-ari-a');
            $this->fail('missing CA did not fail closed');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('unavailable_to_control', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_token_is_reread_between_requests(): void
    {
        $tokenPath = $this->configureCredentials('token-one');
        $authorization = [];
        Http::fake(function (Request $request) use (&$authorization) {
            $authorization[] = $request->header('Authorization')[0] ?? '';

            return Http::response(['kind' => 'Deployment']);
        });

        $client = new HttpKubernetesWorkloadClient;
        $client->getDeployment('utcp-runtime', 'asterisk-ari-a');
        file_put_contents($tokenPath, 'token-two');
        $client->getDeployment('utcp-runtime', 'asterisk-ari-a');

        $this->assertSame(['Bearer token-one', 'Bearer token-two'], $authorization);
    }

    public function test_http_errors_are_mapped_to_fence_safe_reasons(): void
    {
        $this->configureCredentials('sensitive-token-value');

        Http::fakeSequence()
            ->push(['kind' => 'Status', 'message' => 'bounded'], 403)
            ->push(['kind' => 'Status', 'message' => 'bounded'], 404)
            ->push(['kind' => 'Status', 'message' => 'bounded'], 409)
            ->push(['kind' => 'Status', 'message' => 'bounded'], 500);

        foreach ([403 => 'permission_denied', 404 => 'target_mismatch', 409 => 'fence_in_progress', 500 => 'unavailable_to_control'] as $status => $reason) {
            try {
                (new HttpKubernetesWorkloadClient)->scaleDeployment('utcp-runtime', 'asterisk-ari-a', 0);
                $this->fail('HTTP '.$status.' did not fail closed');
            } catch (KubernetesWorkloadClientException $exception) {
                $this->assertSame($reason, $exception->reason);
                $this->assertStringNotContainsString('sensitive-token-value', $exception->getMessage());
            }
        }
    }

    public function test_timeout_fails_closed(): void
    {
        $this->configureCredentials('token');
        Http::fake(fn () => throw new ConnectionException('timeout'));

        try {
            (new HttpKubernetesWorkloadClient)->getDeployment('utcp-runtime', 'asterisk-ari-a');
            $this->fail('timeout did not fail closed');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('unavailable_to_control', $exception->reason);
        }
    }

    public function test_malformed_objects_fail_closed(): void
    {
        $this->configureCredentials('token');
        Http::fake(fn () => Http::response(['kind' => 'Service']));
        try {
            (new HttpKubernetesWorkloadClient)->getDeployment('utcp-runtime', 'asterisk-ari-a');
            $this->fail('malformed response did not fail closed');
        } catch (KubernetesWorkloadClientException $exception) {
            $this->assertSame('failed', $exception->reason);
        }
    }

    private function configureCredentials(string $token): string
    {
        $tokenPath = $this->temporaryFile($token);
        $caPath = $this->temporaryFile('ca');
        config()->set('runtime_engine.kubernetes.service_host', 'kubernetes.default.svc');
        config()->set('runtime_engine.kubernetes.service_port', 443);
        config()->set('runtime_engine.kubernetes.token_path', $tokenPath);
        config()->set('runtime_engine.kubernetes.ca_path', $caPath);
        config()->set('runtime_engine.kubernetes.connect_timeout_seconds', 2);
        config()->set('runtime_engine.kubernetes.request_timeout_seconds', 5);

        return $tokenPath;
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'utcp-kube-client-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
