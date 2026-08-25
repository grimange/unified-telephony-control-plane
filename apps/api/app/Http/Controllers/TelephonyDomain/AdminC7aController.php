<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\C7aService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AdminC7aController extends Controller
{
    public function trunks(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.view');
        return response()->json(['external_trunks' => $service->listTrunks($tenant)]);
    }

    public function trunk(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.view');
        return response()->json(['external_trunk' => $service->trunk($tenant, $trunk)]);
    }

    public function createTrunk(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createTrunk($request, $tenant, $request->validate($this->trunkRules()), $this->idempotencyKey($request)), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function updateTrunk(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->updateTrunk($request, $tenant, $trunk, $request->validate($this->trunkRules(false)))); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function trunkState(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]); return response()->json($service->changeTrunkState($request, $tenant, $trunk, $data['desired_state'])); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function endpoint(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createEndpoint($request, $tenant, $trunk, $request->validate($this->endpointRules())), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function endpointState(Request $request, string $trunk, string $endpoint, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]); return response()->json($service->changeEndpointState($request, $tenant, $trunk, $endpoint, $data['desired_state'])); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function attachAddress(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->attachAddress($request, $tenant, $trunk, $request->validate(['telephony_address_id' => ['required', 'uuid'], 'direction' => ['required', 'string', 'in:inbound,outbound,both']])), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function credential(Request $request, string $trunk, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createCredential($request, $tenant, $trunk, $request->validate($this->credentialRules()), $this->idempotencyKey($request)), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function addresses(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.view');
        return response()->json(['telephony_addresses' => $service->listAddresses($tenant)]);
    }

    public function createAddress(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createAddress($request, $tenant, $request->validate(['address_type' => ['required', 'string', 'max:24'], 'value' => ['required', 'string', 'max:255']]), $this->idempotencyKey($request)), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function addressState(Request $request, string $address, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]); return response()->json($service->changeAddressState($request, $tenant, $address, $data['desired_state'])); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function callerIdentities(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.view');
        return response()->json(['caller_identities' => $service->listCallerIdentities($tenant)]);
    }

    public function createCallerIdentity(Request $request, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createCallerIdentity($request, $tenant, $request->validate(['name' => ['required', 'string', 'max:160'], 'telephony_address_id' => ['required', 'uuid'], 'display_name' => ['nullable', 'string', 'max:160']]), $this->idempotencyKey($request)), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function callerIdentityState(Request $request, string $identity, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]); return response()->json($service->changeCallerIdentityState($request, $tenant, $identity, $data['desired_state'])); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function callerIdentityPolicy(Request $request, string $identity, AuthorizationService $authorization, C7aService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.external_connectivity.manage');
        try { return response()->json($service->createPolicy($request, $tenant, $identity, $request->validate(['external_trunk_id' => ['required', 'uuid']])), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    private function tenantId(Request $request): string { $tenant = $request->session()->get('active_tenant_id'); abort_unless(is_string($tenant), 409, 'Active tenant context is required.'); return $tenant; }
    private function trunkRules(bool $required = true): array { $p = $required ? 'required' : 'sometimes'; return ['name' => [$p, 'string', 'max:160'], 'slug' => [$p, 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'supported_directions' => ['sometimes', 'array'], 'supported_directions.*' => ['string', 'in:inbound,outbound'], 'capabilities' => ['sometimes', 'array'], 'capabilities.*' => ['string', 'max:120']]; }
    private function endpointRules(): array { return ['endpoint_uri' => ['required', 'string', 'max:255'], 'transport' => ['sometimes', 'string', 'in:udp,tcp,tls'], 'authentication_mode' => ['sometimes', 'string', 'in:none,credentials'], 'credential_reference_id' => ['nullable', 'uuid'], 'signaling_mode' => ['sometimes', 'string', 'in:static,outbound_registration'], 'registration_target' => ['nullable', 'string', 'max:255'], 'registration_realm' => ['nullable', 'string', 'max:255'], 'registration_identity' => ['nullable', 'string', 'max:160'], 'capabilities' => ['sometimes', 'array'], 'capabilities.*' => ['string', 'max:120'], 'priority' => ['sometimes', 'integer', 'min:1', 'max:100000']]; }
    private function credentialRules(): array { return ['credential_type' => ['required', 'string', 'max:60'], 'identifier' => ['nullable', 'string', 'max:160'], 'secret' => ['required', 'string', 'min:8', 'max:4096'], 'expires_at' => ['nullable', 'date']]; }
    private function idempotencyKey(Request $request): ?IdempotencyKey { $value = $request->header('Idempotency-Key'); if (! is_string($value) || $value === '') return null; try { return IdempotencyKey::fromString($value); } catch (InvalidArgumentException) { Log::info('c7a idempotency key rejected', ['result' => 'invalid']); abort(response()->json(['message' => 'Invalid idempotency key.'], 422)); } }
}
