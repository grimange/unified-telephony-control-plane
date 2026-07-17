<?php

namespace App\RuntimeEngine\Commands;

use Illuminate\Support\Facades\DB;
use Throwable;

final class RuntimeConferenceInspectionService
{
    public function __construct(
        private readonly RuntimeAdapterRegistry $adapters,
    ) {}

    public function inspect(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        if ($node === null) {
            return RuntimeConferenceInspectionResult::failed('invalid_request', 'runtime_node_not_found');
        }
        $adapter = $this->adapterForNode($node);
        if ($adapter === null) {
            return RuntimeConferenceInspectionResult::unsupported();
        }

        try {
            return $adapter->inspectConferenceRuntime($tenantId, $runtimeNodeId, $conferenceId, $participantId);
        } catch (Throwable) {
            return RuntimeConferenceInspectionResult::failed('internal_error', 'runtime_conference_inspection_failed');
        }
    }

    public function recordEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        if ($node === null) {
            return false;
        }
        $adapter = $this->adapterForNode($node);
        if ($adapter === null) {
            return false;
        }

        try {
            return $adapter->recordConferenceRuntimeInspectionEvidence($tenantId, $runtimeNodeId, $conferenceId, $participantId);
        } catch (Throwable) {
            return false;
        }
    }

    private function node(string $tenantId, string $runtimeNodeId): ?object
    {
        return DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    private function adapterForNode(object $node): ?RuntimeConferenceInspectionAdapter
    {
        $adapter = $this->adapters->get((string) $node->adapter_key);

        return $adapter instanceof RuntimeConferenceInspectionAdapter ? $adapter : null;
    }
}
