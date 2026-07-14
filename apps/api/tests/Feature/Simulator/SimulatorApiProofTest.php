<?php

namespace Tests\Feature\Simulator;

use App\Identity\IdentityIds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SimulatorApiProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_configures_explicit_simulator_node_and_denied_users_fail_closed(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-simulator-admin@utcp.local.test', 'other-simulator');
        $member = $this->createUser('simulator-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-member');

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload(), ['Idempotency-Key' => 'simulator-node-create'])
            ->assertCreated()
            ->assertJsonPath('runtime_node.runtime_family', 'simulator')
            ->assertJsonPath('runtime_node.adapter_key', 'simulator-deterministic')
            ->assertJsonPath('runtime_node.observed_state', 'unobserved')
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/capabilities", [
                'capabilities' => ['event.stream', 'runtime.configuration', 'runtime.observation'],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.capabilities.0', 'event.stream')
            ->assertJsonPath('runtime_node.capabilities.1', 'runtime.configuration')
            ->assertJsonPath('runtime_node.capabilities.2', 'runtime.observation');

        $profile = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration", [
                'scenario_key' => 'steady-ready',
                'scenario_version' => 1,
                'seed' => 'c4-api-proof-seed',
                'parameters' => ['max_delay_seconds' => 0],
            ])
            ->assertOk()
            ->assertJsonPath('adapter_configuration.adapter_key', 'simulator-deterministic')
            ->assertJsonPath('adapter_configuration.profile.scenario_key', 'steady-ready')
            ->json('adapter_configuration');

        $this->assertSame('configured', $profile['state']['phase']);

        $this->actingAs($member)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration", [
                'scenario_key' => 'terminal-failure',
                'scenario_version' => 1,
                'seed' => 'denied',
            ])
            ->assertForbidden();

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration")
            ->assertNotFound();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'active');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration")
            ->assertOk()
            ->assertJsonPath('adapter_configuration.profile.scenario_key', 'steady-ready')
            ->assertJsonMissing(['fencing_token' => true])
            ->assertJsonMissing(['raw_payload' => true]);

        $routes = implode("\n", collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all());
        $this->assertStringContainsString('api/v1/admin/runtime-nodes/{runtimeNode}/adapter-configuration', $routes);
        $this->assertStringNotContainsString('simulator/advance', $routes);
        $this->assertStringNotContainsString('simulator/emit', $routes);
        $this->assertStringNotContainsString('simulator/reconcile', $routes);
        $this->assertStringNotContainsString('api/v1/admin/ari', $routes);
        $this->assertStringNotContainsString('api/v1/admin/esl', $routes);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'simulator-admin@utcp.local.test', string $slug = 'simulator-local'): array
    {
        $user = $this->createUser($email);
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
        $this->attachTenantRole($user->id, $tenantId, 'tenant-admin');

        return [$user, $tenantId];
    }

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => $roleKey,
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Simulator User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(): array
    {
        return [
            'name' => 'Local Deterministic Simulator',
            'slug' => 'local-deterministic-simulator',
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => ['purpose' => 'c4-proof'],
        ];
    }
}
