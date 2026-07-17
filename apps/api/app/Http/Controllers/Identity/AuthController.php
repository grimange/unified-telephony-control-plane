<?php

namespace App\Http\Controllers\Identity;

use App\ControlPlane\Audit\AuditRepository;
use App\Http\Controllers\Controller;
use App\Identity\IdentityContext;
use App\Identity\IdentityProjectionService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function login(Request $request, AuditRepository $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $user = User::query()->where('normalized_email', Str::lower($data['email']))->first();
        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password) || $this->temporaryPasswordExpired($user)) {
            RateLimiter::hit($key, 60);
            $audit->append(IdentityContext::fromRequest($request), 'identity.login.failed', 'authentication_attempt', hash('sha256', Str::lower($data['email'])), [
                'result' => 'failed',
            ]);
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('user_session_version', (int) $user->session_version);

        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now(), 'updated_at' => now()]);
        $audit->append(IdentityContext::fromRequest($request), 'identity.login.succeeded', 'user', $user->id, ['result' => 'succeeded']);

        return response()->json(['message' => 'authenticated']);
    }

    public function logout(Request $request, AuditRepository $audit): JsonResponse
    {
        $user = $request->user();
        if ($user !== null) {
            $audit->append(IdentityContext::fromRequest($request, $request->session()->get('active_tenant_id')), 'identity.logout', 'user', $user->id, []);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'logged_out']);
    }

    public function session(Request $request, IdentityProjectionService $projection): JsonResponse
    {
        return response()->json($projection->sessionProjection($request->user(), $request->session()->get('active_tenant_id')));
    }

    public function selectTenant(Request $request, IdentityProjectionService $projection, AuditRepository $audit): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'string']]);
        $exists = DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->where('tenant_memberships.user_id', $request->user()->id)
            ->where('tenant_memberships.tenant_id', $data['tenant_id'])
            ->where('tenant_memberships.status', 'active')
            ->where('tenants.status', 'active')
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Tenant context is not available.'], 403);
        }

        $request->session()->put('active_tenant_id', $data['tenant_id']);
        $audit->append(IdentityContext::fromRequest($request, $data['tenant_id']), 'identity.tenant_context.selected', 'tenant', $data['tenant_id'], []);

        return response()->json($projection->sessionProjection($request->user(), $data['tenant_id']));
    }

    public function changePassword(Request $request, AuditRepository $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12'],
        ]);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Invalid password.']);
        }

        $nextSessionVersion = DB::transaction(function () use ($request, $audit, $user, $data): int {
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($data['new_password']),
                'password_change_required' => false,
                'temporary_password_issued_at' => null,
                'temporary_password_expires_at' => null,
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
                'session_version' => DB::raw('session_version + 1'),
                'updated_at' => now(),
            ]);
            $audit->append(IdentityContext::fromRequest($request, $request->session()->get('active_tenant_id')), 'identity.user_password_changed', 'user', $user->id, [
                'password_change_required_cleared' => true,
                'temporary_password_cleared' => true,
            ]);

            return (int) DB::table('users')->where('id', $user->id)->value('session_version');
        });

        Auth::guard('web')->login(User::query()->findOrFail($user->id));
        $request->session()->regenerate();
        $request->session()->put('user_session_version', $nextSessionVersion);

        return response()->json(['message' => 'password_changed']);
    }

    private function temporaryPasswordExpired(User $user): bool
    {
        if (! $user->password_change_required || $user->temporary_password_expires_at === null) {
            return false;
        }

        return $user->temporary_password_expires_at->isPast();
    }
}
