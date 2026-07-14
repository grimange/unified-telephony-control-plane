<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdminUserController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.view');

        return response()->json([
            'users' => DB::table('users')->orderBy('display_name')->get(['id', 'email', 'display_name', 'status', 'password_change_required']),
        ]);
    }

    public function store(Request $request, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:320'],
            'display_name' => ['required', 'string', 'max:160'],
        ]);

        return response()->json($admin->createUser($request, $data['email'], $data['display_name']), 201);
    }

    public function update(Request $request, string $userId, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.manage');
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $admin->setUserStatus($request, $userId, $data['status']);

        return response()->json(['message' => 'user_updated']);
    }

    public function resetPassword(Request $request, string $userId, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.manage');

        return response()->json($admin->resetPassword($request, $userId));
    }

    public function assignPlatformRole(Request $request, string $userId, AuthorizationService $authorization, IdentityAdminService $admin): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.manage');
        $data = $request->validate(['role_key' => ['required', 'string']]);
        try {
            $admin->assignPlatformRole($request, $userId, $data['role_key']);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid role assignment.'], 422);
        }

        return response()->json(['message' => 'platform_role_assigned']);
    }
}
