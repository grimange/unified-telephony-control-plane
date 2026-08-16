<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Facades\DB;

final class RuntimeNodeDrainRepository
{
    public function start(string $tenantId, string $runtimeNodeId, int $remainingWork): void
    {
        $now = now();
        $deadline = $now->copy()->addSeconds((int) config('runtime_engine.drain_timeout_seconds', 3600));
        $existing = DB::table('runtime_node_drains')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && in_array((string) $existing->status, ['running', 'timed_out'], true)) {
            DB::table('runtime_node_drains')->where('id', $existing->id)->update([
                'remaining_work' => $remainingWork,
                'last_evaluated_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $values = [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'status' => 'running',
            'initial_work' => $remainingWork,
            'remaining_work' => $remainingWork,
            'started_at' => $now,
            'last_evaluated_at' => $now,
            'deadline_at' => $deadline,
            'timed_out_at' => null,
            'completed_at' => null,
            'failure_class' => null,
            'failure_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            DB::table('runtime_node_drains')->insert(['id' => RuntimeRegistryIds::new(), ...$values]);
        } else {
            DB::table('runtime_node_drains')->where('id', $existing->id)->update($values);
        }
    }

    public function cancel(string $tenantId, string $runtimeNodeId): void
    {
        DB::table('runtime_node_drains')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->whereIn('status', ['running', 'timed_out'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public function progress(string $tenantId, string $runtimeNodeId): ?object
    {
        return DB::table('runtime_node_drains')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->first();
    }
}
