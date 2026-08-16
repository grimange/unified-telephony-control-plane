<?php

namespace Tests\Feature\Infrastructure;

use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\InfrastructureConnectivityProbe;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InfrastructureConnectivityProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_probe_succeeds_for_active_ready_node_with_valid_workload_identity(): void
    {
        $this->runtimeNode('probe-a');
        $fake = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-probe-a', 'conference-runtime-probe-a', 1, 1, 1));
        $fake->pods = [['metadata' => ['name' => 'asterisk-ari-probe-a-1']]];
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $result = app(InfrastructureConnectivityProbe::class)->runOnce();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('conference-runtime-probe-a', $result['runtime_node_slug']);
        $this->assertSame('utcp-runtime', $result['namespace']);
        $this->assertSame('asterisk-ari-probe-a', $result['deployment']);
        $this->assertSame(1, $result['desired_replicas']);
        $this->assertSame(1, $result['status_replicas']);
        $this->assertSame(1, $result['available_replicas']);
        $this->assertSame(1, $result['owned_pod_count']);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-probe-a']], $fake->getCalls);
        $this->assertSame([['utcp-runtime', 'asterisk-ari-probe-a']], $fake->podListCalls);
        $this->assertSame([], $fake->scaleCalls);
        $this->assertDatabaseCount('runtime_operations', 0);
        $this->assertDatabaseCount('conference_runtime_bindings', 0);
    }

    public function test_probe_command_outputs_bounded_evidence_without_secret_values(): void
    {
        $this->runtimeNode('command');
        $fake = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-command', 'conference-runtime-command', 2, 2, 1));
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $this->artisan('runtime-engine:infrastructure-probe --once')
            ->expectsOutput('runtime_node_slug=conference-runtime-command')
            ->expectsOutput('namespace=utcp-runtime')
            ->expectsOutput('deployment=asterisk-ari-command')
            ->expectsOutput('desired_replicas=2')
            ->expectsOutput('status_replicas=2')
            ->expectsOutput('available_replicas=1')
            ->expectsOutput('owned_pod_count=0')
            ->assertExitCode(0);

        $output = Artisan::output();
        $this->assertStringNotContainsString('token', $output);
        $this->assertStringNotContainsString('secret', strtolower($output));
        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_probe_returns_nonzero_when_no_eligible_runtime_node_exists(): void
    {
        $this->runtimeNode('not-ready', observedState: 'degraded');
        $this->app->instance(KubernetesWorkloadClient::class, ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-not-ready', 'conference-runtime-not-ready', 1, 1, 1)));

        $this->artisan('runtime-engine:infrastructure-probe --once')
            ->expectsOutputToContain('reason=eligible_runtime_node_missing')
            ->assertExitCode(1);
    }

    public function test_probe_returns_nonzero_when_workload_identity_is_missing(): void
    {
        $this->runtimeNode('missing-workload', labels: ['purpose' => 'probe-test']);
        $this->app->instance(KubernetesWorkloadClient::class, ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-missing-workload', 'conference-runtime-missing-workload', 1, 1, 1)));

        $this->artisan('runtime-engine:infrastructure-probe --once')
            ->expectsOutputToContain('reason=workload_identity_missing')
            ->assertExitCode(1);
    }

    public function test_probe_returns_nonzero_for_ownership_mismatch(): void
    {
        $this->runtimeNode('mismatch');
        $fake = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-mismatch', 'other-runtime-node', 1, 1, 1));
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $this->artisan('runtime-engine:infrastructure-probe --once')
            ->expectsOutputToContain('reason=target_mismatch')
            ->assertExitCode(1);

        $this->assertSame([], $fake->scaleCalls);
    }

    public function test_probe_returns_nonzero_for_permission_denial_api_unavailable_and_malformed_response(): void
    {
        $this->runtimeNode('denied');
        $denied = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-denied', 'conference-runtime-denied', 1, 1, 1));
        $denied->getException = KubernetesWorkloadClientException::forbidden();
        $this->app->instance(KubernetesWorkloadClient::class, $denied);
        $this->assertSame('permission_denied', app(InfrastructureConnectivityProbe::class)->runOnce()['reason']);

        DB::table('runtime_nodes')->truncate();
        $this->runtimeNode('unavailable');
        $unavailable = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-unavailable', 'conference-runtime-unavailable', 1, 1, 1));
        $unavailable->getException = KubernetesWorkloadClientException::unavailable();
        $this->app->instance(KubernetesWorkloadClient::class, $unavailable);
        $this->assertSame('unavailable_to_control', app(InfrastructureConnectivityProbe::class)->runOnce()['reason']);

        DB::table('runtime_nodes')->truncate();
        $this->runtimeNode('malformed');
        $malformed = ProbeFakeKubernetesWorkloadClient::withDeployment($this->deployment('asterisk-ari-malformed', 'conference-runtime-malformed', 1, 1, 1));
        $malformed->listException = KubernetesWorkloadClientException::failed();
        $this->app->instance(KubernetesWorkloadClient::class, $malformed);
        $this->assertSame('failed', app(InfrastructureConnectivityProbe::class)->runOnce()['reason']);
    }

    public function test_probe_command_has_no_target_or_mutation_options_and_requires_once(): void
    {
        $command = Artisan::all()['runtime-engine:infrastructure-probe'];
        $options = array_keys($command->getDefinition()->getOptions());

        $this->assertContains('once', $options);
        foreach (['namespace', 'deployment', 'pod', 'runtime-node', 'conference', 'operation', 'scale', 'force'] as $forbidden) {
            $this->assertNotContains($forbidden, $options);
        }

        $this->artisan('runtime-engine:infrastructure-probe')
            ->expectsOutputToContain('requires --once')
            ->assertExitCode(2);
    }

    private function runtimeNode(string $slug, string $observedState = 'ready', ?array $labels = null): string
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
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime '.$slug,
            'slug' => 'conference-runtime-'.$slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
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

        return $nodeId;
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

final class ProbeFakeKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    public function inspectResource(string $kind, string $name): ?array
    {
        unset($kind, $name);

        return null;
    }

    /** @var list<array{0:string,1:string}> */
    public array $getCalls = [];

    /** @var list<array{0:string,1:string}> */
    public array $podListCalls = [];

    /** @var list<array{0:string,1:string,2:int}> */
    public array $scaleCalls = [];

    /** @var list<array<string, mixed>> */
    public array $pods = [];

    public ?KubernetesWorkloadClientException $getException = null;

    public ?KubernetesWorkloadClientException $listException = null;

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
        $this->scaleCalls[] = [$namespace, $name, $replicas];
    }

    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        $this->podListCalls[] = [$namespace, $identity->deployment];
        if ($this->listException !== null) {
            throw $this->listException;
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
