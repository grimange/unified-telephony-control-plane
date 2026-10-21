<?php

declare(strict_types=1);

namespace Utcp\GnG1Proof;

use App\Infrastructure\Kubernetes\KubernetesInfrastructureClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use DateTimeImmutable;

/** Test-only bridge. Never registered in the production container or routing. */
final class SnapshotInfrastructureClient implements KubernetesInfrastructureClient
{
    public int $reads = 0;

    public function __construct(private readonly array $snapshot, private readonly DateTimeImmutable $receivedAt) {}

    public function listNodes(): array
    {
        $this->validate();
        $this->reads++;

        return array_map(static fn (array $node): array => [
            'metadata' => ['name' => $node['name'], 'uid' => $node['uid'], 'resourceVersion' => $node['resource_version'], 'labels' => $node['labels']],
            'spec' => ['unschedulable' => $node['unschedulable'], 'taints' => $node['taints']],
            'status' => ['conditions' => $node['conditions'], 'addresses' => $node['addresses'], 'capacity' => $node['capacity'], 'allocatable' => $node['allocatable']],
        ], $this->snapshot['nodes']);
    }

    public function listPods(): array
    {
        $this->validate();
        $this->reads++;

        return array_map(static fn (array $pod): array => [
            'metadata' => ['name' => $pod['name'], 'namespace' => $pod['namespace'], 'uid' => $pod['uid'], 'resourceVersion' => $pod['resource_version'], 'labels' => $pod['labels']],
            'spec' => ['nodeName' => $pod['node_name']],
            'status' => ['phase' => $pod['phase'], 'conditions' => [['type' => 'Ready', 'status' => $pod['ready']]]],
        ], $this->snapshot['pods']);
    }

    private function validate(): void
    {
        $s = $this->snapshot;
        $keys = ['contract_version', 'request_id', 'cluster_id', 'complete', 'started_at', 'finished_at', 'nodes_status', 'pods_status', 'nodes', 'pods', 'error'];
        if (array_diff(array_keys($s), $keys) !== [] || array_diff($keys, array_keys($s)) !== []
            || $s['contract_version'] !== 'utcp.infrastructure.v1' || $s['cluster_id'] !== 'cluster-fixture'
            || ! is_bool($s['complete'])) {
            throw KubernetesWorkloadClientException::failed('Unsupported or malformed observation envelope.');
        }
        if (! $s['complete']) {
            if ($s['nodes'] !== null || $s['pods'] !== null || ! is_array($s['error'])) {
                throw KubernetesWorkloadClientException::failed('Incomplete observation exposed partial facts.');
            }
            // Compatibility projection follows the immutable GN-G0 errors.json.
            // The normalized code remains in the caller-owned snapshot for audit.
            throw match ($s['error']['code'] ?? null) {
                'invalid_request' => KubernetesWorkloadClientException::invalidRequest(),
                'unauthorized', 'forbidden' => KubernetesWorkloadClientException::forbidden(),
                'target_not_found', 'identity_mismatch' => KubernetesWorkloadClientException::targetMismatch(),
                'ownership_conflict' => KubernetesWorkloadClientException::ownershipConflict(),
                'resource_version_conflict', 'stale_generation', 'drift' => KubernetesWorkloadClientException::conflict(),
                'timeout', 'cancelled', 'transport_unavailable', 'retryable_failure', 'outcome_unknown', 'busy' => KubernetesWorkloadClientException::unavailable(),
                'terminal_failure' => KubernetesWorkloadClientException::failed(),
                default => KubernetesWorkloadClientException::failed('Unknown infrastructure observation error.'),
            };
        }
        if ($s['error'] !== null || ! is_array($s['nodes']) || ! is_array($s['pods'])
            || ($s['nodes_status']['complete'] ?? null) !== true || ($s['pods_status']['complete'] ?? null) !== true) {
            throw KubernetesWorkloadClientException::failed('Invalid complete observation.');
        }
        foreach ([$s, $s['nodes_status'], $s['pods_status']] as $interval) {
            try {
                $started = new DateTimeImmutable($interval['started_at']);
                $finished = new DateTimeImmutable($interval['finished_at']);
            } catch (\Throwable) {
                throw KubernetesWorkloadClientException::failed('Invalid collection interval.');
            }
            if ($finished < $started || $started->getTimestamp() < $this->receivedAt->getTimestamp() - 300
                || $finished->getTimestamp() > $this->receivedAt->getTimestamp() + 5) {
                throw KubernetesWorkloadClientException::unavailable('Infrastructure observation is stale.');
            }
        }
        // Full schema validation is executed by the Go exporter before this bridge
        // receives snapshots. This bridge implements the PHP freshness/error seam.
    }
}
