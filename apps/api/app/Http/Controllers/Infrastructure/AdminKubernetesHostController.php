<?php

namespace App\Http\Controllers\Infrastructure;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Infrastructure\Kubernetes\KubernetesHostVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminKubernetesHostController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, KubernetesHostVisibilityService $hosts): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.infrastructure.view');
        return response()->json(['hosts' => $hosts->hosts()]);
    }
}
