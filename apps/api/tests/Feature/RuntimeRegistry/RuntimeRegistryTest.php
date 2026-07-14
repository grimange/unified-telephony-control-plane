<?php

namespace Tests\Feature\RuntimeRegistry;

use App\Identity\IdentityIds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RuntimeRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_manages_runtime_node_without_revealing_credentials(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $secret = 'c2-proof-secret-'.bin2hex(random_bytes(6));

        $created = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload(), ['Idempotency-Key' => 'runtime-node-create-key'])
            ->assertCreated()
            ->assertJsonPath('runtime_node.desired_state', 'draft')
            ->assertJsonPath('runtime_node.observed_state', 'unobserved')
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload(), ['Idempotency-Key' => 'runtime-node-create-key'])
            ->assertCreated()
            ->assertJsonPath('runtime_node.id', $created['id']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/endpoints", [
                'purpose' => 'control',
                'transport' => 'https',
                'host' => 'asterisk-control.local.test',
                'port' => 8089,
                'path' => '/ari',
                'tls_mode' => 'verify',
            ])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/endpoints", [
                'purpose' => 'events',
                'transport' => 'wss',
                'host' => 'asterisk-events.local.test',
                'port' => 8089,
                'path' => '/ari/events',
                'tls_mode' => 'verify',
            ])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$created['id']}/capabilities", [
                'capabilities' => ['conference.execution', 'event.stream'],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.capabilities.0', 'conference.execution');

        $credential = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/credentials", [
                'credential_type' => 'control-api',
                'identifier' => 'proof-user',
                'secret' => $secret,
            ], ['Idempotency-Key' => 'runtime-credential-create-key'])
            ->assertCreated()
            ->assertJsonMissing(['secret' => $secret])
            ->assertJsonMissing(['encrypted_secret' => true])
            ->json('credential');

        $row = DB::table('runtime_node_credentials')->where('id', $credential['id'])->first();
        $this->assertNotNull($row);
        $this->assertNotSame($secret, $row->encrypted_secret);
        $this->assertSame($secret, Crypt::decryptString($row->encrypted_secret));

        $rotated = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/credentials/{$credential['id']}/rotate", [
                'credential_type' => 'control-api',
                'identifier' => 'proof-user',
                'secret' => $secret.'-rotated',
            ], ['Idempotency-Key' => 'runtime-credential-rotate-key'])
            ->assertOk()
            ->assertJsonPath('credential.version', 2)
            ->json('credential');

        $this->assertDatabaseHas('runtime_node_credentials', ['id' => $credential['id'], 'status' => 'retired']);
        $this->assertDatabaseHas('runtime_node_credentials', ['id' => $rotated['id'], 'status' => 'active']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'active')
            ->assertJsonPath('runtime_node.observed_state', 'unobserved');

        $serialized = json_encode([
            DB::table('control_plane_audit_records')->get()->all(),
            DB::table('control_plane_outbox_messages')->get()->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringNotContainsString($secret.'-rotated', $serialized);
    }

    public function test_tenant_member_cross_tenant_access_and_invalid_inputs_fail_closed(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('admin-two@utcp.local.test');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-admin@utcp.local.test', 'other');
        $member = $this->createUser('member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-member');

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload())
            ->assertCreated()
            ->json('runtime_node');

        $this->actingAs($member)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('member-denied'))
            ->assertForbidden();

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}")
            ->assertNotFound();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])
            ->assertUnprocessable();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/endpoints", [
                'purpose' => 'sip',
                'transport' => 'udp',
                'host' => 'bad.local.test',
                'port' => 5060,
            ])
            ->assertUnprocessable();

        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'suspended']);
        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-nodes')
            ->assertStatus(409);
    }

    public function test_idempotency_conflict_and_route_inventory_exclude_live_runtime_authority(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('first'), ['Idempotency-Key' => 'runtime-node-conflict-key'])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('second'), ['Idempotency-Key' => 'runtime-node-conflict-key'])
            ->assertConflict();

        $routes = implode("\n", collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all());
        $this->assertStringContainsString('api/v1/admin/runtime-nodes', $routes);
        $this->assertStringNotContainsString('api/v1/admin/ari', $routes);
        $this->assertStringNotContainsString('api/v1/admin/esl', $routes);
        $this->assertStringNotContainsString('test-connection', $routes);
        $this->assertStringNotContainsString('reconcile', $routes);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'admin@utcp.local.test', string $slug = 'local'): array
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
            'display_name' => 'Runtime Registry User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(string $slug = 'proof-runtime'): array
    {
        return [
            'name' => 'Proof Runtime',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => ['purpose' => 'proof'],
        ];
    }
}
