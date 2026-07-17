<?php

namespace Tests\Feature\Identity;

use App\Identity\IdentityIds;
use App\Models\User;
use App\TelephonyDomain\TelephonyDomainIds;
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

        $this->assertArrayNotHasKey('temporary_password', $reset);
        $this->assertArrayHasKey('expires_at', $reset);
        $this->assertFalse($reset['temporary_password_displayed']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'password_change_required' => true]);
    }

    public function test_admin_user_detail_exposes_safe_telephony_signaling_lifecycle(): void
    {
        [$admin, $tenantId] = $this->createPlatformAdminWithTenant();
        $target = $this->createUser('detail-target@utcp.local.test', 'Detail Target');
        $membershipId = IdentityIds::new();
        $sessionId = TelephonyDomainIds::new();
        $observationId = TelephonyDomainIds::new();
        $now = now();
        $signalingIdentity = 'ts-'.strtolower(str_replace('-', '', $sessionId));

        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $target->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-member',
            'assigned_by_user_id' => null,
            'created_at' => $now,
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $target->id,
            'status' => 'active',
            'issued_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('signaling_registration_observations')->insert([
            'id' => $observationId,
            'tenant_id' => $tenantId,
            'telephony_session_id' => $sessionId,
            'signaling_identity' => $signalingIdentity,
            'desired_state' => 'eligible',
            'desired_generation' => 1,
            'observed_state' => 'registered',
            'observed_at' => $now,
            'observed_expires_at' => $now->copy()->addSeconds(90),
            'last_event_type' => 'registration.accepted',
            'failure_class' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.email', 'detail-target@utcp.local.test')
            ->assertJsonPath('active_telephony_session.id', $sessionId)
            ->assertJsonPath('signaling.signaling_identity', $signalingIdentity)
            ->assertJsonPath('signaling.registration.observed_state', 'registered')
            ->assertJsonMissingPath('signaling.credential.sip_secret')
            ->assertJsonMissingPath('signaling.credential.ha1')
            ->assertJsonMissingPath('signaling.registration.ruid');

        $issued = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/users/{$target->id}/telephony-sessions/{$sessionId}/signaling-credential")
            ->assertOk()
            ->assertJsonPath('credential.username', $signalingIdentity)
            ->assertJsonPath('credential.realm', 'sip.utcp.local.test')
            ->assertJsonMissingPath('credential.ha1')
            ->json('credential');

        $this->assertArrayHasKey('sip_secret', $issued);
        $this->assertDatabaseMissing('control_plane_audit_records', [
            'metadata' => $issued['sip_secret'],
        ]);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('signaling.credential.username', $signalingIdentity)
            ->assertJsonMissingPath('signaling.credential.sip_secret')
            ->assertJsonMissingPath('signaling.credential.ha1');

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/users/{$target->id}/telephony-sessions/{$sessionId}/end")
            ->assertOk()
            ->assertJsonPath('telephony_session.status', 'ended');

        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'desired_state' => 'removed',
        ]);
        $this->assertSame(0, DB::table('telephony_signaling_credentials')
            ->where('telephony_session_id', $sessionId)
            ->whereNull('revoked_at')
            ->count());

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('active_telephony_session.id', $sessionId)
            ->assertJsonPath('active_telephony_session.status', 'ended')
            ->assertJsonPath('signaling.registration.pending_removal', true)
            ->assertJsonPath('signaling.credential', null);
    }

    public function test_view_own_telephony_session_capability_does_not_expose_other_members_sessions(): void
    {
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'view-own-scope',
            'display_name' => 'View Own Scope Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $viewer = $this->createUser('viewer@utcp.local.test', 'Viewer Member');
        $target = $this->createUser('target@utcp.local.test', 'Target Member');
        foreach ([$viewer, $target] as $member) {
            $membershipId = IdentityIds::new();
            DB::table('tenant_memberships')->insert([
                'id' => $membershipId,
                'user_id' => $member->id,
                'tenant_id' => $tenantId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('tenant_role_assignments')->insert([
                'id' => IdentityIds::new(),
                'membership_id' => $membershipId,
                'role_key' => 'tenant-member',
                'assigned_by_user_id' => null,
                'created_at' => now(),
            ]);
        }

        $targetSessionId = TelephonyDomainIds::new();
        DB::table('telephony_sessions')->insert([
            'id' => $targetSessionId,
            'tenant_id' => $tenantId,
            'user_id' => $target->id,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('active_telephony_session', null)
            ->assertJsonPath('signaling', null);

        $viewerSessionId = TelephonyDomainIds::new();
        DB::table('telephony_sessions')->insert([
            'id' => $viewerSessionId,
            'tenant_id' => $tenantId,
            'user_id' => $viewer->id,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/users/{$viewer->id}")
            ->assertOk()
            ->assertJsonPath('active_telephony_session.id', $viewerSessionId);
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
