<?php

namespace App\Http\Controllers\ControlPlane;

use App\ControlPlane\RuntimeReconciliation\RuntimeReconciliationQuery;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuntimeReconciliationDetailResource;
use App\Http\Resources\RuntimeReconciliationListResource;
use App\Identity\Authorization\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class AdminRuntimeReconciliationController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, RuntimeReconciliationQuery $reconciliations): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'runtime_node_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in($reconciliations->statuses())],
            'target_type' => ['sometimes', Rule::in($reconciliations->targetTypes())],
            'runtime_operation_id' => ['sometimes', 'regex:/\A[0-9a-fA-F]{32}\z/'],
            'updated_from' => ['sometimes', 'date'],
            'updated_to' => ['sometimes', 'date', 'after_or_equal:updated_from'],
        ]);

        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($data['per_page'] ?? 20)));
        $result = $reconciliations->paginate($tenantId, $data, $page, $perPage);

        return response()->json([
            'runtime_reconciliations' => RuntimeReconciliationListResource::collection($result['rows'])->resolve($request),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'has_more' => ($page * $perPage) < $result['total'],
            ],
        ]);
    }

    public function show(Request $request, string $runtimeReconciliation, AuthorizationService $authorization, RuntimeReconciliationQuery $reconciliations): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $authorization->requireTenant($request->user()->id, $tenantId, 'runtime.nodes.view');

        try {
            $reconciliationId = RuntimeOperationId::fromString($runtimeReconciliation)->value();
        } catch (InvalidArgumentException) {
            abort(404, 'Runtime reconciliation not found.');
        }

        $reconciliation = $reconciliations->find($tenantId, $reconciliationId);
        abort_unless($reconciliation !== null, 404, 'Runtime reconciliation not found.');

        return response()->json(['runtime_reconciliation' => (new RuntimeReconciliationDetailResource($reconciliation))->resolve($request)]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId), 409, 'Active tenant context is required.');

        return $tenantId;
    }
}
