<?php

namespace App\Infrastructure\Kubernetes;

use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use Illuminate\Support\Facades\DB;

final class K5CPlacementObservationService
{
    public function __construct(private readonly KubernetesHostVisibilityService $visibility) {}

    /** @return array{observed: int, unavailable: int} */
    public function refresh(): array
    {
        $nodes = DB::table('runtime_nodes')->select('id', 'tenant_id')->get();
        $now = now();
        $unavailable = false;
        $placements = [];
        try {
            foreach ($nodes as $node) {
                $placements[] = [(string) $node->id, (string) $node->tenant_id, $this->visibility->placementForRuntimeNode((string) $node->id, (string) $node->tenant_id)];
            }
        } catch (KubernetesWorkloadClientException) {
            $unavailable = true;
        }

        foreach ($nodes as $node) {
            $placement = $unavailable ? ['status' => 'kubernetes_observation_unavailable'] : collect($placements)->first(fn (array $item): bool => $item[0] === (string) $node->id)[2];
            $facts = $this->facts($placement);
            DB::table('runtime_node_k5c_placement_observations')->upsert([[
                'runtime_node_id' => (string) $node->id,
                'tenant_id' => (string) $node->tenant_id,
                'status' => (string) $placement['status'],
                'observed_kubernetes_node_uid' => $facts['uid'],
                'observed_kubernetes_node_name' => $facts['name'],
                'observed_region' => $facts['region'],
                'observed_zone' => $facts['zone'],
                'observed_hostname' => $facts['hostname'],
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['runtime_node_id'], ['tenant_id', 'status', 'observed_kubernetes_node_uid', 'observed_kubernetes_node_name', 'observed_region', 'observed_zone', 'observed_hostname', 'observed_at', 'updated_at']);
        }

        return ['observed' => $unavailable ? 0 : count($nodes), 'unavailable' => $unavailable ? count($nodes) : 0];
    }

    /** @return array{uid:?string, name:?string, region:?string, zone:?string, hostname:?string} */
    private function facts(array $placement): array
    {
        $nodes = $placement['status'] === 'placed' ? [$placement['kubernetes_node'] ?? []] : ($placement['observed_nodes'] ?? []);
        $values = [];
        foreach (['uid' => 'uid', 'name' => 'name', 'region' => 'topology.kubernetes.io/region', 'zone' => 'topology.kubernetes.io/zone', 'hostname' => 'kubernetes.io/hostname'] as $key => $source) {
            $items = array_values(array_unique(array_filter(array_map(fn (array $node): string => (string) ($key === 'uid' || $key === 'name' ? ($node[$key] ?? '') : ($node['topology'][$source] ?? '')), $nodes))));
            $values[$key] = count($items) === 1 ? $items[0] : null;
        }
        return $values;
    }
}
