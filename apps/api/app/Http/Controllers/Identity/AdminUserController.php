<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityAdminService;
use App\Identity\IdentityContext;
use App\Identity\UserAccess\ResetUserPasswordService;
use App\TelephonyDomain\Signaling\SignalingCredentialService;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminUserController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $tenantId = $this->activeTenantId($request);
        $this->authorizeUserView($request, $authorization, $tenantId);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'nullable', 'in:active,suspended'],
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($data['per_page'] ?? 20)));
        $search = trim((string) ($data['search'] ?? ''));

        $query = DB::table('users')
            ->select('users.*')
            ->orderBy('display_name')
            ->orderBy('email');
        if (! $authorization->hasPlatform($request->user()->id, 'platform.users.view')) {
            $query->join('tenant_memberships', 'tenant_memberships.user_id', '=', 'users.id')
                ->where('tenant_memberships.tenant_id', $tenantId);
        }
        if (($data['status'] ?? null) !== null && $data['status'] !== '') {
            $query->where('users.status', $data['status']);
        }
        if ($search !== '') {
            $normalized = mb_strtolower($search);
            $query->where(function ($nested) use ($normalized, $search): void {
                $nested->where('users.normalized_email', 'like', '%'.$normalized.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%')
                    ->orWhere('users.display_name', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        $users = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (object $user): array => $this->serializeUserSummary($user, $tenantId))
            ->all();

        return response()->json([
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function show(
        Request $request,
        string $userId,
        AuthorizationService $authorization,
        TelephonyDomainService $telephony,
        SignalingCredentialService $signaling,
    ): JsonResponse {
        $tenantId = $this->activeTenantId($request);
        $this->authorizeUserView($request, $authorization, $tenantId);
        $user = DB::table('users')->where('id', $userId)->first();
        abort_unless($user !== null, 404, 'User not found.');
        if (! $authorization->hasPlatform($request->user()->id, 'platform.users.view')) {
            abort_unless($tenantId !== null && $this->isTenantMember($userId, $tenantId), 404, 'User not found.');
        }

        $activeSession = null;
        $signalingMetadata = null;
        if ($tenantId !== null && $this->isTenantMember($userId, $tenantId)) {
            $viewerId = (string) $request->user()->id;
            if ($authorization->hasTenant($viewerId, $tenantId, 'telephony.sessions.manage')
                || ($userId === $viewerId && $authorization->hasTenant($viewerId, $tenantId, 'telephony.sessions.view_own'))) {
                $activeSession = $telephony->mostRecentSessionForUser($tenantId, $userId);
            }
            if ($activeSession !== null && $authorization->hasTenant($viewerId, $tenantId, 'telephony.signaling.manage')) {
                $signalingMetadata = $signaling->metadata($tenantId, $userId, (string) $activeSession['id'], false);
            }
        }

        return response()->json([
            'user' => $this->serializeUserDetail($user),
            'memberships' => $this->membershipsForUser($userId),
            'platform_roles' => $this->platformRolesForUser($userId),
            'effective_capabilities' => [
                'platform' => $authorization->platformCapabilities($userId),
                'tenant' => $tenantId === null ? [] : $authorization->tenantCapabilities($userId, $tenantId),
            ],
            'active_telephony_session' => $activeSession,
            'signaling' => $signalingMetadata,
        ]);
    }

    public function endTelephonySession(
        Request $request,
        string $userId,
        string $telephonySession,
        AuthorizationService $authorization,
        TelephonyDomainService $telephony,
    ): JsonResponse {
        $tenantId = $this->requireActiveTenant($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.sessions.manage');

        return response()->json($telephony->endSessionForUser($request, $tenantId, $userId, $telephonySession, 'admin_ended'));
    }

    public function issueSignalingCredential(
        Request $request,
        string $userId,
        string $telephonySession,
        AuthorizationService $authorization,
        SignalingCredentialService $signaling,
    ): JsonResponse {
        $tenantId = $this->requireActiveTenant($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.signaling.manage');

        return response()->json($signaling->issueForUser(
            $tenantId,
            $userId,
            $telephonySession,
            IdentityContext::fromRequest($request, $tenantId),
        ));
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

    public function resetPassword(Request $request, string $userId, AuthorizationService $authorization, ResetUserPasswordService $reset): JsonResponse
    {
        $authorization->requirePlatform($request->user()->id, 'platform.users.manage');
        $result = $reset->resetFromRequest($request, $userId);

        return response()->json([
            'expires_at' => $result->expiresAt->toIso8601String(),
            'password_change_required' => true,
            'temporary_password_displayed' => false,
        ]);
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

    private function authorizeUserView(Request $request, AuthorizationService $authorization, ?string $tenantId): void
    {
        if ($authorization->hasPlatform($request->user()->id, 'platform.users.view')) {
            return;
        }
        if ($tenantId !== null && $authorization->hasTenant($request->user()->id, $tenantId, 'tenant.memberships.view')) {
            return;
        }

        throw new HttpException(403, 'Forbidden');
    }

    private function activeTenantId(Request $request): ?string
    {
        $value = $request->session()->get('active_tenant_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function requireActiveTenant(Request $request): string
    {
        $tenantId = $this->activeTenantId($request);
        abort_unless($tenantId !== null, 409, 'Active tenant context is required.');

        return $tenantId;
    }

    private function isTenantMember(string $userId, string $tenantId): bool
    {
        return DB::table('tenant_memberships')->where('user_id', $userId)->where('tenant_id', $tenantId)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUserSummary(object $user, ?string $tenantId): array
    {
        $activeSession = $tenantId === null ? null : DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('issued_at')
            ->first();
        $registration = $activeSession === null ? null : DB::table('signaling_registration_observations')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $activeSession->id)
            ->first();

        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'password_change_required' => (bool) $user->password_change_required,
            'updated_at' => $user->updated_at,
            'membership_summary' => $this->membershipSummary($user->id, $tenantId),
            'role_summary' => $this->roleSummary($user->id, $tenantId),
            'active_telephony_session' => $activeSession === null ? null : [
                'id' => $activeSession->id,
                'status' => $activeSession->status,
                'issued_at' => $activeSession->issued_at,
                'expires_at' => $activeSession->expires_at,
            ],
            'signaling_registration_summary' => $registration === null ? null : [
                'desired_state' => $registration->desired_state,
                'observed_state' => $registration->observed_state,
                'observed_at' => $registration->observed_at,
                'observed_expires_at' => $registration->observed_expires_at,
                'pending_removal' => $registration->desired_state === 'removed' && in_array($registration->observed_state, ['registered', 'pending_removal'], true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUserDetail(object $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'password_change_required' => (bool) $user->password_change_required,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'last_login_at' => $user->last_login_at,
            'password_changed_at' => $user->password_changed_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipSummary(string $userId, ?string $tenantId): array
    {
        $query = DB::table('tenant_memberships')->where('user_id', $userId);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'suspended' => (clone $query)->where('status', 'suspended')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleSummary(string $userId, ?string $tenantId): array
    {
        return [
            'platform' => $this->platformRolesForUser($userId),
            'tenant' => $tenantId === null ? [] : $this->tenantRolesForUser($userId, $tenantId),
        ];
    }

    /**
     * @return list<string>
     */
    private function platformRolesForUser(string $userId): array
    {
        return DB::table('platform_role_assignments')
            ->where('user_id', $userId)
            ->orderBy('role_key')
            ->pluck('role_key')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tenantRolesForUser(string $userId, string $tenantId): array
    {
        return DB::table('tenant_memberships')
            ->join('tenant_role_assignments', 'tenant_role_assignments.membership_id', '=', 'tenant_memberships.id')
            ->where('tenant_memberships.user_id', $userId)
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->orderBy('tenant_role_assignments.role_key')
            ->pluck('tenant_role_assignments.role_key')
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipsForUser(string $userId): array
    {
        return DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->where('tenant_memberships.user_id', $userId)
            ->orderBy('tenants.display_name')
            ->get([
                'tenant_memberships.id',
                'tenant_memberships.tenant_id',
                'tenant_memberships.status',
                'tenant_memberships.created_at',
                'tenant_memberships.updated_at',
                'tenants.slug',
                'tenants.display_name',
            ])
            ->map(fn (object $membership): array => [
                'id' => $membership->id,
                'tenant_id' => $membership->tenant_id,
                'tenant_slug' => $membership->slug,
                'tenant_display_name' => $membership->display_name,
                'status' => $membership->status,
                'roles' => DB::table('tenant_role_assignments')
                    ->where('membership_id', $membership->id)
                    ->orderBy('role_key')
                    ->pluck('role_key')
                    ->all(),
                'created_at' => $membership->created_at,
                'updated_at' => $membership->updated_at,
            ])
            ->all();
    }
}
