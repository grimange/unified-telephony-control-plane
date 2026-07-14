<?php

namespace App\Identity;

use App\ControlPlane\Audit\AuditRepository;
use App\Identity\Authorization\CapabilityCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IdentityAdminService
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly CapabilityCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createTenant(Request $request, string $slug, string $displayName): array
    {
        return DB::transaction(function () use ($request, $slug, $displayName): array {
            $id = IdentityIds::new();
            DB::table('tenants')->insert([
                'id' => $id,
                'slug' => Str::slug($slug),
                'display_name' => $displayName,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.tenant.created', 'tenant', $id, [
                'slug' => Str::slug($slug),
            ]);

            return ['id' => $id, 'slug' => Str::slug($slug), 'display_name' => $displayName, 'status' => 'active'];
        });
    }

    /**
     * @return array{user: array<string, mixed>, temporary_password: string}
     */
    public function createUser(Request $request, string $email, string $displayName): array
    {
        return DB::transaction(function () use ($request, $email, $displayName): array {
            $temporaryPassword = $this->temporaryPassword();
            $id = IdentityIds::new();
            DB::table('users')->insert([
                'id' => $id,
                'email' => $email,
                'normalized_email' => Str::lower($email),
                'display_name' => $displayName,
                'password' => Hash::make($temporaryPassword),
                'status' => 'active',
                'password_change_required' => true,
                'session_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.user.created', 'user', $id, [
                'email_hash' => hash('sha256', Str::lower($email)),
            ]);

            return [
                'user' => ['id' => $id, 'email' => $email, 'display_name' => $displayName, 'status' => 'active'],
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

    public function setUserStatus(Request $request, string $userId, string $status): void
    {
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('invalid user status');
        }

        DB::transaction(function () use ($request, $userId, $status): void {
            DB::table('users')->where('id', $userId)->update([
                'status' => $status,
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.user.status_changed', 'user', $userId, ['status' => $status]);
        });
    }

    public function setTenantStatus(Request $request, string $tenantId, string $status): void
    {
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('invalid tenant status');
        }

        DB::transaction(function () use ($request, $tenantId, $status): void {
            DB::table('tenants')->where('id', $tenantId)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.tenant.status_changed', 'tenant', $tenantId, ['status' => $status]);
        });
    }

    public function createMembership(Request $request, string $tenantId, string $userId, string $roleKey): string
    {
        return DB::transaction(function () use ($request, $tenantId, $userId, $roleKey): string {
            $this->assertRoleScope($roleKey, 'tenant');
            $membership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            $membershipId = $membership?->id ?? IdentityIds::new();

            if ($membership === null) {
                DB::table('tenant_memberships')->insert([
                    'id' => $membershipId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit->append(IdentityContext::fromRequest($request, $tenantId), 'identity.membership.created', 'tenant_membership', $membershipId, []);
            }

            if (! DB::table('tenant_role_assignments')->where('membership_id', $membershipId)->where('role_key', $roleKey)->exists()) {
                DB::table('tenant_role_assignments')->insert([
                    'id' => IdentityIds::new(),
                    'membership_id' => $membershipId,
                    'role_key' => $roleKey,
                    'assigned_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                ]);
            }
            $this->audit->append(IdentityContext::fromRequest($request, $tenantId), 'identity.tenant_role.assigned', 'tenant_membership', $membershipId, ['role_key' => $roleKey]);

            return $membershipId;
        });
    }

    public function setMembershipStatus(Request $request, string $membershipId, string $status): void
    {
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('invalid membership status');
        }

        DB::transaction(function () use ($request, $membershipId, $status): void {
            $tenantId = DB::table('tenant_memberships')->where('id', $membershipId)->value('tenant_id');
            DB::table('tenant_memberships')->where('id', $membershipId)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit->append(IdentityContext::fromRequest($request, is_string($tenantId) ? $tenantId : null), 'identity.membership.status_changed', 'tenant_membership', $membershipId, ['status' => $status]);
        });
    }

    /**
     * @return array{temporary_password: string}
     */
    public function resetPassword(Request $request, string $userId): array
    {
        return DB::transaction(function () use ($request, $userId): array {
            $temporaryPassword = $this->temporaryPassword();
            DB::table('users')->where('id', $userId)->update([
                'password' => Hash::make($temporaryPassword),
                'password_change_required' => true,
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.user.password_reset', 'user', $userId, []);

            return ['temporary_password' => $temporaryPassword];
        });
    }

    public function assignPlatformRole(Request $request, string $userId, string $roleKey): void
    {
        DB::transaction(function () use ($request, $userId, $roleKey): void {
            $this->assertRoleScope($roleKey, 'platform');
            if (! DB::table('platform_role_assignments')->where('user_id', $userId)->where('role_key', $roleKey)->exists()) {
                DB::table('platform_role_assignments')->insert([
                    'id' => IdentityIds::new(),
                    'user_id' => $userId,
                    'role_key' => $roleKey,
                    'assigned_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                ]);
            }
            $this->audit->append(IdentityContext::fromRequest($request), 'identity.platform_role.assigned', 'user', $userId, ['role_key' => $roleKey]);
        });
    }

    private function assertRoleScope(string $roleKey, string $scope): void
    {
        if ($this->catalog->roleScope($roleKey) !== $scope) {
            throw new InvalidArgumentException('role scope mismatch');
        }
    }

    private function temporaryPassword(): string
    {
        return 'utcp-'.bin2hex(random_bytes(12));
    }
}
