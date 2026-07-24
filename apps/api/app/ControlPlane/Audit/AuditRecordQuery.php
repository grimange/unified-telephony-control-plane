<?php

namespace App\ControlPlane\Audit;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRecordQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(string $tenantId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->baseQuery($tenantId, $filters)
            ->orderByDesc('control_plane_audit_records.occurred_at')
            ->orderByDesc('control_plane_audit_records.id')
            ->paginate(
                perPage: $perPage,
                columns: ['control_plane_audit_records.*'],
                pageName: 'page',
                page: $page,
            );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countForTenant(string $tenantId, array $filters = []): int
    {
        return (int) $this->baseQuery($tenantId, $filters)->count();
    }

    public function find(string $tenantId, string $auditRecordId): ?object
    {
        return DB::table('control_plane_audit_records')
            ->where('tenant_id', $tenantId)
            ->where('id', $auditRecordId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(string $tenantId, array $filters): Builder
    {
        $query = DB::table('control_plane_audit_records')
            ->where('tenant_id', $tenantId);

        $equals = [
            'actor_id' => 'actor_id',
            'actor_type' => 'actor_type',
            'action' => 'action',
            'subject_type' => 'subject_type',
            'subject_id' => 'subject_id',
            'correlation_id' => 'correlation_id',
            'request_id' => 'request_id',
        ];

        foreach ($equals as $filter => $column) {
            if (($filters[$filter] ?? null) !== null && $filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }

        if (($filters['occurred_from'] ?? null) !== null) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['occurred_from']));
        }

        if (($filters['occurred_to'] ?? null) !== null) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['occurred_to']));
        }

        return $query;
    }
}
