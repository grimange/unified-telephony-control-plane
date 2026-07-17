<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityContext;
use App\TelephonyDomain\Signaling\SignalingCredentialService;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class TelephonySessionController extends Controller
{
    public function current(Request $request, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.sessions.view_own');

        return response()->json(['telephony_session' => $domain->currentSession($tenantId, $request->user()->id)]);
    }

    public function store(Request $request, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.sessions.create_own');

        return response()->json($domain->createSession($request, $tenantId, $this->idempotencyKey($request)), 201);
    }

    public function end(Request $request, string $telephonySession, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.sessions.view_own');

        return response()->json($domain->endSession($request, $tenantId, $telephonySession));
    }

    public function issueSignalingCredential(Request $request, string $telephonySession, AuthorizationService $authorization, SignalingCredentialService $signaling): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.signaling.issue_own');

        return response()->json($signaling->issueOwn(
            $tenantId,
            (string) $request->user()->id,
            $telephonySession,
            IdentityContext::fromRequest($request, $tenantId),
        ), 201);
    }

    public function signalingCredential(Request $request, string $telephonySession, AuthorizationService $authorization, SignalingCredentialService $signaling): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.signaling.view_own');

        $metadata = $signaling->metadata($tenantId, (string) $request->user()->id, $telephonySession);
        abort_unless($metadata !== null, 404, 'Telephony session not found.');

        return response()->json($metadata);
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
            Log::info('telephony session idempotency key rejected', ['component' => 'telephony-domain', 'result' => 'invalid_idempotency_key']);

            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
