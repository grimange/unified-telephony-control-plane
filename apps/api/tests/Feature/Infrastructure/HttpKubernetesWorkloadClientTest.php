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

    public function test_owned_pod_list_uses_controller_owner_chain_for_exact_deployment_a(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology();

        $pods = (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari'));

        $this->assertCount(1, $pods);
        $this->assertSame('asterisk-ari-74b5d7d9-aa111', $pods[0]['metadata']['name']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/apis/apps/v1/namespaces/utcp-runtime/replicasets'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'labelSelector=app.kubernetes.io%2Fpart-of%3Dutcp%2Capp.kubernetes.io%2Fcomponent%3Dasterisk-ari'));
    }

    public function test_owned_pod_list_uses_controller_owner_chain_for_exact_deployment_b(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(targetDeployment: 'asterisk-ari-b');

        $pods = (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari-b'));

        $this->assertCount(1, $pods);
        $this->assertSame('asterisk-ari-b-8557bd4d76-bb222', $pods[0]['metadata']['name']);
    }

    public function test_prefix_collision_never_grants_sibling_pod_ownership(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology();

        $pods = (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari'));

        $this->assertSame(['asterisk-ari-74b5d7d9-aa111'], array_map(
            static fn (array $pod): string => (string) data_get($pod, 'metadata.name'),
            $pods,
        ));
    }

    public function test_deployment_uid_mismatch_rejects_identical_names(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(
            targetDeploymentUid: 'deployment-a-uid',
            replicaSets: [
                $this->replicaSet('rs-a', 'replicaset-a-uid', 'asterisk-ari', 'different-deployment-uid'),
            ],
            pods: [
                $this->pod('same-name-pod', 'pod-a-uid', 'rs-a', 'replicaset-a-uid'),
            ],
        );

        $this->assertSame([], (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari')));
    }

    public function test_replicaset_owner_mismatch_rejects_pod(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(
            replicaSets: [
                $this->replicaSet('rs-a', 'replicaset-a-uid', 'asterisk-ari-b', 'deployment-b-uid'),
            ],
            pods: [
                $this->pod('asterisk-ari-74b5d7d9-aa111', 'pod-a-uid', 'rs-a', 'replicaset-a-uid'),
            ],
        );

        $this->assertSame([], (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari')));
    }

    public function test_pod_without_controller_owner_reference_is_not_owned(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(pods: [['metadata' => ['name' => 'orphan', 'uid' => 'pod-orphan']]]);

        $this->assertSame([], (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari')));
    }

    public function test_missing_replicaset_does_not_fall_back_to_prefix(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(
            replicaSets: [],
            pods: [
                $this->pod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-uid', 'asterisk-ari-b-8557bd4d76', 'missing-rs-uid'),
            ],
        );

        $this->assertSame([], (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari')));
    }

    public function test_replicaset_without_deployment_controller_is_not_owned(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(
            replicaSets: [[
                'kind' => 'ReplicaSet',
                'metadata' => [
                    'name' => 'asterisk-ari-74b5d7d9',
                    'uid' => 'replicaset-a-uid',
                    'ownerReferences' => [['kind' => 'Job', 'name' => 'not-a-deployment', 'uid' => 'job-uid', 'controller' => true]],
                ],
            ]],
            pods: [
                $this->pod('asterisk-ari-74b5d7d9-aa111', 'pod-a-uid', 'asterisk-ari-74b5d7d9', 'replicaset-a-uid'),
            ],
        );

        $this->assertSame([], (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari')));
    }

    public function test_multiple_target_pods_and_rollout_replicasets_are_all_recognized(): void
    {
        $this->configureCredentials('token');
        $this->fakeOwnershipTopology(
            replicaSets: [
                $this->replicaSet('asterisk-ari-74b5d7d9', 'replicaset-a-old-uid', 'asterisk-ari', 'deployment-a-uid'),
                $this->replicaSet('asterisk-ari-95ac3f120a', 'replicaset-a-new-uid', 'asterisk-ari', 'deployment-a-uid'),
                $this->replicaSet('asterisk-ari-b-8557bd4d76', 'replicaset-b-uid', 'asterisk-ari-b', 'deployment-b-uid'),
            ],
            pods: [
                $this->pod('asterisk-ari-74b5d7d9-aa111', 'pod-a-old-uid', 'asterisk-ari-74b5d7d9', 'replicaset-a-old-uid'),
                $this->pod('asterisk-ari-95ac3f120a-cc333', 'pod-a-new-uid', 'asterisk-ari-95ac3f120a', 'replicaset-a-new-uid'),
                $this->pod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-uid', 'asterisk-ari-b-8557bd4d76', 'replicaset-b-uid'),
            ],
        );

        $pods = (new HttpKubernetesWorkloadClient)->listOwnedPods('utcp-runtime', new RuntimeNodeWorkloadIdentity('utcp-runtime', 'asterisk-ari'));

        $this->assertSame([
            'asterisk-ari-74b5d7d9-aa111',
            'asterisk-ari-95ac3f120a-cc333',
        ], array_map(static fn (array $pod): string => (string) data_get($pod, 'metadata.name'), $pods));
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

    /**
     * @param  list<array<string, mixed>>|null  $replicaSets
     * @param  list<array<string, mixed>>|null  $pods
     */
    private function fakeOwnershipTopology(
        string $targetDeployment = 'asterisk-ari',
        string $targetDeploymentUid = 'deployment-a-uid',
        ?array $replicaSets = null,
        ?array $pods = null,
    ): void {
        $replicaSets ??= [
            $this->replicaSet('asterisk-ari-74b5d7d9', 'replicaset-a-uid', 'asterisk-ari', 'deployment-a-uid'),
            $this->replicaSet('asterisk-ari-b-8557bd4d76', 'replicaset-b-uid', 'asterisk-ari-b', 'deployment-b-uid'),
        ];
        $pods ??= [
            $this->pod('asterisk-ari-74b5d7d9-aa111', 'pod-a-uid', 'asterisk-ari-74b5d7d9', 'replicaset-a-uid'),
            $this->pod('asterisk-ari-b-8557bd4d76-bb222', 'pod-b-uid', 'asterisk-ari-b-8557bd4d76', 'replicaset-b-uid'),
        ];
        if ($targetDeployment === 'asterisk-ari-b' && $targetDeploymentUid === 'deployment-a-uid') {
            $targetDeploymentUid = 'deployment-b-uid';
        }

        Http::fake(function (Request $request) use ($targetDeployment, $targetDeploymentUid, $replicaSets, $pods) {
            if (str_ends_with($request->url(), '/apis/apps/v1/namespaces/utcp-runtime/deployments/'.$targetDeployment)) {
                return Http::response($this->deployment($targetDeployment, $targetDeploymentUid));
            }
            if (str_ends_with($request->url(), '/apis/apps/v1/namespaces/utcp-runtime/replicasets')) {
                return Http::response(['kind' => 'ReplicaSetList', 'items' => $replicaSets]);
            }
            if (str_contains($request->url(), '/api/v1/namespaces/utcp-runtime/pods?')) {
                return Http::response(['kind' => 'PodList', 'items' => $pods]);
            }

            return Http::response(['kind' => 'Status'], 404);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function deployment(string $name, string $uid): array
    {
        return [
            'kind' => 'Deployment',
            'metadata' => [
                'namespace' => 'utcp-runtime',
                'name' => $name,
                'uid' => $uid,
            ],
            'spec' => ['replicas' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replicaSet(string $name, string $uid, string $deploymentName, string $deploymentUid): array
    {
        return [
            'kind' => 'ReplicaSet',
            'metadata' => [
                'namespace' => 'utcp-runtime',
                'name' => $name,
                'uid' => $uid,
                'ownerReferences' => [[
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'name' => $deploymentName,
                    'uid' => $deploymentUid,
                    'controller' => true,
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pod(string $name, string $uid, string $replicaSetName, string $replicaSetUid): array
    {
        return [
            'kind' => 'Pod',
            'metadata' => [
                'namespace' => 'utcp-runtime',
                'name' => $name,
                'uid' => $uid,
                'ownerReferences' => [[
                    'apiVersion' => 'apps/v1',
                    'kind' => 'ReplicaSet',
                    'name' => $replicaSetName,
                    'uid' => $replicaSetUid,
                    'controller' => true,
                ]],
            ],
        ];
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
