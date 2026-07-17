<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ConferenceController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.view');

        return response()->json(['conferences' => $domain->listConferences($tenantId)]);
    }

    public function show(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.view');

        return response()->json(['conference' => $domain->conference($tenantId, $conference)]);
    }

    public function joinSelf(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.join');

        return response()->json($domain->admitSelf($request, $tenantId, $conference, $this->idempotencyKey($request)), 201);
    }

    public function removeSelf(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.join');

        return response()->json($domain->removeSelfFromConference($request, $tenantId, $conference));
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
            Log::info('conference participant idempotency key rejected', ['component' => 'telephony-domain', 'result' => 'invalid_idempotency_key']);

            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
