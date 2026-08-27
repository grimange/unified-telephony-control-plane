<?php

namespace Tests\Feature\RuntimeProvisioning;

use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Models\User;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchCatalog;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchRuntimeNodeReconciler;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeProvisioning\ManagedFreeSwitchProvisioningOperationHandler;
use App\RuntimeProvisioning\ManagedRuntimeResourceIdentity;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class ManagedFreeSwitchProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_and_desired_workload_are_deterministic_and_provider_specific(): void
    {
        $asterisk = ManagedRuntimeResourceIdentity::names('asterisk', 'managed-node', '11111111-2222-3333-4444-555555555555');
        $freeswitch = ManagedRuntimeResourceIdentity::names('freeswitch', 'managed-node', '11111111-2222-3333-4444-555555555555');

        $this->assertSame('asterisk-managed-node-'.substr(hash('sha256', '11111111-2222-3333-4444-555555555555'), 0, 8), $asterisk['deployment']);
        $this->assertSame('freeswitch-managed-node-'.substr(hash('sha256', '11111111-2222-3333-4444-555555555555'), 0, 8), $freeswitch['deployment']);
        $this->assertNotSame($asterisk['deployment'], $freeswitch['deployment']);

        config(['freeswitch_esl.managed_image' => 'ghcr.io/grimange/utcp-freeswitch@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        $this->app->instance(KubernetesWorkloadClient::class, $this->createMock(KubernetesWorkloadClient::class));
        $handler = app(ManagedFreeSwitchProvisioningOperationHandler::class);
        $deployment = $handler->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node');
        $container = $deployment['spec']['template']['spec']['containers'][0];

        $this->assertSame('apps/v1', $deployment['apiVersion']);
        $this->assertSame('Deployment', $deployment['kind']);
        $this->assertSame('utcp-runtime', $deployment['metadata']['namespace']);
        $this->assertSame($freeswitch['deployment'], $deployment['metadata']['name']);
        $this->assertSame([
            'app.kubernetes.io/part-of' => 'utcp',
            'app.kubernetes.io/component' => 'freeswitch-esl',
            'app.kubernetes.io/instance' => $freeswitch['deployment'],
            'utcp.dev/runtime-node' => 'managed-node',
        ], $deployment['metadata']['labels']);
        $this->assertSame([
            'matchLabels' => [
                'app.kubernetes.io/part-of' => 'utcp',
                'app.kubernetes.io/component' => 'freeswitch-esl',
                'utcp.dev/runtime-node' => 'managed-node',
            ],
        ], $deployment['spec']['selector']);
        $this->assertSame([
            ...$deployment['metadata']['labels'],
            'utcp.io/network-role' => 'freeswitch-esl',
        ], $deployment['spec']['template']['metadata']['labels']);
        $this->assertSame('ghcr.io/grimange/utcp-freeswitch@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $container['image']);
        $this->assertSame('freeswitch-esl', $deployment['spec']['template']['metadata']['labels']['utcp.io/network-role']);
        $this->assertContains(['name' => 'sip', 'containerPort' => 5060, 'protocol' => 'UDP'], $container['ports']);
        $this->assertContains(['name' => 'esl', 'containerPort' => 8021, 'protocol' => 'TCP'], $container['ports']);
        $this->assertContains(['name' => 'rtp', 'containerPort' => 21000, 'protocol' => 'UDP'], $container['ports']);
        $this->assertSame('freeswitch-'.'managed-node-'.substr(hash('sha256', '11111111-2222-3333-4444-555555555555'), 0, 8).'-credentials', $container['envFrom'][0]['secretRef']['name']);
        $this->assertSame([
            ['name' => 'freeswitch-runtime', 'mountPath' => '/var/lib/freeswitch'],
            ['name' => 'freeswitch-run', 'mountPath' => '/var/run/freeswitch'],
            ['name' => 'freeswitch-log', 'mountPath' => '/var/log/freeswitch'],
        ], $container['volumeMounts']);
        $this->assertSame([
            'allowPrivilegeEscalation' => false,
            'readOnlyRootFilesystem' => true,
            'capabilities' => ['drop' => ['ALL']],
        ], $container['securityContext']);
        $this->assertSame($container['readinessProbe'], $container['livenessProbe']);
        $this->assertSame(24, $container['startupProbe']['failureThreshold']);
        $this->assertSame(['exec' => ['command' => ['/usr/local/bin/utcp-freeswitch-healthcheck']]], array_intersect_key($container['readinessProbe'], ['exec' => true]));

        $serializedDeployment = json_decode(json_encode($deployment, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $emptyDirVolumes = collect($serializedDeployment->spec->template->spec->volumes)
            ->filter(fn (object $volume): bool => isset($volume->emptyDir))
            ->keyBy('name');

        $this->assertSame(['freeswitch-runtime', 'freeswitch-run', 'freeswitch-log'], $emptyDirVolumes->keys()->all());
        foreach ($emptyDirVolumes as $volume) {
            $this->assertInstanceOf(\stdClass::class, $volume->emptyDir);
        }
        $this->assertStringNotContainsString('"emptyDir":[]', json_encode($deployment, JSON_THROW_ON_ERROR));
    }

    public function test_reconciler_waits_for_readiness_and_converges_at_current_generation(): void
    {
        config(['freeswitch_esl.managed_image' => 'registry.example.test/utcp/freeswitch@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);
        $tenantId = Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        $now = now();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'reconciler-'.substr($tenantId, 0, 8),
            'display_name' => 'FreeSWITCH reconciler tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Managed FreeSWITCH',
            'slug' => 'managed-freeswitch-'.substr($nodeId, 0, 8),
            'runtime_family' => 'freeswitch',
            'adapter_key' => 'freeswitch-esl',
            'desired_state' => 'active',
            'observed_state' => 'unobserved',
            'configuration_version' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $deploymentTargetId = Str::uuid()->toString();
        DB::table('deployment_targets')->insert([
            'id' => $deploymentTargetId,
            'tenant_id' => $tenantId,
            'name' => 'Local Kubernetes',
            'slug' => 'local-kubernetes',
            'kind' => 'kubernetes',
            'configuration' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_provisioning_requests')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'deployment_target_id' => $deploymentTargetId,
            'runtime_node_id' => $nodeId,
            'runtime_family' => 'freeswitch',
            'adapter_key' => 'freeswitch-esl',
            'requested_name' => 'Managed FreeSWITCH',
            'requested_slug' => 'managed-freeswitch-'.substr($nodeId, 0, 8),
            'idempotency_key' => 'managed-freeswitch-'.$nodeId,
            'request_fingerprint' => hash('sha256', $nodeId),
            'status' => 'succeeded',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([['control', 'tcp', 8021], ['sip', 'udp', 5060]] as [$purpose, $transport, $port]) {
            DB::table('runtime_node_endpoints')->insert([
                'id' => Str::uuid()->toString(),
                'runtime_node_id' => $nodeId,
                'purpose' => $purpose,
                'transport' => $transport,
                'host' => 'freeswitch.test',
                'port' => $port,
                'path' => null,
                'tls_mode' => 'disabled',
                'priority' => 100,
                'enabled' => true,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('runtime_node_credentials')->insert([
            'id' => Str::uuid()->toString(),
            'runtime_node_id' => $nodeId,
            'credential_type' => 'freeswitch-esl',
            'identifier' => 'utcp_test',
            'encrypted_secret' => 'opaque-test-secret',
            'secret_fingerprint' => str_repeat('a', 64),
            'version' => 1,
            'status' => 'active',
            'rotated_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['call.transfer', 'recording'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => Str::uuid()->toString(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applyDeployment')->twice()->withArgs(function (array $deployment): bool {
                return $deployment['spec']['template']['spec']['containers'][0]['image'] === 'registry.example.test/utcp/freeswitch@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
            })->andReturn([]);
        });
        $reconciler = new FreeSwitchRuntimeNodeReconciler(
            new FreeSwitchCatalog,
            app(RuntimeRegistryService::class),
            app(KubernetesWorkloadClient::class),
            app(ManagedFreeSwitchProvisioningOperationHandler::class),
        );
        $target = (object) ['target_id' => $nodeId, 'last_operation_id' => null];
        $waiting = $reconciler->evaluate($target);

        $this->assertSame('operation_required', $waiting->status);
        $this->assertSame('freeswitch_esl_readiness_missing', $waiting->reasonCode);

        $this->assertSame(
            ['call.control', 'call.dtmf.send', 'call.hold', 'call.origination', 'media.playback'],
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->orderBy('capability_key')->pluck('capability_key')->all(),
        );

        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_configuration_version' => DB::table('runtime_nodes')->where('id', $nodeId)->value('configuration_version'),
        ]);

        $this->assertSame('converged', $reconciler->evaluate($target)->status);
    }

    public function test_managed_provisioning_projects_the_exact_catalog_capabilities(): void
    {
        config(['freeswitch_esl.managed_image' => 'ghcr.io/grimange/utcp-freeswitch@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc']);
        [$user, $tenantId] = $this->tenantAdmin('managed-freeswitch-capabilities@utcp.local.test', 'managed-freeswitch-capabilities');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($user)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $response = $this->actingAs($user)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'freeswitch',
            'adapter_key' => 'freeswitch-esl',
            'name' => 'Catalog FreeSWITCH',
            'slug' => 'catalog-freeswitch',
        ], ['Idempotency-Key' => 'catalog-freeswitch-capabilities'])->assertAccepted();
        $nodeId = $response->json('provisioning_request.runtime_node.id');

        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applySecret')->once()->andReturn([]);
            $mock->shouldReceive('applyDeployment')->once()->andReturn([]);
            $mock->shouldReceive('applyService')->once()->andReturn([]);
        });

        $this->assertSame(1, app(CommandWorker::class)->workOnce('catalog-freeswitch-capabilities-worker', 10, 60, ['runtime.node.provision']));
        $this->assertSame(
            ['call.control', 'call.dtmf.send', 'call.hold', 'call.origination', 'media.playback'],
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->orderBy('capability_key')->pluck('capability_key')->all(),
        );
    }

    public function test_digest_revision_changes_the_shared_desired_deployment_and_is_idempotent(): void
    {
        $handler = app(ManagedFreeSwitchProvisioningOperationHandler::class);
        $digestA = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';
        $digestB = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

        config(['freeswitch_esl.managed_image' => "registry.example.test/utcp/freeswitch@$digestA"]);
        $deploymentA = $handler->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node-a');

        config(['freeswitch_esl.managed_image' => "registry.example.test/utcp/freeswitch@$digestB"]);
        $deploymentB = $handler->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node-a');
        $deploymentBAgain = $handler->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node-a');
        $deploymentOtherNode = $handler->desiredDeployment('66666666-7777-8888-9999-000000000000', 'managed-node-b');

        $image = static fn (array $deployment): string => $deployment['spec']['template']['spec']['containers'][0]['image'];
        $this->assertSame("registry.example.test/utcp/freeswitch@$digestA", $image($deploymentA));
        $this->assertSame("registry.example.test/utcp/freeswitch@$digestB", $image($deploymentB));
        $this->assertNotSame($image($deploymentA), $image($deploymentB));
        $this->assertEquals($deploymentB, $deploymentBAgain);
        $this->assertSame($image($deploymentB), $image($deploymentOtherNode));
    }

    public function test_mutable_managed_image_reference_is_rejected(): void
    {
        config(['freeswitch_esl.managed_image' => 'registry.example.test/utcp/freeswitch:0.1.0-k1-dev']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('managed FreeSWITCH image configuration is invalid');

        app(ManagedFreeSwitchProvisioningOperationHandler::class)
            ->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node');
    }

    /** @return array{0: User, 1: string} */
    private function tenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'FreeSWITCH test admin',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => ucfirst($slug).' Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return [$user, $tenantId];
    }
}
