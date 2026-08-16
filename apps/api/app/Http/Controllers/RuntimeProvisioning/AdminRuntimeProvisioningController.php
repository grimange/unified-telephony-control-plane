<?php

namespace App\Http\Controllers\RuntimeProvisioning;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\RuntimeProvisioning\RuntimeProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminRuntimeProvisioningController extends Controller
{
    public function targets(Request $request, AuthorizationService $authorization, RuntimeProvisioningService $provisioning): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json(['deployment_targets' => $provisioning->listDeploymentTargets($request, $tenantId)]);
    }

    public function target(Request $request, string $deploymentTarget, AuthorizationService $authorization, RuntimeProvisioningService $provisioning): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json($provisioning->deploymentTarget($request, $tenantId, $deploymentTarget));
    }

    public function store(Request $request, AuthorizationService $authorization, RuntimeProvisioningService $provisioning): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.manage');
        $data = $request->validate([
            'deployment_target_id' => ['required', 'uuid'],
            'runtime_family' => ['required', 'string', 'max:40'],
            'adapter_key' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $header = $request->header('Idempotency-Key');
        if (! is_string($header) || $header === '') {
            return response()->json(['message' => 'Idempotency-Key header is required for provisioning requests.'], 422);
        }

        try {
            $key = IdempotencyKey::fromString($header);

            return response()->json($provisioning->requestProvisioning($request, $tenantId, $data, $key), 202);
        } catch (InvalidArgumentException $exception) {
            $payload = ['message' => $exception->getMessage()];
            if ($exception->getMessage() === 'A runtime with this name or identifier already exists.') {
                $payload['errors'] = ['name' => [$exception->getMessage()]];
            }

            return response()->json($payload, 422);
        }
    }

    public function show(Request $request, string $runtimeProvisioning, AuthorizationService $authorization, RuntimeProvisioningService $provisioning): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        return response()->json($provisioning->provisioningRequest($tenantId, $runtimeProvisioning));
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        return $tenantId;
    }
}
