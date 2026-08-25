<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\C7bService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AdminC7bController extends Controller
{
    public function inbound(Request $request, AuthorizationService $authorization, C7bService $service): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenant, 'telephony.routing.view');

        return response()->json(['inbound_routes' => $service->listInbound($tenant)]);
    }

    public function outbound(Request $request, AuthorizationService $authorization, C7bService $service): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenant, 'telephony.routing.view');

        return response()->json(['outbound_routes' => $service->listOutbound($tenant)]);
    }

    public function createInbound(Request $request, AuthorizationService $authorization, C7bService $service): JsonResponse
    {
        return $this->create($request, $authorization, $service, 'inbound');
    }

    public function createOutbound(Request $request, AuthorizationService $authorization, C7bService $service): JsonResponse
    {
        return $this->create($request, $authorization, $service, 'outbound');
    }

    public function state(Request $request, string $kind, string $route, AuthorizationService $authorization, C7bService $service): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenant, 'telephony.routing.manage');
        try {
            $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]);

            return response()->json($service->changeState($request, $tenant, $kind, $route, $data['desired_state']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function create(Request $request, AuthorizationService $authorization, C7bService $service, string $kind): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenant, 'telephony.routing.manage');
        try {
            $data = $request->validate($this->rules($kind));
            $result = $kind === 'inbound'
                ? $service->createInbound($request, $tenant, $data, $this->idempotencyKey($request))
                : $service->createOutbound($request, $tenant, $data, $this->idempotencyKey($request));

            return response()->json($result, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function rules(string $kind): array
    {
        $rules = ['name' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:100'], 'external_trunk_id' => ['required', 'uuid'], 'telephony_address_id' => ['required', 'uuid'], 'priority' => ['sometimes', 'integer', 'min:1', 'max:100000']];

        return $kind === 'inbound' ? [...$rules, 'destination_ref' => ['required']] : [...$rules, 'caller_identity_id' => ['nullable', 'uuid']];
    }

    private function tenantId(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Active tenant context is required.');

        return $tenant;
    }

    private function idempotencyKey(Request $request): ?IdempotencyKey
    {
        $value = $request->header('Idempotency-Key');
        if (! is_string($value) || $value === '') {
            return null;
        } try {
            return IdempotencyKey::fromString($value);
        } catch (InvalidArgumentException) {
            Log::info('c7b idempotency key rejected', ['result' => 'invalid']);
            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
