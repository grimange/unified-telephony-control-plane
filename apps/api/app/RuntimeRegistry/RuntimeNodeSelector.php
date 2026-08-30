<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RuntimeNodeSelector
{
    public function __construct(private readonly RuntimeNodeWorkloadService $workload, private readonly RuntimeNodeCapacityEvaluator $capacity, private readonly RuntimeNodeFailureDomainEvaluator $failureDomains) {}

    public function selectForOutboundCall(string $tenantId, ?string $requestedRuntimeNodeId = null): string
    {
        $capability = (string) config('telephony_domain.runtime_capabilities.call_control', 'call.control');
        $candidates = DB::table('runtime_nodes')->where('tenant_id', $tenantId)->where('desired_state', 'active')->where('observed_state', 'ready')
            ->whereExists(function ($query) use ($capability): void { $query->selectRaw('1')->from('runtime_node_capabilities')->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')->where('runtime_node_capabilities.capability_key', $capability); })
            ->when($requestedRuntimeNodeId !== null, fn ($query) => $query->where('id', $requestedRuntimeNodeId))->get()
            ->map(function (object $node) use ($tenantId): object { $node->active_telephony_work = $this->workload->activeTelephonyWorkCount($tenantId, (string) $node->id); $node->k5c_observation = DB::table('runtime_node_k5c_placement_observations')->where('runtime_node_id', (string) $node->id)->where('tenant_id', $tenantId)->first(); return $node; })
            ->filter(fn (object $node): bool => $this->capacity->eligible($node, (int) $node->active_telephony_work) && $this->failureDomains->eligible($node, $node->k5c_observation) && $this->convergedExecution($node))
            ->sort(function (object $left, object $right): int { return [(int) $left->placement_priority, -$this->capacity->availableSlotRank($left, (int) $left->active_telephony_work), (int) $left->active_telephony_work, (string) $left->id] <=> [(int) $right->placement_priority, -$this->capacity->availableSlotRank($right, (int) $right->active_telephony_work), (int) $right->active_telephony_work, (string) $right->id]; });

        foreach ($candidates as $candidate) {
            $node = DB::table('runtime_nodes')->where('id', (string) $candidate->id)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($node !== null && (string) $node->desired_state === 'active' && (string) $node->observed_state === 'ready' && DB::table('runtime_node_capabilities')->where('runtime_node_id', (string) $node->id)->where('capability_key', $capability)->exists() && $this->capacity->eligible($node, $this->workload->activeTelephonyWorkCount($tenantId, (string) $node->id)) && $this->failureDomains->eligible($node, DB::table('runtime_node_k5c_placement_observations')->where('runtime_node_id', (string) $node->id)->where('tenant_id', $tenantId)->first()) && $this->convergedExecution($node)) return (string) $node->id;
        }
        if ($requestedRuntimeNodeId !== null) { $requested = DB::table('runtime_nodes')->where('id', $requestedRuntimeNodeId)->where('tenant_id', $tenantId)->exists(); throw new InvalidArgumentException($requested ? 'Runtime node is not eligible for outbound call execution.' : 'Runtime node is not available for this tenant.'); }
        throw new InvalidArgumentException('No eligible runtime node is available for outbound call execution.');
    }

    private function convergedExecution(object $node): bool
    {
        // Older externally managed RuntimeNodes may have no execution-image
        // contract at all. When one is configured, outbound selection adopts
        // the established ADR-027 convergence requirement.
        if ($node->desired_execution_image === null && $node->observed_execution_image === null) {
            return true;
        }

        return (int) ($node->observed_configuration_version ?? 0) >= (int) ($node->configuration_version ?? 0)
            && RuntimeExecutionContract::isCurrent($node->desired_execution_image, $node->observed_execution_image);
    }
}
