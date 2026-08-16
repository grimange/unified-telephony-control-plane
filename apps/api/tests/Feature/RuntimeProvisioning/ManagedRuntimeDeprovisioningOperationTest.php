<?php

namespace Tests\Feature\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use App\Models\User;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeProvisioning\ManagedAsteriskDeprovisioningOperationHandler;
use App\RuntimeProvisioning\ManagedAsteriskResourceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ManagedRuntimeDeprovisioningOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_retirement_schedules_one_operation_and_deletes_owned_resources_in_order(): void
    {
        [$admin, $tenantId, $nodeId, $session] = $this->managedRuntime('rnp4-happy');
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->first();
        $names = ManagedAsteriskResourceIdentity::names((string) $node->slug, $nodeId);
        $fake = DeprovisionFakeKubernetesWorkloadClient::owned($names, (string) $node->slug);
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.deprovision')->count());
        $this->assertSame(1, app(CommandWorker::class)->workOnce('rnp4-infrastructure', 10, 60, ['runtime.node.deprovision']));

        $this->assertSame(['Deployment', 'Service', 'Secret'], $fake->deleteKinds);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.deprovision')->where('status', 'succeeded')->count());
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'desired_state' => 'retired']);
        $this->assertDatabaseHas('runtime_provisioning_requests', ['runtime_node_id' => $nodeId]);
        $this->assertStringNotContainsString('secret-material', json_encode(DB::table('runtime_operations')->pluck('payload')->all(), JSON_THROW_ON_ERROR));
        unset($admin, $tenantId, $session);
    }

    public function test_decommission_retirement_schedules_deprovision_after_rnm_retires_the_node(): void
    {
        [$admin, $tenantId, $nodeId, $session] = $this->managedRuntime('rnp4-decommission');
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['desired_state' => 'drained']);

        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/runtime-nodes/{$nodeId}/decommission")
            ->assertAccepted();
        $this->assertSame(1, app(CommandWorker::class)->workOnce('rnp4-rnm', 10, 60, ['runtime.node.decommission']));

        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'desired_state' => 'retired']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.deprovision')->count());
        unset($tenantId);
    }

    public function test_external_retirement_never_schedules_or_deletes_infrastructure(): void
    {
        [$admin, $tenantId] = $this->tenantAdmin('rnp4-external@utcp.local.test', 'rnp4-external');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $node = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-nodes', [
            'name' => 'External Asterisk', 'slug' => 'external-asterisk', 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari',
        ])->assertCreated()->json('runtime_node');
        DB::table('runtime_nodes')->where('id', $node['id'])->update(['desired_state' => 'disabled']);
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'retired'])->assertOk();

        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.deprovision')->count());
        $fake = new DeprovisionFakeKubernetesWorkloadClient;
        $this->app->instance(KubernetesWorkloadClient::class, $fake);
        $this->assertSame(0, app(CommandWorker::class)->workOnce('rnp4-external', 10, 60, ['runtime.node.deprovision']));
        $this->assertSame([], $fake->deleteKinds);
        unset($tenantId);
    }

    public function test_pre_retired_execution_is_refused_for_all_non_terminal_states(): void
    {
        [$admin, $tenantId, $nodeId] = $this->managedRuntime('rnp4-gate');
        $requestId = (string) DB::table('runtime_provisioning_requests')->where('runtime_node_id', $nodeId)->value('id');
        $handler = app(ManagedAsteriskDeprovisioningOperationHandler::class);
        foreach (['draft', 'active', 'draining', 'drained', 'disabled'] as $state) {
            DB::table('runtime_nodes')->where('id', $nodeId)->update(['desired_state' => $state]);
            $result = $handler->execute(['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'payload' => ['provisioning_request_id' => $requestId]], null);
            $this->assertSame(FailureClass::Conflict->value, $result['failure_class'], $state);
        }
        unset($admin);
    }

    public function test_ownership_conflict_is_preflighted_before_any_delete(): void
    {
        [$admin, $tenantId, $nodeId, $session] = $this->managedRuntime('rnp4-conflict');
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->first();
        $names = ManagedAsteriskResourceIdentity::names((string) $node->slug, $nodeId);
        $fake = DeprovisionFakeKubernetesWorkloadClient::owned($names, (string) $node->slug);
        $fake->ownership['Service:'.$names['service']] = ['app.kubernetes.io/part-of' => 'external', 'utcp.dev/runtime-node' => 'someone-else'];
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $this->assertSame(0, app(CommandWorker::class)->workOnce('rnp4-conflict', 10, 60, ['runtime.node.deprovision']));
        $this->assertSame([], $fake->deleteKinds);
        $this->assertDatabaseHas('runtime_operations', ['operation_type' => 'runtime.node.deprovision', 'last_failure_class' => 'conflict', 'last_failure_code' => 'deprovision_ownership_conflict']);
        unset($admin, $session);
    }

    public function test_partial_deletion_retry_accepts_absence_and_converges(): void
    {
        [$admin, $tenantId, $nodeId, $session] = $this->managedRuntime('rnp4-retry');
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->first();
        $names = ManagedAsteriskResourceIdentity::names((string) $node->slug, $nodeId);
        $fake = DeprovisionFakeKubernetesWorkloadClient::owned($names, (string) $node->slug);
        $fake->failServiceOnce = true;
        $this->app->instance(KubernetesWorkloadClient::class, $fake);

        $this->assertSame(0, app(CommandWorker::class)->workOnce('rnp4-retry-1', 10, 60, ['runtime.node.deprovision']));
        $this->assertSame(['Deployment'], $fake->deleteKinds);
        DB::table('runtime_operations')->where('operation_type', 'runtime.node.deprovision')->update(['available_at' => now()->subSecond()]);
        $this->assertSame(1, app(CommandWorker::class)->workOnce('rnp4-retry-2', 10, 60, ['runtime.node.deprovision']));
        $this->assertSame(['Deployment', 'Service', 'Secret'], $fake->deleteKinds);
        unset($admin, $tenantId, $session);
    }

    /** @return array{0:User,1:string,2:string,3:array<string,int|string>} */
    private function managedRuntime(string $slug): array
    {
        [$admin, $tenantId] = $this->tenantAdmin($slug.'@utcp.local.test', $slug);
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $nodeId = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/runtime-provisioning', [
            'deployment_target_id' => $target['id'], 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'name' => $slug, 'slug' => $slug,
        ], ['Idempotency-Key' => $slug.'-key'])->assertAccepted()->json('provisioning_request.runtime_node.id');
        DB::table('runtime_nodes')->where('id', $nodeId)->update(['desired_state' => 'disabled']);
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/runtime-nodes/{$nodeId}/desired-state", ['desired_state' => 'retired'])->assertOk();

        return [$admin, $tenantId, $nodeId, $session];
    }

    /** @return array{0:User,1:string} */
    private function tenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create(['id' => IdentityIds::new(), 'email' => $email, 'normalized_email' => $email, 'display_name' => 'RNP4 Test User', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => $slug.' Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_role_assignments')->insert(['id' => IdentityIds::new(), 'user_id' => $user->id, 'role_key' => 'platform-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $user->id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);

        return [$user, $tenantId];
    }
}

final class DeprovisionFakeKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    /** @var list<string> */
    public array $deleteKinds = [];

    /** @var array<string,array<string,string>> */
    public array $ownership = [];

    public bool $failServiceOnce = false;

    public static function owned(array $names, string $slug): self
    {
        $fake = new self;
        foreach (['Deployment' => $names['deployment'], 'Service' => $names['service'], 'Secret' => $names['secret']] as $kind => $name) {
            $fake->ownership[$kind.':'.$name] = ['app.kubernetes.io/part-of' => 'utcp', 'utcp.dev/runtime-node' => $slug];
        }

        return $fake;
    }

    public function inspectResource(string $kind, string $name): ?array
    {
        $key = $kind.':'.$name;
        if (! isset($this->ownership[$key])) {
            return null;
        }

        return ['kind' => $kind, 'metadata' => ['name' => $name, 'namespace' => 'utcp-runtime', 'labels' => $this->ownership[$key]]];
    }

    public function getDeployment(string $namespace, string $name): ?array
    {
        return null;
    }

    public function scaleDeployment(string $namespace, string $name, int $replicas): void
    {
        throw KubernetesWorkloadClientException::unavailable();
    }

    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        return [];
    }

    public function applySecret(array $desired, string $runtimeNodeSlug): array
    {
        return [];
    }

    public function applyDeployment(array $desired, string $runtimeNodeSlug): array
    {
        return [];
    }

    public function applyService(array $desired, string $runtimeNodeSlug): array
    {
        return [];
    }

    public function deleteSecret(string $name, string $runtimeNodeSlug): bool
    {
        $key = 'Secret:'.$name;
        if (isset($this->ownership[$key])) {
            $this->deleteKinds[] = 'Secret';
            unset($this->ownership[$key]);
        }

return true;
    }

    public function deleteDeployment(string $name, string $runtimeNodeSlug): bool
    {
        $key = 'Deployment:'.$name;
        if (isset($this->ownership[$key])) {
            $this->deleteKinds[] = 'Deployment';
            unset($this->ownership[$key]);
        }

return true;
    }

    public function deleteService(string $name, string $runtimeNodeSlug): bool
    {
        if ($this->failServiceOnce) {
            $this->failServiceOnce = false;
            throw KubernetesWorkloadClientException::unavailable('transient test failure');
        }
        $key = 'Service:'.$name;
        if (isset($this->ownership[$key])) {
            $this->deleteKinds[] = 'Service';
            unset($this->ownership[$key]);
        }

return true;
    }
}
