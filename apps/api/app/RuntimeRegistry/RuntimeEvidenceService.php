<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RuntimeEvidenceService
{
    /**
     * @return array<string, mixed>
     */
    public function show(string $tenantId, string $runtimeNodeId): array
    {
        $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        $lease = DB::table('runtime_listener_leases')->where('runtime_node_id', $runtimeNodeId)->orderByDesc('updated_at')->first();
        $epoch = DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $runtimeNodeId)->orderByDesc('opened_at')->first();
        $event = DB::table('runtime_event_receipts')->where('runtime_node_id', $runtimeNodeId)->orderByDesc('occurred_at')->first();
        $reconciliation = DB::table('runtime_reconciliation_states')->where('target_type', 'runtime_node')->where('target_id', $runtimeNodeId)->orderByDesc('updated_at')->first();
        $operation = $reconciliation?->last_operation_id === null ? null : DB::table('runtime_operations')->where('id', $reconciliation->last_operation_id)->first();
        $lastSuccess = DB::table('runtime_operations')->where('runtime_node_id', $runtimeNodeId)->where('operation_type', 'runtime.node.inspect')->where('status', 'succeeded')->max('completed_at');
        $lastFailure = DB::table('runtime_operations')->where('runtime_node_id', $runtimeNodeId)->where('operation_type', 'runtime.node.inspect')->whereIn('status', ['terminal_failed', 'retry_scheduled'])->orderByDesc('updated_at')->first();

        return [
            'desired_state' => $node->desired_state,
            'observed_state' => $node->observed_state,
            'observed_at' => $node->observed_at,
            'desired_configuration_generation' => (int) $node->configuration_version,
            'observed_configuration_generation' => $node->observed_configuration_version === null ? null : (int) $node->observed_configuration_version,
            'listener' => [
                'status' => $lease?->status,
                'lease_freshness' => $this->freshness($lease?->lease_expires_at),
                'last_claimed_at' => $lease?->claimed_at,
                'last_renewed_at' => $lease?->heartbeat_at,
            ],
            'connection' => [
                'state' => $epoch?->status,
                'latest_epoch_opened_at' => $epoch?->opened_at,
                'latest_epoch_closed_at' => $epoch?->closed_at,
                'latest_event_at' => $event?->occurred_at,
                'latest_disconnect_class' => $this->safe((string) ($event?->failure_class ?? '')),
            ],
            'reconciliation' => [
                'state' => $reconciliation?->status,
                'last_evaluated_at' => $reconciliation?->last_checked_at,
                'next_retry_at' => $reconciliation?->next_check_at,
                'sanitized_failure_class' => $this->safe((string) ($operation?->last_failure_class ?? '')),
                'sanitized_failure_code' => $this->safe((string) ($operation?->last_failure_code ?? $reconciliation?->blocked_reason ?? '')),
                'sanitized_message' => $this->message((string) ($operation?->last_failure_class ?? ''), (string) ($operation?->last_failure_code ?? $reconciliation?->blocked_reason ?? '')),
            ],
            'inspection' => [
                'last_success_at' => $lastSuccess,
                'last_failure_at' => $lastFailure?->updated_at,
                'failure_class' => $this->safe((string) ($lastFailure?->last_failure_class ?? '')),
            ],
        ];
    }

    private function freshness(?string $expiresAt): ?string
    {
        if ($expiresAt === null) {
            return null;
        }

        return Carbon::parse($expiresAt)->isFuture() ? 'fresh' : 'expired';
    }

    private function safe(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, 120);
    }

    private function message(string $class, string $code): ?string
    {
        $safeCode = $this->safe($code);
        if ($safeCode === null) {
            return null;
        }

        return $this->safe($class) === null ? $safeCode : $this->safe($class).':'.$safeCode;
    }
}
