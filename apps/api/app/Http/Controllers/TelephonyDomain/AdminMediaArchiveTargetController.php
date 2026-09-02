<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\MediaArchiveTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AdminMediaArchiveTargetController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.view');
        return response()->json(['recording_archive_targets' => $service->listTargets($tenant)]);
    }

    public function store(Request $request, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.manage');
        try { return response()->json($service->createTarget($request, $tenant, $request->validate($this->targetRules()), $this->idempotencyKey($request)), 201); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function show(Request $request, string $target, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.view');
        return response()->json(['recording_archive_target' => $service->target($tenant, $target)]);
    }

    public function update(Request $request, string $target, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.manage');
        try { return response()->json($service->updateTarget($request, $tenant, $target, $request->validate($this->targetRules(false)))); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function desiredState(Request $request, string $target, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.manage');
        try { $data = $request->validate(['desired_state' => ['required', 'string', 'max:24']]); return response()->json($service->changeTargetState($request, $tenant, $target, $data['desired_state'])); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function credential(Request $request, string $target, AuthorizationService $authorization, MediaArchiveTargetService $service): JsonResponse
    {
        $tenant = $this->tenantId($request); $authorization->requireTenant($request->user()->id, $tenant, 'telephony.recording_archive_targets.manage');
        try { return response()->json($service->putCredential($request, $tenant, $target, $request->validate(['identifier' => ['nullable', 'string', 'max:160'], 'secret' => ['required', 'string', 'min:8', 'max:4096']]), $this->idempotencyKey($request))); } catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    private function tenantId(Request $request): string { $tenant = $request->session()->get('active_tenant_id'); abort_unless(is_string($tenant), 409, 'Active tenant context is required.'); return $tenant; }
    private function targetRules(bool $required = true): array { $p = $required ? 'required' : 'sometimes'; return ['name' => [$p, 'string', 'max:160'], 'slug' => [$p, 'string', 'max:100'], 'description' => ['nullable', 'string'], 'target_kind' => ['sometimes', 'string', 'in:s3_compatible'], 'endpoint_url' => [$p, 'string', 'max:255'], 'region' => ['nullable', 'string', 'max:64'], 'bucket' => [$p, 'string', 'max:255'], 'object_prefix' => ['nullable', 'string', 'max:255']]; }
    private function idempotencyKey(Request $request): ?IdempotencyKey { $value = $request->header('Idempotency-Key'); if (! is_string($value) || $value === '') return null; try { return IdempotencyKey::fromString($value); } catch (InvalidArgumentException) { Log::info('archive target idempotency key rejected', ['result' => 'invalid']); abort(response()->json(['message' => 'Invalid idempotency key.'], 422)); } }
}
