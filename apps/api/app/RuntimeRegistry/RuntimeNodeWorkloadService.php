<?php

namespace App\RuntimeRegistry;

use App\TelephonyDomain\CallState;
use Illuminate\Support\Facades\DB;

final class RuntimeNodeWorkloadService
{
    public function activeConferenceBindingCount(string $tenantId, string $runtimeNodeId, ?string $excludeConferenceId = null): int
    {
        return (int) DB::table('conference_runtime_bindings')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('status', 'active')
            ->when($excludeConferenceId !== null, fn ($query) => $query->where('conference_id', '!=', $excludeConferenceId))
            ->count();
    }

    public function activeCallLegCount(string $tenantId, string $runtimeNodeId): int
    {
        return (int) DB::table('call_legs')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->whereNotIn('observed_state', array_map(
                static fn (CallState $state): string => $state->value,
                array_filter(CallState::cases(), static fn (CallState $state): bool => $state->terminal()),
            ))
            ->count();
    }

    public function activeTelephonyWorkCount(string $tenantId, string $runtimeNodeId, ?string $excludeConferenceId = null): int
    {
        return $this->activeConferenceBindingCount($tenantId, $runtimeNodeId, $excludeConferenceId)
            + $this->activeCallLegCount($tenantId, $runtimeNodeId);
    }
}
