<?php

namespace App\RuntimeEngine\Reconciliation;

use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeRegistry\RuntimeNodeDrainRepository;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;

final class RuntimeNodeDrainCoordinator implements Reconciler
{
    public function __construct(
        private readonly RuntimeRegistryService $registry,
        private readonly RuntimeNodeDrainRepository $drains,
    ) {}

    public function targetType(): string
    {
        return 'runtime_node_drain';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $node = DB::table('runtime_nodes')
            ->where('id', (string) $target->target_id)
            ->where('tenant_id', (string) $target->tenant_id)
            ->first();

        if ($node === null) {
            return ReconciliationResult::blocked('runtime_node_missing');
        }

        if ((string) $node->desired_state !== 'draining') {
            return ReconciliationResult::converged();
        }

        $remaining = (int) DB::table('conference_runtime_bindings')
            ->where('tenant_id', (string) $target->tenant_id)
            ->where('runtime_node_id', (string) $target->target_id)
            ->where('status', 'active')
            ->count();
        $drain = $this->drains->progress((string) $target->tenant_id, (string) $target->target_id);
        if ($drain === null) {
            $this->drains->start((string) $target->tenant_id, (string) $target->target_id, $remaining);
            $drain = $this->drains->progress((string) $target->tenant_id, (string) $target->target_id);
        }

        $now = now();
        $timedOut = $drain?->deadline_at !== null && $now->greaterThan($drain->deadline_at) && $remaining > 0;
        DB::table('runtime_node_drains')
            ->where('tenant_id', (string) $target->tenant_id)
            ->where('runtime_node_id', (string) $target->target_id)
            ->update([
                'remaining_work' => $remaining,
                'last_evaluated_at' => $now,
                'status' => $timedOut ? 'timed_out' : ((string) ($drain?->status ?? 'running')),
                'timed_out_at' => $timedOut ? ($drain?->timed_out_at ?? $now) : ($drain?->timed_out_at ?? null),
                'failure_class' => $timedOut ? 'timeout' : ($drain?->failure_class ?? null),
                'failure_code' => $timedOut ? 'drain_deadline_exceeded' : ($drain?->failure_code ?? null),
                'updated_at' => $now,
            ]);

        if ($remaining === 0) {
            $completed = $this->registry->completeDrain(
                ExecutionContext::system(
                    reason: 'runtime drain completed',
                    tenantId: (string) $target->tenant_id,
                    origin: 'telephony-reconciler',
                ),
                (string) $target->tenant_id,
                (string) $target->target_id,
            );

            return $completed
                ? ReconciliationResult::converged()
                : ReconciliationResult::waiting('drain_completion_recheck', (int) config('runtime_engine.drain_poll_seconds', 15));
        }

        return ReconciliationResult::waiting(
            $timedOut ? 'drain_timed_out' : 'drain_work_remaining',
            (int) config('runtime_engine.drain_poll_seconds', 15),
        );
    }
}
