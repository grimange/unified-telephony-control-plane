<?php

namespace App\Http\Controllers\RuntimeRegistry;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityContext;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationRegistry;
use App\RuntimeRegistry\RuntimeEvidenceService;
use App\RuntimeRegistry\RuntimeNodeHistoryService;
use App\RuntimeRegistry\RuntimeRegistryCatalog;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AdminRuntimeNodeController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json(['runtime_nodes' => $registry->listNodes($tenantId)]);
    }

    public function catalog(Request $request, AuthorizationService $authorization, RuntimeRegistryCatalog $catalog): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json(['catalog' => $catalog->managementCatalog()]);
    }

    public function show(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json(['runtime_node' => $registry->node($runtimeNode, $tenantId)]);
    }

    public function store(Request $request, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate($this->nodeRules());

        try {
            return response()->json($registry->createNode($request, $tenantId, $data, $this->idempotencyKey($request)), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'runtime_family' => ['sometimes', 'string', 'max:40'],
            'adapter_key' => ['sometimes', 'string', 'max:80'],
            'placement_region' => ['nullable', 'string', 'max:80'],
            'placement_zone' => ['nullable', 'string', 'max:80'],
            'placement_priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'capacity_weight' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'labels' => ['sometimes', 'array'],
        ]);

        try {
            return response()->json($registry->updateNode($request, $tenantId, $runtimeNode, $data));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function desiredState(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate([
            'desired_state' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            return response()->json($registry->changeDesiredState($request, $tenantId, $runtimeNode, $data['desired_state'], $data['reason'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function addEndpoint(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate($this->endpointRules());

        try {
            return response()->json($registry->addEndpoint($request, $tenantId, $runtimeNode, $data), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function updateEndpoint(Request $request, string $runtimeNode, string $endpoint, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate($this->endpointRules(false));

        try {
            return response()->json($registry->updateEndpoint($request, $tenantId, $runtimeNode, $endpoint, $data));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function removeEndpoint(Request $request, string $runtimeNode, string $endpoint, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $registry->removeEndpoint($request, $tenantId, $runtimeNode, $endpoint);

        return response()->json(['message' => 'endpoint_removed']);
    }

    public function setCapabilities(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate(['capabilities' => ['required', 'array'], 'capabilities.*' => ['string', 'max:120']]);

        try {
            return response()->json($registry->setCapabilities($request, $tenantId, $runtimeNode, $data['capabilities']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function createCredential(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.credentials.rotate');
        $data = $request->validate($this->credentialRules());

        try {
            return response()->json($registry->createCredential($request, $tenantId, $runtimeNode, $data, $this->idempotencyKey($request)), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function rotateCredential(Request $request, string $runtimeNode, string $credential, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.credentials.rotate');
        $data = $request->validate($this->credentialRules());

        try {
            return response()->json($registry->rotateCredential($request, $tenantId, $runtimeNode, $credential, $data, $this->idempotencyKey($request)));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function retireCredential(Request $request, string $runtimeNode, string $credential, AuthorizationService $authorization, RuntimeRegistryService $registry): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.credentials.rotate');

        try {
            return response()->json($registry->retireCredential($request, $tenantId, $runtimeNode, $credential));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function adapterConfiguration(Request $request, string $runtimeNode, AuthorizationService $authorization, AdapterConfigurationRegistry $adapters): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        try {
            $node = $this->runtimeNode($tenantId, $runtimeNode);
            $context = IdentityContext::fromRequest($request, $tenantId);
            $handler = $adapters->forNode($node);

            return response()->json(['adapter_configuration' => $handler->read($node, $context)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function putAdapterConfiguration(Request $request, string $runtimeNode, AuthorizationService $authorization, AdapterConfigurationRegistry $adapters): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');

        try {
            $node = $this->runtimeNode($tenantId, $runtimeNode);
            $context = IdentityContext::fromRequest($request, $tenantId);
            $handler = $adapters->forNode($node);
            $validated = $handler->validate($node, $request->all(), $context);

            return response()->json(['adapter_configuration' => $handler->update($node, $validated, $context)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function runtimeEvidence(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeEvidenceService $evidence): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        try {
            return response()->json(['runtime_evidence' => $evidence->show($tenantId, $runtimeNode)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function history(Request $request, string $runtimeNode, AuthorizationService $authorization, RuntimeNodeHistoryService $history): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before' => ['sometimes', 'date'],
        ]);

        try {
            return response()->json($history->list($tenantId, $runtimeNode, (int) ($data['limit'] ?? 25), $data['before'] ?? null));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        return $tenantId;
    }

    private function runtimeNode(string $tenantId, string $runtimeNodeId): object
    {
        $node = DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->first();

        abort_unless($node !== null, 404, 'Runtime node not found.');

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:100'],
            'runtime_family' => ['required', 'string', 'max:40'],
            'adapter_key' => ['required', 'string', 'max:80'],
            'placement_region' => ['nullable', 'string', 'max:80'],
            'placement_zone' => ['nullable', 'string', 'max:80'],
            'placement_priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'capacity_weight' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'labels' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'purpose' => [$presence, 'string', 'max:40'],
            'transport' => [$presence, 'string', 'max:20'],
            'host' => [$presence, 'string', 'max:253'],
            'port' => [$presence, 'integer', 'min:1', 'max:65535'],
            'path' => ['nullable', 'string', 'max:255'],
            'tls_mode' => ['sometimes', 'string', 'max:32'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialRules(): array
    {
        return [
            'credential_type' => ['required', 'string', 'max:60'],
            'identifier' => ['nullable', 'string', 'max:160'],
            'secret' => ['required', 'string', 'min:8', 'max:4096'],
            'expires_at' => ['nullable', 'date'],
        ];
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
            Log::info('runtime registry idempotency key rejected', ['component' => 'runtime_registry', 'result' => 'invalid_idempotency_key']);

            abort(response()->json(['message' => 'Invalid idempotency key.'], 422));
        }
    }
}
