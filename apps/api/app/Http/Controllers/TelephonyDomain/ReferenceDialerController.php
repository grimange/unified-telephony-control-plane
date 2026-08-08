<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\Signaling\SignalingCredentialService;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferenceDialerController extends Controller
{
    public function bootstrap(
        Request $request,
        AuthorizationService $authorization,
        TelephonyDomainService $domain,
        SignalingCredentialService $signaling,
    ): JsonResponse {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        $userId = (string) $request->user()->id;
        $authorization->requireTenant($userId, $tenantId, 'telephony.sessions.view_own');
        $authorization->requireTenant($userId, $tenantId, 'telephony.signaling.view_own');
        $authorization->requireTenant($userId, $tenantId, 'telephony.conferences.view');

        $telephonySession = $domain->currentSession($tenantId, $userId);

        return response()->json([
            'application' => 'reference-dialer',
            'tenant_id' => $tenantId,
            'telephony_session' => $telephonySession,
            'signaling' => $telephonySession === null
                ? null
                : $signaling->metadata($tenantId, $userId, (string) $telephonySession['id']),
            'conferences' => $domain->listConferences($tenantId),
        ]);
    }
}
