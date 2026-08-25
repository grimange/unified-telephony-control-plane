<?php

namespace Tests\Feature\RuntimeProvisioning;

use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Models\User;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeProvisioning\ManagedAsteriskProvisioningOperationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

final class ManagedAsteriskProvisioningOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_infrastructure_worker_materializes_managed_asterisk_and_activates_only_after_configuration(): void
    {
        [$admin, $tenantId] = $this->tenantAdmin('rnp3-happy@utcp.local.test', 'rnp3-happy');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');

        $response = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'name' => 'Managed Asterisk',
            'slug' => 'managed-asterisk',
        ], ['Idempotency-Key' => 'rnp3-happy-1'])->assertAccepted();
        $nodeId = $response->json('provisioning_request.runtime_node.id');
        $operation = DB::table('runtime_operations')->where('operation_type', 'runtime.node.provision')->first();

        $secret = null;
        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock) use (&$secret): void {
            $mock->shouldReceive('applySecret')->once()->withArgs(function (array $desired, string $slug) use (&$secret): bool {
                $secret = $desired['stringData']['ARI_PASSWORD'];

                return $slug === 'managed-asterisk' && $desired['metadata']['namespace'] === 'utcp-runtime';
            })->andReturn([]);
            $mock->shouldReceive('applyDeployment')->once()->withArgs(function (array $desired, string $slug): bool {
                $ports = $desired['spec']['template']['spec']['containers'][0]['ports'];
                $container = $desired['spec']['template']['spec']['containers'][0];
                $volumes = $desired['spec']['template']['spec']['volumes'];

                return $slug === 'managed-asterisk'
                    && $desired['metadata']['namespace'] === 'utcp-runtime'
                    && $container['envFrom'][0]['secretRef']['name'] === $desired['metadata']['name'].'-credentials'
                    && in_array(['name' => 'sip', 'containerPort' => 5060, 'protocol' => 'UDP'], $ports, true)
                    && in_array(['name' => 'asterisk-local-config', 'mountPath' => '/opt/utcp-asterisk-local-config', 'readOnly' => true], $container['volumeMounts'], true)
                    && in_array(['name' => 'asterisk-local-config', 'configMap' => ['name' => 'asterisk-local-sip-fixtures', 'optional' => true]], $volumes, true);
            })->andReturn([]);
            $mock->shouldReceive('applyService')->once()->withArgs(function (array $desired, string $slug): bool {
                return $slug === 'managed-asterisk'
                    && $desired['metadata']['namespace'] === 'utcp-runtime'
                    && $desired['spec']['type'] === 'ClusterIP'
                    && $desired['spec']['ports'] === [
                        ['name' => 'ari', 'protocol' => 'TCP', 'port' => 8088, 'targetPort' => 'ari'],
                        ['name' => 'sip', 'protocol' => 'UDP', 'port' => 5060, 'targetPort' => 'sip'],
                    ];
            })->andReturn([]);
        });

        $processed = app(CommandWorker::class)->workOnce(
            'rnp3-infrastructure-test',
            10,
            60,
            ['runtime.node.provision'],
        );
        $this->assertSame(1, $processed);

        $this->assertDatabaseHas('runtime_operations', ['id' => $operation->id, 'status' => 'succeeded']);
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'desired_state' => 'active', 'observed_state' => 'unobserved']);
        $this->assertDatabaseHas('runtime_node_credentials', ['runtime_node_id' => $nodeId, 'credential_type' => 'ari-basic', 'status' => 'active']);
        $this->assertSame(4, DB::table('runtime_node_endpoints')->where('runtime_node_id', $nodeId)->count());
        $this->assertDatabaseHas('runtime_node_endpoints', [
            'runtime_node_id' => $nodeId,
            'purpose' => 'sip',
            'transport' => 'udp',
            'port' => 5060,
            'enabled' => true,
        ]);
        $sipHost = (string) DB::table('runtime_node_endpoints')
            ->where('runtime_node_id', $nodeId)
            ->where('purpose', 'sip')
            ->value('host');
        $this->assertStringStartsWith('asterisk-managed-asterisk-', $sipHost);
        $this->assertStringEndsWith('.utcp-runtime.svc.cluster.local', $sipHost);
        $this->assertSame(
            ['control', 'events', 'health', 'sip'],
            DB::table('runtime_node_endpoints')
                ->where('runtime_node_id', $nodeId)
                ->orderBy('purpose')
                ->pluck('purpose')
                ->all(),
        );
        $this->assertSame(
            count(config('runtime_registry.adapter_keys.asterisk-ari.supported_capabilities')),
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->count(),
        );
        $this->assertDatabaseHas('asterisk_ari_profiles', ['runtime_node_id' => $nodeId]);

        $operationPayload = (string) DB::table('runtime_operations')->where('id', $operation->id)->value('payload');
        $evidence = $operationPayload.json_encode(DB::table('control_plane_outbox_messages')->pluck('payload')->all(), JSON_THROW_ON_ERROR).json_encode(DB::table('control_plane_audit_records')->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        $this->assertNotNull($secret);
        $this->assertStringNotContainsString((string) $secret, $response->getContent());
        $this->assertStringNotContainsString((string) $secret, $evidence);
    }

    public function test_configured_managed_asterisk_image_is_used_without_source_changes(): void
    {
        config(['asterisk_ari.managed_image' => 'registry.example.test/utcp/asterisk-ari@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);
        [$admin, $tenantId] = $this->tenantAdmin('rnp6-image@utcp.local.test', 'rnp6-image');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $response = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', [
            'deployment_target_id' => $target['id'], 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari',
            'name' => 'Configured Image Asterisk', 'slug' => 'configured-image-asterisk',
        ], ['Idempotency-Key' => 'rnp6-image-1'])->assertAccepted();

        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applySecret')->once()->andReturn([]);
            $mock->shouldReceive('applyDeployment')->once()->withArgs(function (array $desired): bool {
                return $desired['spec']['template']['spec']['containers'][0]['image'] === 'registry.example.test/utcp/asterisk-ari@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
            })->andReturn([]);
            $mock->shouldReceive('applyService')->once()->andReturn([]);
        });

        $this->assertSame(1, app(CommandWorker::class)->workOnce('rnp6-image-test', 10, 60, ['runtime.node.provision']));
        $this->assertDatabaseHas('runtime_nodes', ['id' => $response->json('provisioning_request.runtime_node.id'), 'desired_state' => 'active']);
    }

    public function test_mutable_managed_asterisk_image_reference_is_rejected(): void
    {
        config(['asterisk_ari.managed_image' => 'registry.example.test/utcp/asterisk-ari:0.1.0-k1-dev']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('managed Asterisk image configuration is invalid');

        app(ManagedAsteriskProvisioningOperationHandler::class)
            ->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node');
    }

    public function test_request_and_operation_are_idempotent_and_retry_reuses_credential_after_partial_secret_apply(): void
    {
        [$admin, $tenantId] = $this->tenantAdmin('rnp3-retry@utcp.local.test', 'rnp3-retry');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $payload = [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'name' => 'Retry Asterisk',
            'slug' => 'retry-asterisk',
        ];
        $first = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp3-retry-1'])->assertAccepted();
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp3-retry-1'])->assertAccepted();
        $nodeId = $first->json('provisioning_request.runtime_node.id');

        $passwords = [];
        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock) use (&$passwords): void {
            $mock->shouldReceive('applySecret')->twice()->withArgs(function (array $desired) use (&$passwords): bool {
                $passwords[] = $desired['stringData']['ARI_PASSWORD'];

                return true;
            })->andReturnUsing(function (array $desired) use (&$passwords): array {
                if (count($passwords) === 1) {
                    throw new \RuntimeException('simulated deployment corridor interruption');
                }

                return [];
            });
            $mock->shouldReceive('applyDeployment')->once()->andReturn([]);
            $mock->shouldReceive('applyService')->once()->andReturn([]);
        });

        $worker = app(CommandWorker::class);
        $this->assertSame(0, $worker->workOnce('rnp3-retry-test-1', 10, 60, ['runtime.node.provision']));
        DB::table('runtime_operations')->where('operation_type', 'runtime.node.provision')->update(['available_at' => now()->subSecond()]);
        $this->assertSame(1, $worker->workOnce('rnp3-retry-test-2', 10, 60, ['runtime.node.provision']));

        $this->assertCount(2, $passwords);
        $this->assertSame($passwords[0], $passwords[1]);
        $this->assertSame(1, DB::table('runtime_provisioning_requests')->count());
        $this->assertSame(1, DB::table('runtime_nodes')->where('id', $nodeId)->count());
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.provision')->count());
        $this->assertSame(1, DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('status', 'active')->count());
    }

    public function test_unowned_secret_conflict_fails_operation_without_adopting_the_resource(): void
    {
        [$admin, $tenantId] = $this->tenantAdmin('rnp3-conflict@utcp.local.test', 'rnp3-conflict');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $response = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'name' => 'Conflict Asterisk',
            'slug' => 'conflict-asterisk',
        ], ['Idempotency-Key' => 'rnp3-conflict-1'])->assertAccepted();
        $nodeId = $response->json('provisioning_request.runtime_node.id');

        $this->mock(KubernetesWorkloadClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applySecret')->once()->andThrow(KubernetesWorkloadClientException::ownershipConflict());
            $mock->shouldNotReceive('applyDeployment');
            $mock->shouldNotReceive('applyService');
        });

        $this->assertSame(0, app(CommandWorker::class)->workOnce(
            'rnp3-conflict-test',
            10,
            60,
            ['runtime.node.provision'],
        ));

        $this->assertDatabaseHas('runtime_operations', [
            'operation_type' => 'runtime.node.provision',
            'status' => 'terminal_failed',
            'last_failure_class' => 'conflict',
            'last_failure_code' => 'provisioning_ownership_conflict',
        ]);
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'desired_state' => 'draft']);
    }

    /** @return array{0: User, 1: string} */
    private function tenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(), 'email' => $email, 'normalized_email' => $email,
            'display_name' => 'RNP3 Test User', 'password' => Hash::make('correct-password-123'),
            'status' => 'active', 'password_change_required' => false, 'session_version' => 1,
        ]);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => ucfirst($slug).' Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_role_assignments')->insert(['id' => IdentityIds::new(), 'user_id' => $user->id, 'role_key' => 'platform-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $user->id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);

        return [$user, $tenantId];
    }
}
