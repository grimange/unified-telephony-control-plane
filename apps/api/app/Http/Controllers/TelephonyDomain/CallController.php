<?php

namespace App\Http\Controllers\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\Telephony\CallLegResource;
use App\Http\Resources\Telephony\CallOperationResource;
use App\Http\Resources\Telephony\CallResource;
use App\Http\Resources\Telephony\CallTimelineResource;
use App\Identity\Authorization\AuthorizationService;
use App\TelephonyDomain\CallDomainService;
use App\TelephonyDomain\CallOperationCatalog;
use App\TelephonyDomain\CallQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CallController extends Controller
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly CallQueryService $queries, private readonly CallDomainService $calls) {}

    public function index(Request $request)
    {
        $tenant = $this->tenant($request, 'telephony.calls.view');
        $data = $request->validate(['state' => ['sometimes', 'string', 'max:40'], 'direction' => ['sometimes', Rule::in(['inbound', 'outbound'])], 'created_from' => ['sometimes', 'date'], 'created_to' => ['sometimes', 'date'], 'terminal' => ['sometimes', 'boolean'], 'runtime_node_id' => ['sometimes', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 25);
        $result = $this->queries->calls($tenant, $data, $page, $perPage);

        return $this->paginated(CallResource::collection($result['rows']), $result);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request, 'telephony.calls.originate');
        $data = $request->validate(['direction' => ['required', Rule::in(['outbound'])], 'runtime_node_id' => ['nullable', 'uuid'], 'destination_ref' => ['required', 'string', 'max:255']]);
        $context = $this->context($request, $tenant);
        $key = $this->idempotency($request);
        try {
            $result = $this->calls->createOutboundCall($tenant, $context, $key, $data['runtime_node_id'] ?? null, $data['destination_ref']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $call = $this->queries->call($tenant, $result['call_id']);

        return (new CallResource($call))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.calls.view');
        $row = $this->queries->call($tenant, $call);
        abort_if($row === null, 404);

        return new CallResource($row);
    }

    public function legs(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.calls.view');
        $this->requireCall($tenant, $call);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $result = $this->queries->legs($tenant, $call, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));

        return $this->paginated(CallLegResource::collection($result['rows']), $result);
    }

    public function storeOperation(Request $request, string $call)
    {
        $tenant = $this->tenant($request);
        $this->requireCall($tenant, $call);
        $data = $request->validate(['operation_type' => ['required', 'string', Rule::in(array_keys(CallOperationCatalog::all()))], 'target_leg_id' => ['nullable', 'uuid'], 'leg_ids' => ['nullable', 'array', 'size:2'], 'leg_ids.*' => ['uuid'], 'payload' => ['sometimes', 'array']]);
        $type = $data['operation_type'];
        $this->authorization->requireTenant($request->user()->id, $tenant, $this->permissionFor($type));
        $definition = CallOperationCatalog::all()[$type];
        $payload = $data['payload'] ?? [];
        $aggregateType = $definition['target'];
        $aggregateId = $call;
        if ($aggregateType === 'call_leg') {
            if (! isset($data['target_leg_id'])) {
                throw new HttpException(422, 'target_leg_id is required for this operation');
            }
            $leg = DB::table('call_legs')->where('tenant_id', $tenant)->where('call_id', $call)->where('id', $data['target_leg_id'])->first();
            if ($leg === null) {
                abort(404);
            }
            $aggregateId = (string) $leg->id;
            $payload += ['call_id' => $call, 'leg_id' => $aggregateId];
        } elseif ($aggregateType === 'relationship') {
            $payload['leg_ids'] = $data['leg_ids'] ?? null;
        }
        $payload['call_id'] ??= $call;
        $runtimeNodeId = DB::table($aggregateType === 'call_leg' ? 'call_legs' : 'calls')->where('id', $aggregateId)->value('runtime_node_id');
        if ($aggregateType === 'relationship' && $runtimeNodeId === null && isset($payload['leg_ids'][0])) {
            $runtimeNodeId = DB::table('call_legs')->where('id', $payload['leg_ids'][0])->value('runtime_node_id');
        }
        try {
            $operationId = $this->calls->requestOperation($tenant, $this->context($request, $tenant), $type, $aggregateType, $aggregateId, $payload, $this->idempotency($request), $runtimeNodeId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $operation = DB::table('runtime_operations')->where('tenant_id', $tenant)->where('id', $operationId)->first();

        return (new CallOperationResource($operation))->response()->setStatusCode(202);
    }

    public function storeLeg(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.calls.originate');
        $data = $request->validate(['runtime_node_id' => ['nullable', 'uuid'], 'destination_ref' => ['required', 'string', 'max:255']]);

        try {
            $result = $this->calls->createOutboundLeg($tenant, $this->context($request, $tenant), $call, $this->idempotency($request), $data['runtime_node_id'] ?? null, $data['destination_ref']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $leg = DB::table('call_legs')->where('tenant_id', $tenant)->where('id', $result['leg_id'])->first();

        return (new CallLegResource($leg))->response()->setStatusCode(201);
    }

    public function operations(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.calls.view');
        $this->requireCall($tenant, $call);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $result = $this->queries->operations($tenant, $call, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));

        return $this->paginated(CallOperationResource::collection($result['rows']), $result);
    }

    public function timeline(Request $request, string $call)
    {
        $tenant = $this->tenant($request, 'telephony.calls.view');
        $this->requireCall($tenant, $call);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $result = $this->queries->timeline($tenant, $call, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));

        return $this->paginated(CallTimelineResource::collection($result['rows']), $result);
    }

    private function tenant(Request $request, ?string $permission = null): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        if (! is_string($tenant) || $tenant === '') {
            abort(409, 'Active tenant context is required');
        }
        if ($permission !== null) {
            $this->authorization->requireTenant($request->user()->id, $tenant, $permission);
        }

        return $tenant;
    }

    private function requireCall(string $tenant, string $call): object
    {
        $row = $this->queries->call($tenant, $call);
        abort_if($row === null, 404);

        return $row;
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

    private function permissionFor(string $type): string
    {
        if (str_contains($type, 'recording')) {
            return 'telephony.calls.record';
        }
        if ($type === 'call.leg.originate' || $type === 'call.leg.cancel_origination') {
            return 'telephony.calls.originate';
        }

        return 'telephony.calls.control';
    }

    private function paginated($resources, array $result)
    {
        return response()->json(['data' => $resources->resolve(), 'pagination' => ['page' => $result['page'], 'per_page' => $result['per_page'], 'total' => $result['total'], 'has_more' => $result['has_more']]]);
    }
}
