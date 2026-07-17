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

final class AdminConferenceController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.view');

        return response()->json(['conferences' => $domain->listConferences($tenantId)]);
    }

    public function store(Request $request, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.manage');
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:100'],
            'display_name' => ['required', 'string', 'max:160'],
            'runtime_node_id' => ['nullable', 'uuid'],
        ]);

        try {
            return response()->json($domain->createConference($request, $tenantId, $data, $this->idempotencyKey($request)), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.view');

        return response()->json(['conference' => $domain->conference($tenantId, $conference)]);
    }

    public function desiredState(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.manage');
        $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]);

        try {
            return response()->json($domain->changeConferenceDesiredState($request, $tenantId, $conference, $data['desired_state']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function runtimeBinding(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.manage');
        $data = $request->validate(['runtime_node_id' => ['required', 'uuid']]);

        return response()->json($domain->bindRuntimeNode($request, $tenantId, $conference, $data['runtime_node_id']));
    }

    public function participants(Request $request, string $conference, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.participants.manage');

        return response()->json(['participants' => $domain->participants($tenantId, $conference)]);
    }

    public function removeParticipant(Request $request, string $conference, string $participant, AuthorizationService $authorization, TelephonyDomainService $domain): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'telephony.conferences.participants.manage');

        return response()->json($domain->removeParticipant($request, $tenantId, $participant));
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
            Log::info('conference idempotency key rejected', ['component' => 'telephony-domain', 'result' => 'invalid_idempotency_key']);

            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
