<?php

namespace App\Identity\Authorization;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuthorizationService
{
    /**
     * @return list<string>
     */
    public function platformCapabilities(string $userId): array
    {
        return DB::table('platform_role_assignments')
            ->join('roles', 'roles.key', '=', 'platform_role_assignments.role_key')
            ->join('role_capabilities', 'role_capabilities.role_key', '=', 'roles.key')
            ->where('platform_role_assignments.user_id', $userId)
            ->where('roles.scope', 'platform')
            ->orderBy('role_capabilities.capability_key')
            ->distinct()
            ->pluck('role_capabilities.capability_key')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tenantCapabilities(string $userId, string $tenantId): array
    {
        return DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->join('tenant_role_assignments', 'tenant_role_assignments.membership_id', '=', 'tenant_memberships.id')
            ->join('roles', 'roles.key', '=', 'tenant_role_assignments.role_key')
            ->join('role_capabilities', 'role_capabilities.role_key', '=', 'roles.key')
            ->where('tenant_memberships.user_id', $userId)
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->where('tenant_memberships.status', 'active')
            ->where('tenants.status', 'active')
            ->where('roles.scope', 'tenant')
            ->orderBy('role_capabilities.capability_key')
            ->distinct()
            ->pluck('role_capabilities.capability_key')
            ->all();
    }

    public function requirePlatform(string $userId, string $capability): void
    {
        if (! in_array($capability, $this->platformCapabilities($userId), true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }

    public function requireTenant(string $userId, string $tenantId, string $capability): void
    {
        if (! in_array($capability, $this->tenantCapabilities($userId, $tenantId), true)) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
