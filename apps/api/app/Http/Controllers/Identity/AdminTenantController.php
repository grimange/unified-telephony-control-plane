<?php

namespace App\Http\Controllers\Identity;

use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AdminTenantController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.tenants.view');

        return response()->json([
            'tenants' => DB::table('tenants')->orderBy('display_name')->get(['id', 'slug', 'display_name', 'status']),
        ]);
    }

    public function store(Request $request, AuthorizationService $authorization, IdentityAdminService $admin, IdempotencyStore $idempotency): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.tenants.manage');
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:80'],
            'display_name' => ['required', 'string', 'max:160'],
        ]);

        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return response()->json(['tenant' => $admin->createTenant($request, $data['slug'], $data['display_name'])], 201);
        }

        try {
            $existing = $idempotency->begin('identity.tenants.create', $key, $data);
        } catch (IdempotencyConflict) {
            return response()->json(['message' => 'Idempotency key conflict.'], 409);
        }

        if ($existing !== null) {
            if ($existing->status === 'completed' && $existing->result !== null) {
                return response()->json($existing->result);
            }

            return response()->json(['message' => 'Request is already in progress.'], 409);
        }

        $result = ['tenant' => $admin->createTenant($request, $data['slug'], $data['display_name'])];
        $idempotency->complete('identity.tenants.create', $key, $result);

        return response()->json($result, 201);
    }

    public function update(Request $request, string $tenantId, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.tenants.manage');
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $admin->setTenantStatus($request, $tenantId, $data['status']);

        return response()->json(['message' => 'tenant_updated']);
    }

    private function idempotencyKey(Request $request): ?IdempotencyKey
    {
        $header = $request->header('Idempotency-Key');
        if (! is_string($header) || $header === '') {
            return null;
        }

        try {
            return IdempotencyKey::fromString($header);
        } catch (InvalidArgumentException) {
            Log::info('identity idempotency key rejected', ['component' => 'identity', 'result' => 'invalid_idempotency_key']);

            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
