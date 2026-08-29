<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RuntimeNodeSelector
{
    /**
     * Resolve an eligible tenant runtime for a call execution operation.
     *
     * The ordering is the non-workload portion of the established conference
     * selector: placement priority followed by a stable id tie-break. Calls
     * have no canonical binding/workload table, so conference capacity is not
     * applied here.
     */
    public function selectForOutboundCall(string $tenantId, ?string $requestedRuntimeNodeId = null): string
    {
        $capability = (string) config('telephony_domain.runtime_capabilities.call_control', 'call.control');

        $query = DB::table('runtime_nodes')
            ->where('tenant_id', $tenantId)
            ->where('desired_state', 'active')
            ->where('observed_state', 'ready')
            ->whereExists(function ($capabilityQuery) use ($capability): void {
                $capabilityQuery->selectRaw('1')
                    ->from('runtime_node_capabilities')
                    ->whereColumn('runtime_node_capabilities.runtime_node_id', 'runtime_nodes.id')
                    ->where('runtime_node_capabilities.capability_key', $capability);
            })
            ->when($requestedRuntimeNodeId !== null, fn ($nodeQuery) => $nodeQuery->where('id', $requestedRuntimeNodeId))
            ->orderBy('placement_priority')
            ->orderBy('id');

        foreach ($query->get() as $candidate) {
            $node = DB::table('runtime_nodes')
                ->where('id', (string) $candidate->id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($node !== null
                && (string) $node->desired_state === 'active'
                && (string) $node->observed_state === 'ready'
                && DB::table('runtime_node_capabilities')
                    ->where('runtime_node_id', (string) $node->id)
                    ->where('capability_key', $capability)
                    ->exists()) {
                return (string) $node->id;
            }
        }

        if ($requestedRuntimeNodeId !== null) {
            $requested = DB::table('runtime_nodes')
                ->where('id', $requestedRuntimeNodeId)
                ->where('tenant_id', $tenantId)
                ->exists();

            throw new InvalidArgumentException($requested
                ? 'Runtime node is not eligible for outbound call execution.'
                : 'Runtime node is not available for this tenant.');
        }

        throw new InvalidArgumentException('No eligible runtime node is available for outbound call execution.');
    }
}
