<?php

namespace App\Http\Controllers\Infrastructure;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Infrastructure\Kubernetes\HostMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminHostMaintenanceController extends Controller
{
    public function store(Request $request, string $nodeUid, AuthorizationService $authorization, HostMaintenanceService $maintenance): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.infrastructure.maintain');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['maintenance' => $maintenance->request($request, $nodeUid, $data['reason'] ?? null)], 202);
    }

    public function index(Request $request, AuthorizationService $authorization, HostMaintenanceService $maintenance): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.infrastructure.view');
        return response()->json(['maintenances' => $maintenance->active()]);
    }
}
