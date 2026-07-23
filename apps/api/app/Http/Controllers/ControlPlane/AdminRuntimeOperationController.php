<?php

namespace App\Http\Controllers\ControlPlane;

use App\ControlPlane\RuntimeOperations\RuntimeOperationQuery;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuntimeOperationDetailResource;
use App\Http\Resources\RuntimeOperationListResource;
use App\Identity\Authorization\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class AdminRuntimeOperationController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, RuntimeOperationQuery $operations): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'runtime_node_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in($operations->statuses())],
            'operation_type' => ['sometimes', Rule::in($operations->operationTypes())],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'correlation_id' => ['sometimes', 'regex:/\A[0-9a-fA-F]{32}\z/'],
        ]);

        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($data['per_page'] ?? 20)));
        $result = $operations->paginate($tenantId, $data, $page, $perPage);

        return response()->json([
            'runtime_operations' => RuntimeOperationListResource::collection($result['rows'])->resolve($request),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'has_more' => ($page * $perPage) < $result['total'],
            ],
        ]);
    }

    public function show(Request $request, string $runtimeOperation, AuthorizationService $authorization, RuntimeOperationQuery $operations): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        try {
            $operationId = RuntimeOperationId::fromString($runtimeOperation)->value();
        } catch (InvalidArgumentException) {
            abort(404, 'Runtime operation not found.');
        }

        $operation = $operations->find($tenantId, $operationId);
        abort_unless($operation !== null, 404, 'Runtime operation not found.');

        return response()->json(['runtime_operation' => (new RuntimeOperationDetailResource($operation))->resolve($request)]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        return $tenantId;
    }
}
