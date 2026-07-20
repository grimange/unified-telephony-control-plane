<?php

namespace App\RuntimeEngine\Commands;

use App\RuntimeEngine\EngineIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $result = RuntimeConferenceInspectionResult::failed('invalid_request', 'runtime_node_not_found');
            $this->recordInspectionMetric('unknown', $participantId === null ? 'conference' : 'conference_participant', $result);

            return $result;
        }
        $adapter = $this->adapterForNode($node);
        if ($adapter === null) {
            $result = RuntimeConferenceInspectionResult::unsupported();
            $this->recordInspectionMetric((string) $node->adapter_key, $participantId === null ? 'conference' : 'conference_participant', $result);

            return $result;
        }

        try {
            $result = $adapter->inspectConferenceRuntime($tenantId, $runtimeNodeId, $conferenceId, $participantId);
            $this->recordInspectionMetric((string) $node->adapter_key, $participantId === null ? 'conference' : 'conference_participant', $result);

            return $result;
        } catch (Throwable) {
            $result = RuntimeConferenceInspectionResult::failed('internal_error', 'runtime_conference_inspection_failed');
            $this->recordInspectionMetric((string) $node->adapter_key, $participantId === null ? 'conference' : 'conference_participant', $result);

            return $result;
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

    private function recordInspectionMetric(string $adapterKey, string $resourceType, RuntimeConferenceInspectionResult $result): void
    {
        try {
            if (! Schema::hasTable('conference_recovery_metric_events')) {
                return;
            }

            DB::table('conference_recovery_metric_events')->insert([
                'id' => EngineIds::new(),
                'adapter_key' => $this->boundedMetricValue($adapterKey, 80),
                'resource_type' => in_array($resourceType, ['conference', 'conference_participant'], true) ? $resourceType : 'conference',
                'result' => in_array($result->status, ['observed', 'unavailable', 'unsupported', 'failed'], true) ? $result->status : 'failed',
                'failure_class' => $this->boundedMetricValue($result->failureClass ?? 'none', 80),
                'reason' => $this->boundedMetricValue($result->runtimeReferenceHealth ?? $result->failureCode ?? 'none', 120),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Recovery telemetry is diagnostic evidence; it must not affect reconciliation authority.
        }
    }

    private function boundedMetricValue(string $value, int $max): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?? 'unknown';
        $safe = trim($safe);

        return substr($safe === '' ? 'none' : $safe, 0, $max);
    }
}
