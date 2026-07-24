<?php

namespace App\Http\Controllers\ControlPlane;

use App\ControlPlane\Audit\AuditRecordQuery;
use App\ControlPlane\Shared\AuditRecordId;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditRecordDetailResource;
use App\Http\Resources\AuditRecordListResource;
use App\Identity\Authorization\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAuditRecordController extends Controller
{
    private const CAPABILITY = 'tenant.memberships.manage';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditRecordQuery $auditRecords,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $this->authorization->requireTenant($request->user()->id, $tenantId, self::CAPABILITY);

        $validated = $request->validate($this->validationRules());
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($validated['per_page'] ?? 25)));
        $filters = $this->filters($validated);
        $page = $this->normalizePage($tenantId, $filters, $page, $perPage);

        $records = $this->auditRecords->paginate($tenantId, $filters, $page, $perPage);

        return response()->json([
            'audit_records' => AuditRecordListResource::collection($records->items())->resolve($request),
            'pagination' => [
                'page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'has_more' => $records->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, string $auditRecord): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $this->authorization->requireTenant($request->user()->id, $tenantId, self::CAPABILITY);

        try {
            $auditRecordId = AuditRecordId::fromString($auditRecord)->value();
        } catch (\InvalidArgumentException) {
            abort(404, 'Audit record not found.');
        }

        $record = $this->auditRecords->find($tenantId, $auditRecordId);
        abort_unless($record !== null, 404, 'Audit record not found.');

        return response()->json([
            'audit_record' => (new AuditRecordDetailResource($record))->resolve($request),
        ]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenantId) && $tenantId !== '', 409, 'Select an active tenant.');

        return $tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'actor_id' => ['sometimes', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'actor_type' => ['sometimes', 'string', 'max:80', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'action' => ['sometimes', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'subject_type' => ['sometimes', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'subject_id' => ['sometimes', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'correlation_id' => ['sometimes', 'string', 'regex:/\A[0-9a-fA-F]{32}\z/'],
            'request_id' => ['sometimes', 'string', 'regex:/\A[0-9a-fA-F]{32}\z/'],
            'occurred_from' => ['sometimes', 'date'],
            'occurred_to' => ['sometimes', 'date', 'after_or_equal:occurred_from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filters(array $validated): array
    {
        $filters = [];
        foreach (['actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'correlation_id', 'request_id', 'occurred_from', 'occurred_to'] as $key) {
            if (($validated[$key] ?? null) !== null && $validated[$key] !== '') {
                $filters[$key] = $validated[$key];
            }
        }

        foreach (['correlation_id', 'request_id'] as $key) {
            if (isset($filters[$key]) && is_string($filters[$key])) {
                $filters[$key] = strtolower($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizePage(string $tenantId, array $filters, int $page, int $perPage): int
    {
        $total = $this->auditRecords->countForTenant($tenantId, $filters);
        if ($total === 0) {
            return 1;
        }

        $lastPage = (int) max(1, ceil($total / $perPage));

        return min($page, $lastPage);
    }
}
