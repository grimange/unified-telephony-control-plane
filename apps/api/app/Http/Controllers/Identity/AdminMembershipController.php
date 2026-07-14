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

final class AdminMembershipController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'tenant.memberships.view');

        return response()->json([
            'memberships' => DB::table('tenant_memberships')
                ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->where('tenant_memberships.tenant_id', $tenantId)
                ->orderBy('users.display_name')
                ->get([
                    'tenant_memberships.id',
                    'tenant_memberships.user_id',
                    'users.email',
                    'users.display_name',
                    'tenant_memberships.status',
                ]),
        ]);
    }

    public function store(Request $request, AuthorizationService $authorization, IdentityAdminService $admin, IdempotencyStore $idempotency): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'tenant.memberships.manage');
        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'role_key' => ['required', 'string'],
        ]);
        $fingerprint = ['tenant_id' => $tenantId, ...$data];
        $key = $this->idempotencyKey($request);

        if ($key !== null) {
            try {
                $existing = $idempotency->begin('identity.memberships.create', $key, $fingerprint);
            } catch (IdempotencyConflict) {
                return response()->json(['message' => 'Idempotency key conflict.'], 409);
            }

            if ($existing !== null) {
                if ($existing->status === 'completed' && $existing->result !== null) {
                    return response()->json($existing->result);
                }

                return response()->json(['message' => 'Request is already in progress.'], 409);
            }
        }

        try {
            $result = ['membership_id' => $admin->createMembership($request, $tenantId, $data['user_id'], $data['role_key'])];
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid role assignment.'], 422);
        }

        if ($key !== null) {
            $idempotency->complete('identity.memberships.create', $key, $result);
        }

        return response()->json($result, 201);
    }

    public function update(Request $request, string $membershipId, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'tenant.memberships.manage');
        abort_unless(
            DB::table('tenant_memberships')->where('id', $membershipId)->where('tenant_id', $tenantId)->exists(),
            404,
            'Membership not found.',
        );
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $admin->setMembershipStatus($request, $membershipId, $data['status']);

        return response()->json(['message' => 'membership_updated']);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        return $tenantId;
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
