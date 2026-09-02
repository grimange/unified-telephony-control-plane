<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\Telephony\RecordingSessionResource;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\CallQueryService;
use App\TelephonyDomain\RecordingArtifactService;
use App\TelephonyDomain\RecordingSessionService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

final class RecordingSessionController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly CallQueryService $calls,
        private readonly RecordingSessionService $recordings,
        private readonly RecordingArtifactService $artifacts,
    ) {}

    public function index(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.recordings.view');
        if ($this->calls->call($tenant, $call) === null) {
            abort(404);
        }

        return RecordingSessionResource::collection(array_map(fn (object $session): object => $this->withArtifact($tenant, $session), $this->recordings->forCall($tenant, $call)));
    }

    public function store(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.recordings.manage');
        $data = $request->validate([
            'target_leg_id' => ['required', 'uuid'],
            'conference_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        try {
            $session = $this->recordings->requestStart($tenant, $this->context($request, $tenant), $call, $data['target_leg_id'], $data['conference_id'] ?? null, $this->idempotency($request));
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new RecordingSessionResource($this->withArtifact($tenant, $session)))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $call, string $recordingSession)
    {
        $tenant = $this->tenant($request, 'telephony.recordings.view');
        $session = $this->recordings->forTenant($tenant, $recordingSession);
        abort_if($session === null || (string) $session->call_id !== $call, 404);

        return new RecordingSessionResource($this->withArtifact($tenant, $session));
    }

    public function stop(Request $request, string $call, string $recordingSession)
    {
        $tenant = $this->tenant($request, 'telephony.recordings.manage');
        $session = $this->recordings->forTenant($tenant, $recordingSession);
        abort_if($session === null || (string) $session->call_id !== $call, 404);
        try {
            $result = $this->recordings->requestStop($tenant, $this->context($request, $tenant), $recordingSession, $this->idempotency($request));
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new RecordingSessionResource($this->withArtifact($tenant, $result)))->response()->setStatusCode(202);
    }

    private function tenant(Request $request, string $permission): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        if (! is_string($tenant) || $tenant === '') {
            abort(409, 'Active tenant context is required');
        }
        $this->authorization->requireTenant($request->user()->id, $tenant, $permission);

        return $tenant;
    }

    private function context(Request $request, string $tenant): ExecutionContext
    {
        $base = ExecutionContext::fromRequest($request);

        return new ExecutionContext($base->requestId, $base->correlationId, null, 'user', (string) $request->user()->id, $tenant, null, 'http', $base->occurredAt);
    }

    private function idempotency(Request $request): ?IdempotencyKey
    {
        $value = $request->header('Idempotency-Key');

        return is_string($value) && $value !== '' ? IdempotencyKey::fromString($value) : null;
    }

    private function withArtifact(string $tenantId, object $session): object
    {
        $session->artifact = $this->artifacts->forRecordingSession($tenantId, (string) $session->id);

        return $session;
    }
}
