<?php

namespace App\ControlPlane\RuntimeReconciliation;

use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RuntimeReconciliationQuery
{
    private const STATUS_VALUES = [
        'waiting',
        'leased',
        'converged',
        'operation_required',
        'blocked',
        'unsupported',
        'retry_scheduled',
    ];

    public function __construct(private readonly ReconcilerRegistry $reconcilers) {}

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return self::STATUS_VALUES;
    }

    /**
     * @return list<string>
     */
    public function targetTypes(): array
    {
        return $this->reconcilers->targetTypes();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, object>, total: int}
     */
    public function paginate(string $tenantId, array $filters, int $page, int $perPage): array
    {
        $query = $this->baseQuery($tenantId);
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('runtime_reconciliation_states.updated_at')
            ->orderByDesc('runtime_reconciliation_states.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $runtimeReconciliationId): ?object
    {
        return $this->baseQuery($tenantId)
            ->where('runtime_reconciliation_states.id', $runtimeReconciliationId)
            ->first();
    }

    private function baseQuery(string $tenantId): Builder
    {
        return DB::table('runtime_reconciliation_states')
            ->leftJoin('runtime_nodes', function ($join): void {
                $join
                    ->where('runtime_reconciliation_states.target_type', '=', 'runtime_node')
                    ->on(DB::raw('CAST(runtime_nodes.id AS TEXT)'), '=', 'runtime_reconciliation_states.target_id')
                    ->on('runtime_nodes.tenant_id', '=', 'runtime_reconciliation_states.tenant_id');
            })
            ->leftJoin('runtime_operations', function ($join): void {
                $join
                    ->on('runtime_operations.id', '=', 'runtime_reconciliation_states.last_operation_id')
                    ->on('runtime_operations.tenant_id', '=', 'runtime_reconciliation_states.tenant_id');
            })
            ->where('runtime_reconciliation_states.tenant_id', $tenantId)
            ->select([
                'runtime_reconciliation_states.id',
                'runtime_reconciliation_states.tenant_id',
                'runtime_reconciliation_states.target_type',
                'runtime_reconciliation_states.target_id',
                'runtime_reconciliation_states.desired_generation',
                'runtime_reconciliation_states.observed_generation',
                'runtime_reconciliation_states.status',
                'runtime_reconciliation_states.last_checked_at',
                'runtime_reconciliation_states.next_check_at',
                'runtime_reconciliation_states.last_operation_id',
                'runtime_reconciliation_states.blocked_reason',
                'runtime_reconciliation_states.attempt_count',
                'runtime_reconciliation_states.created_at',
                'runtime_reconciliation_states.updated_at',
                'runtime_nodes.name as runtime_node_name',
                'runtime_nodes.slug as runtime_node_slug',
                'runtime_nodes.runtime_family as runtime_node_runtime_family',
                'runtime_nodes.adapter_key as runtime_node_adapter_key',
                'runtime_operations.operation_type as runtime_operation_type',
                'runtime_operations.status as runtime_operation_status',
                'runtime_operations.created_at as runtime_operation_created_at',
                'runtime_operations.completed_at as runtime_operation_completed_at',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['runtime_node_id'])) {
            $query
                ->where('runtime_reconciliation_states.target_type', 'runtime_node')
                ->where('runtime_reconciliation_states.target_id', $filters['runtime_node_id']);
        }

        if (isset($filters['status'])) {
            $query->where('runtime_reconciliation_states.status', $filters['status']);
        }

        if (isset($filters['target_type'])) {
            $query->where('runtime_reconciliation_states.target_type', $filters['target_type']);
        }

        if (isset($filters['runtime_operation_id'])) {
            $query->where('runtime_reconciliation_states.last_operation_id', strtolower((string) $filters['runtime_operation_id']));
        }

        if (isset($filters['updated_from'])) {
            $query->where('runtime_reconciliation_states.updated_at', '>=', Carbon::parse($filters['updated_from']));
        }

        if (isset($filters['updated_to'])) {
            $query->where('runtime_reconciliation_states.updated_at', '<', Carbon::parse($filters['updated_to']));
        }
    }
}
