<?php

namespace Tests\Feature\Identity;

use App\Identity\IdentityIds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IdentityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_session_projection_tenant_selection_and_logout(): void
    {
        [$user, $tenantId] = $this->createPlatformAdminWithTenant();

        $this->getJson('/api/v1/auth/session')->assertUnauthorized();

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password-123',
                '_token' => 'csrf-token',
            ])
            ->assertOk();

        $this->assertAuthenticatedAs($user);

        $this->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonFragment(['platform.users.manage']);

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/tenant-context', [
                'tenant_id' => $tenantId,
                '_token' => 'csrf-token',
            ])
            ->assertOk()
            ->assertJsonPath('active_tenant.tenant_id', $tenantId)
            ->assertJsonFragment(['tenant.memberships.manage']);

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/logout', ['_token' => 'csrf-token'])
            ->assertOk();

        $this->assertGuest();
    }

    public function test_suspended_user_tenant_and_membership_are_rejected(): void
    {
        [$user, $tenantId, $membershipId] = $this->createPlatformAdminWithTenant();

        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password-123',
                '_token' => 'csrf-token',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'suspended']);

        $this->actingAs($user)
            ->withSession(['user_session_version' => 1, '_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/tenant-context', ['tenant_id' => $tenantId, '_token' => 'csrf-token'])
            ->assertForbidden();

        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'active']);
        DB::table('tenant_memberships')->where('id', $membershipId)->update(['status' => 'suspended']);

        $this->actingAs($user)
            ->withSession(['user_session_version' => 1, '_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/tenant-context', ['tenant_id' => $tenantId, '_token' => 'csrf-token'])
            ->assertForbidden();
    }

    public function test_admin_routes_enforce_platform_and_tenant_capabilities(): void
    {
        [$admin, $tenantId] = $this->createPlatformAdminWithTenant();
        $regular = $this->createUser('member@utcp.local.test', 'Tenant Member');

        $this->actingAs($regular)
            ->withSession(['user_session_version' => 1])
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $tenantResponse = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson('/api/v1/admin/tenants', [
                'slug' => 'proof-admin',
                'display_name' => 'Proof Admin',
            ])
            ->assertCreated();

        $newTenantId = $tenantResponse->json('tenant.id');

        $createdUser = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson('/api/v1/admin/users', [
                'email' => 'new-user@utcp.local.test',
                'display_name' => 'New User',
            ])
            ->assertCreated()
            ->json();

        $this->assertArrayHasKey('temporary_password', $createdUser);
        $this->assertDatabaseMissing('control_plane_audit_records', [
            'metadata' => $createdUser['temporary_password'],
        ]);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/memberships', [
                'user_id' => $createdUser['user']['id'],
                'role_key' => 'tenant-member',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/memberships', [
                'user_id' => $createdUser['user']['id'],
                'role_key' => 'platform-admin',
            ])
            ->assertUnprocessable();
    }

    public function test_password_change_and_admin_reset_invalidate_sessions(): void
    {
        [$admin] = $this->createPlatformAdminWithTenant();
        $user = $this->createUser('reset-target@utcp.local.test', 'Reset Target');

        $this->actingAs($user)
            ->withSession(['user_session_version' => 1, '_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'correct-password-123',
                'new_password' => 'new-correct-password-456',
                '_token' => 'csrf-token',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-correct-password-456', (string) DB::table('users')->where('id', $user->id)->value('password')));

        $reset = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson("/api/v1/admin/users/{$user->id}/password-reset")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('temporary_password', $reset);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'password_change_required' => true]);
        $this->assertStringNotContainsString($reset['temporary_password'], json_encode(DB::table('control_plane_audit_records')->get()->all()));
    }

    public function test_safe_create_operations_use_c0_idempotency(): void
    {
        [$admin] = $this->createPlatformAdminWithTenant();

        $first = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson('/api/v1/admin/tenants', [
                'slug' => 'idempotent-proof',
                'display_name' => 'Idempotent Proof',
            ], ['Idempotency-Key' => 'tenant-create-proof-key'])
            ->assertCreated()
            ->json();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson('/api/v1/admin/tenants', [
                'slug' => 'idempotent-proof',
                'display_name' => 'Idempotent Proof',
            ], ['Idempotency-Key' => 'tenant-create-proof-key'])
            ->assertOk()
            ->assertJsonPath('tenant.id', $first['tenant']['id']);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->postJson('/api/v1/admin/tenants', [
                'slug' => 'idempotent-conflict',
                'display_name' => 'Idempotent Conflict',
            ], ['Idempotency-Key' => 'tenant-create-proof-key'])
            ->assertConflict();
    }

    /**
     * @return array{0: User, 1: string, 2: string}
     */
    private function createPlatformAdminWithTenant(): array
    {
        $user = $this->createUser('admin@utcp.local.test', 'Admin User');
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'local',
            'display_name' => 'Local Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
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
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return [$user, $tenantId, $membershipId];
    }

    private function createUser(string $email, string $displayName): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => $displayName,
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }
}
