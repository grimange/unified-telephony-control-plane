<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIdentitySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status !== 'active' || (int) $request->session()->get('user_session_version') !== (int) $user->session_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->password_change_required) {
            if ($user->temporary_password_expires_at !== null && $user->temporary_password_expires_at->isPast()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            if (! $this->allowsForcedPasswordChangeRequest($request)) {
                return response()->json(['message' => 'Password change required.'], 403);
            }
        }

        $tenantId = $request->session()->get('active_tenant_id');
        if (is_string($tenantId)) {
            $active = DB::table('tenant_memberships')
                ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
                ->where('tenant_memberships.user_id', $user->id)
                ->where('tenant_memberships.tenant_id', $tenantId)
                ->where('tenant_memberships.status', 'active')
                ->where('tenants.status', 'active')
                ->exists();

            if (! $active) {
                $request->session()->forget('active_tenant_id');
            }
        }

        return $next($request);
    }

    private function allowsForcedPasswordChangeRequest(Request $request): bool
    {
        if ($request->is('api/v1/auth/session') || $request->is('api/v1/auth/logout') || $request->is('api/v1/auth/change-password')) {
            return true;
        }

        return false;
    }
}
