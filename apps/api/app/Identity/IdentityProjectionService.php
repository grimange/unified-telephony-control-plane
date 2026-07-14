<?php

namespace App\Identity;

use App\Identity\Authorization\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class IdentityProjectionService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @return array<string, mixed>
     */
    public function sessionProjection(User $user, ?string $activeTenantId): array
    {
        $memberships = DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->where('tenant_memberships.user_id', $user->id)
            ->orderBy('tenants.display_name')
            ->get([
                'tenant_memberships.id as membership_id',
                'tenant_memberships.status as membership_status',
                'tenants.id as tenant_id',
                'tenants.slug',
                'tenants.display_name',
                'tenants.status as tenant_status',
            ])
            ->map(fn ($row): array => [
                'membership_id' => $row->membership_id,
                'tenant_id' => $row->tenant_id,
                'slug' => $row->slug,
                'display_name' => $row->display_name,
                'status' => $row->tenant_status,
                'membership_status' => $row->membership_status,
            ])
            ->values()
            ->all();

        $activeTenant = null;
        $capabilities = $this->authorization->platformCapabilities($user->id);

        if ($activeTenantId !== null) {
            foreach ($memberships as $membership) {
                if ($membership['tenant_id'] === $activeTenantId && $membership['status'] === 'active' && $membership['membership_status'] === 'active') {
                    $activeTenant = [
                        'tenant_id' => $membership['tenant_id'],
                        'slug' => $membership['slug'],
                        'display_name' => $membership['display_name'],
                    ];
                    $capabilities = array_values(array_unique(array_merge(
                        $capabilities,
                        $this->authorization->tenantCapabilities($user->id, $activeTenantId),
                    )));
                    sort($capabilities);
                    break;
                }
            }
        }

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'status' => $user->status,
                'password_change_required' => (bool) $user->password_change_required,
            ],
            'active_tenant' => $activeTenant,
            'memberships' => $memberships,
            'capabilities' => $capabilities,
            'catalog_version' => config('identity.catalog_version'),
            'expires_at' => now()->addMinutes((int) config('session.lifetime'))->toIso8601String(),
        ];
    }
}
