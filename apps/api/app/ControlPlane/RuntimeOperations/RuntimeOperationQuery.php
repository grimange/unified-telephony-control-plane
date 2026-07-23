<?php

namespace App\ControlPlane\RuntimeOperations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RuntimeOperationQuery
{
    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return array_map(
            static fn (OperationStatus $status): string => $status->value,
            OperationStatus::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public function operationTypes(): array
    {
        $types = array_merge(
            array_values((array) config('telephony_domain.operation_types', [])),
            array_values((array) config('simulator.operation_types', [])),
        );

        $types = array_filter($types, static fn (mixed $type): bool => is_string($type) && trim($type) !== '');
        $types = array_values(array_unique(array_map(static fn (string $type): string => trim($type), $types)));
        sort($types);

        return $types;
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
            ->orderByDesc('runtime_operations.created_at')
            ->orderByDesc('runtime_operations.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $runtimeOperationId): ?object
    {
        return $this->baseQuery($tenantId)
            ->leftJoin('runtime_reconciliation_states as reconciliation', function ($join): void {
                $join
                    ->on('reconciliation.last_operation_id', '=', 'runtime_operations.id')
                    ->on('reconciliation.tenant_id', '=', 'runtime_operations.tenant_id');
            })
            ->addSelect([
                'reconciliation.id as reconciliation_id',
                'reconciliation.target_type as reconciliation_target_type',
                'reconciliation.target_id as reconciliation_target_id',
                'reconciliation.status as reconciliation_status',
            ])
            ->where('runtime_operations.id', $runtimeOperationId)
            ->first();
    }

    private function baseQuery(string $tenantId): Builder
    {
        return DB::table('runtime_operations')
            ->leftJoin('runtime_nodes', function ($join): void {
                $join
                    ->on('runtime_nodes.id', '=', 'runtime_operations.runtime_node_id')
                    ->on('runtime_nodes.tenant_id', '=', 'runtime_operations.tenant_id');
            })
            ->where('runtime_operations.tenant_id', $tenantId)
            ->select([
                'runtime_operations.id',
                'runtime_operations.runtime_node_id',
                'runtime_operations.operation_type',
                'runtime_operations.aggregate_type',
                'runtime_operations.aggregate_id',
                'runtime_operations.payload_version',
                'runtime_operations.status',
                'runtime_operations.priority',
                'runtime_operations.correlation_id',
                'runtime_operations.causation_id',
                'runtime_operations.request_id',
                'runtime_operations.attempt_count',
                'runtime_operations.max_attempts',
                'runtime_operations.available_at',
                'runtime_operations.expires_at',
                'runtime_operations.last_failure_class',
                'runtime_operations.last_failure_code',
                'runtime_operations.started_at',
                'runtime_operations.completed_at',
                'runtime_operations.cancelled_at',
                'runtime_operations.created_at',
                'runtime_operations.updated_at',
                'runtime_nodes.name as runtime_node_name',
                'runtime_nodes.slug as runtime_node_slug',
                'runtime_nodes.runtime_family as runtime_node_runtime_family',
                'runtime_nodes.adapter_key as runtime_node_adapter_key',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['runtime_node_id'])) {
            $query->where('runtime_operations.runtime_node_id', $filters['runtime_node_id']);
        }

        if (isset($filters['status'])) {
            $query->where('runtime_operations.status', $filters['status']);
        }

        if (isset($filters['operation_type'])) {
            $query->where('runtime_operations.operation_type', $filters['operation_type']);
        }

        if (isset($filters['created_from'])) {
            $query->where('runtime_operations.created_at', '>=', Carbon::parse($filters['created_from']));
        }

        if (isset($filters['created_to'])) {
            $query->where('runtime_operations.created_at', '<', Carbon::parse($filters['created_to']));
        }

        if (isset($filters['correlation_id'])) {
            $query->where('runtime_operations.correlation_id', strtolower((string) $filters['correlation_id']));
        }
    }
}
