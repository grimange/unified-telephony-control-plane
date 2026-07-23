<?php

use App\Identity\Authorization\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.runtime-nodes', function (User $user, string $tenantId): bool {
    $activeTenantId = request()->session()->get('active_tenant_id');
    if (! is_string($activeTenantId) || $activeTenantId !== $tenantId) {
        return false;
    }

    app(AuthorizationService::class)->requireTenant($user->id, $tenantId, 'runtime.nodes.view');

    return true;
});

Broadcast::channel('tenant.{tenantId}.conferences', function (User $user, string $tenantId): bool {
    $activeTenantId = request()->session()->get('active_tenant_id');
    if (! is_string($activeTenantId) || $activeTenantId !== $tenantId) {
        return false;
    }

    app(AuthorizationService::class)->requireTenant($user->id, $tenantId, 'telephony.conferences.view');

    return true;
});
