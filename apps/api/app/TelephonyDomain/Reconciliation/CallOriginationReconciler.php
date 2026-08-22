<?php

namespace App\TelephonyDomain\Reconciliation;

use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\TelephonyDomain\CallDomainService;
use App\TelephonyDomain\CallState;
use Illuminate\Support\Facades\DB;

final class CallOriginationReconciler implements Reconciler
{
    public function __construct(private readonly CallDomainService $calls) {}

    public function targetType(): string
    {
        return 'call_leg_origination';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        $leg = DB::table('call_legs')->where('tenant_id', $target->tenant_id)->where('id', $target->target_id)->first();
        if ($leg === null || $leg->direction !== 'outbound') {
            return ReconciliationResult::unsupported('call_leg_not_found');
        }
        $state = CallState::from($leg->observed_state);
        if ($state->terminal() || ! in_array($state, [CallState::Requested, CallState::SelectingRoute, CallState::Originating], true)) {
            return ReconciliationResult::converged(300);
        }

        $operation = DB::table('runtime_operations')
            ->where('tenant_id', $target->tenant_id)
            ->where('aggregate_type', 'call_leg')
            ->where('aggregate_id', $target->target_id)
            ->where('operation_type', 'call.leg.originate')
            ->orderByDesc('created_at')
            ->first();
        if ($operation === null) {
            return ReconciliationResult::waiting('origination_operation_not_visible', 10);
        }
        if (in_array($operation->status, ['pending', 'leased', 'running', 'retry_scheduled'], true)) {
            return ReconciliationResult::waiting('origination_operation_in_progress', 10);
        }
        if ($operation->status !== 'succeeded') {
            $this->calls->terminalizePendingOrigination((string) $target->tenant_id, (string) $target->target_id, 'origination_failed');

            return ReconciliationResult::converged(300);
        }

        $completedAt = $operation->completed_at ?? $operation->updated_at ?? $operation->created_at;
        $deadline = strtotime((string) $completedAt) + (int) config('telephony_domain.origination_timeout_seconds', 60);
        if (time() < $deadline) {
            return ReconciliationResult::waiting('origination_observation_deadline_pending', max(1, min(10, $deadline - time())));
        }

        $this->calls->terminalizePendingOrigination((string) $target->tenant_id, (string) $target->target_id);

        return ReconciliationResult::converged(300);
    }
}
