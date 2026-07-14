<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\Authorization\CapabilityCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminRoleController extends Controller
{
    public function index(Request $request, CapabilityCatalog $catalog, AuthorizationService $authorization): JsonResponse
    {
        $tenantId = $request->session()->get('active_tenant_id');
        if (is_string($tenantId)) {
            $authorization->requireTenant($request->user()->id, $tenantId, 'tenant.roles.view');
        } else {
            $authorization->requirePlatform($request->user()->id, 'platform.users.view');
        }

        return response()->json([
            'catalog_version' => config('identity.catalog_version'),
            'roles' => $catalog->roles(),
            'capabilities' => array_keys($catalog->capabilities()),
        ]);
    }
}
