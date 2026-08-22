<?php

namespace App\TelephonyDomain;

use Illuminate\Support\Facades\DB;

final class CallQueryService
{
    /** @return array{rows:list<object>,total:int,page:int,per_page:int,has_more:bool} */
    public function calls(string $tenantId, array $filters, int $page = 1, int $perPage = 25): array
    {
        $query = DB::table('calls')->where('tenant_id', $tenantId);
        if (isset($filters['state'])) {
            $query->where('observed_state', $filters['state']);
        }
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }
        if (isset($filters['terminal'])) {
            $terminal = ['completed', 'failed', 'cancelled'];
            $filters['terminal'] ? $query->whereIn('observed_state', $terminal) : $query->whereNotIn('observed_state', $terminal);
        }
        if (isset($filters['runtime_node_id'])) {
            $query->where('runtime_node_id', $filters['runtime_node_id']);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get()->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $total];
    }

    public function call(string $tenantId, string $callId): ?object
    {
        return DB::table('calls')->where('tenant_id', $tenantId)->where('id', $callId)->first();
    }

    /** @return array{rows:list<object>,total:int,page:int,per_page:int,has_more:bool} */
    public function legs(string $tenantId, string $callId, int $page = 1, int $perPage = 25): array
    {
        $query = DB::table('call_legs')->where('tenant_id', $tenantId)->where('call_id', $callId);
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at')->orderBy('id')->forPage($page, $perPage)->get()->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $total];
    }

    /** @return array{rows:list<object>,total:int,page:int,per_page:int,has_more:bool} */
    public function operations(string $tenantId, string $callId, int $page = 1, int $perPage = 25): array
    {
        $legIds = DB::table('call_legs')->where('tenant_id', $tenantId)->where('call_id', $callId)->pluck('id');
        $query = DB::table('runtime_operations')->where('tenant_id', $tenantId)->where(function ($q) use ($callId, $legIds): void {
            $q->where(fn ($x) => $x->where('aggregate_type', 'call')->where('aggregate_id', $callId))
                ->orWhere(fn ($x) => $x->where('aggregate_type', 'relationship')->where('aggregate_id', $callId));
            if ($legIds->isNotEmpty()) {
                $q->orWhere(fn ($x) => $x->where('aggregate_type', 'call_leg')->whereIn('aggregate_id', $legIds));
            }
        });
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get()->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $total];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,has_more:bool} */
    public function timeline(string $tenantId, string $callId, int $page = 1, int $perPage = 25): array
    {
        $legIds = DB::table('call_legs')->where('tenant_id', $tenantId)->where('call_id', $callId)->pluck('id')->all();
        $limit = min(1000, max(100, $page * $perPage));
        $events = [];
        $add = static function ($row, string $type, string $source, string $time, int $rank, array $metadata = []) use (&$events): void {
            $events[] = ['id' => $source.':'.$row->id, 'type' => $type, 'source' => $source, 'occurred_at' => $time, 'recorded_at' => $row->created_at ?? $time, 'call_id' => $row->call_id ?? null, 'leg_id' => $row->leg_id ?? null, 'summary' => $row->summary ?? null, 'metadata' => $metadata, '_rank' => $rank, '_id' => (string) $row->id];
        };

        $ops = DB::table('runtime_operations')->where('tenant_id', $tenantId)->where(function ($q) use ($callId, $legIds): void {
            $q->where(fn ($x) => $x->where('aggregate_type', 'call')->where('aggregate_id', $callId))->orWhere(fn ($x) => $x->where('aggregate_type', 'relationship')->where('aggregate_id', $callId));
            if ($legIds !== []) {
                $q->orWhere(fn ($x) => $x->where('aggregate_type', 'call_leg')->whereIn('aggregate_id', $legIds));
            }
        })->orderBy('created_at')->limit($limit)->get();
        foreach ($ops as $op) {
            $add((object) ['id' => $op->id, 'created_at' => $op->created_at, 'call_id' => $callId, 'leg_id' => $op->aggregate_type === 'call_leg' ? $op->aggregate_id : null, 'summary' => $op->operation_type], 'operation.'.$op->status, 'runtime_operation', (string) $op->created_at, 2, ['operation_type' => $op->operation_type, 'status' => $op->status, 'target' => ['type' => $op->aggregate_type, 'id' => $op->aggregate_id]]);
        }
        $observations = DB::table('runtime_observations')->where('tenant_id', $tenantId)->where(function ($q) use ($callId, $legIds): void {
            $q->where(fn ($x) => $x->where('subject_type', 'call')->where('subject_id', $callId));
            if ($legIds !== []) {
                $q->orWhere(fn ($x) => $x->where('subject_type', 'call_leg')->whereIn('subject_id', $legIds));
            }
        })->orderBy('observed_at')->limit($limit)->get();
        foreach ($observations as $observation) {
            $payload = json_decode((string) $observation->payload, true);
            $safe = is_array($payload) ? array_intersect_key($payload, array_flip(['digit', 'duration_ms', 'termination_reason', 'runtime_correlation_id'])) : [];
            $add((object) ['id' => $observation->id, 'created_at' => $observation->received_at, 'call_id' => $observation->subject_type === 'call' ? $callId : null, 'leg_id' => $observation->subject_type === 'call_leg' ? $observation->subject_id : null, 'summary' => $observation->observation_type], $observation->observation_type, 'runtime_observation', (string) $observation->observed_at, 3, $safe);
        }
        $audits = DB::table('control_plane_audit_records')->where('tenant_id', $tenantId)->where(function ($q) use ($callId, $legIds): void {
            $q->where(fn ($x) => $x->where('subject_type', 'call')->where('subject_id', $callId));
            if ($legIds !== []) {
                $q->orWhere(fn ($x) => $x->where('subject_type', 'call_leg')->whereIn('subject_id', $legIds));
            }
        })->orderBy('occurred_at')->limit($limit)->get();
        foreach ($audits as $audit) {
            $add((object) ['id' => $audit->id, 'created_at' => $audit->created_at, 'call_id' => $audit->subject_type === 'call' ? $callId : null, 'leg_id' => $audit->subject_type === 'call_leg' ? $audit->subject_id : null, 'summary' => $audit->action], 'audit.'.$audit->action, 'audit', (string) $audit->occurred_at, 4);
        }

        usort($events, static fn (array $a, array $b): int => [$b['occurred_at'], $b['_rank'], $b['_id']] <=> [$a['occurred_at'], $a['_rank'], $a['_id']]);
        $total = count($events);
        $events = array_slice($events, ($page - 1) * $perPage, $perPage);

        return ['rows' => array_map(static function (array $event): array {
            unset($event['_rank'], $event['_id']);

            return $event;
        }, $events), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $total];
    }
}
