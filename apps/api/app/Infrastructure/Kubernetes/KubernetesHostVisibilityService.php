<?php

namespace App\Infrastructure\Kubernetes;

use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityResolver;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityException;
use Illuminate\Support\Facades\DB;

final class KubernetesHostVisibilityService
{
    public function __construct(private readonly KubernetesInfrastructureClient $client) {}

    /** @return list<array<string, mixed>> */
    public function hosts(): array
    {
        $nodes = $this->client->listNodes();
        $pods = $this->client->listPods();
        $runtimeNodes = DB::table('runtime_nodes')->orderBy('name')->get();
        $workloadIdentities = app(RuntimeNodeWorkloadIdentityResolver::class);
        $result = [];
        foreach ($nodes as $node) {
            $name = (string) data_get($node, 'metadata.name', '');
            if ($name === '') continue;
            $workloads = [];
            foreach ($pods as $pod) {
                if ((string) data_get($pod, 'spec.nodeName', '') !== $name) continue;
                $labels = data_get($pod, 'metadata.labels', []);
                if (! is_array($labels) || ($labels['app.kubernetes.io/part-of'] ?? null) !== 'utcp') continue;
                $podNamespace = (string) data_get($pod, 'metadata.namespace', '');
                $podDeployment = (string) ($labels['app.kubernetes.io/instance'] ?? '');
                $runtime = $runtimeNodes->first(function ($item) use ($podNamespace, $podDeployment, $workloadIdentities): bool {
                    try {
                        $identity = $workloadIdentities->resolve($item);
                    } catch (\Throwable) {
                        return false;
                    }

                    return $identity->namespace === $podNamespace && $identity->deployment === $podDeployment;
                });
                $workloads[] = ['name' => (string) data_get($pod, 'metadata.name', ''), 'namespace' => (string) data_get($pod, 'metadata.namespace', ''), 'phase' => data_get($pod, 'status.phase'), 'runtime_node_id' => $runtime?->id, 'runtime_node_name' => $runtime?->name];
            }
            usort($workloads, fn ($a, $b) => [$a['namespace'], $a['name']] <=> [$b['namespace'], $b['name']]);
            $runtimeAssociations = collect($workloads)->filter(fn ($item) => $item['runtime_node_id'] !== null)->unique('runtime_node_id')->sortBy('runtime_node_name')->values()->map(fn ($item) => ['id' => $item['runtime_node_id'], 'name' => $item['runtime_node_name']])->all();
            $addresses = collect(data_get($node, 'status.addresses', []))->filter(fn (mixed $address): bool => is_array($address))->map(fn (array $address): array => ['type' => (string) ($address['type'] ?? ''), 'address' => (string) ($address['address'] ?? '')])->filter(fn (array $a): bool => $a['type'] !== '' && $a['address'] !== '')->sortBy(fn (array $a): string => $a['type'].'|'.$a['address'])->values()->all();
            $conditions = collect(data_get($node, 'status.conditions', []))->filter(fn (mixed $condition): bool => is_array($condition))->map(fn (array $condition): array => ['type' => (string) ($condition['type'] ?? ''), 'status' => (string) ($condition['status'] ?? ''), 'reason' => $condition['reason'] ?? null])->filter(fn (array $c): bool => $c['type'] !== '')->sortBy('type')->values()->all();
            $ready = collect($conditions)->firstWhere('type', 'Ready');
            $result[] = ['uid' => (string) data_get($node, 'metadata.uid', ''), 'name' => $name, 'ready' => ($ready['status'] ?? '') === 'True', 'conditions' => $conditions, 'addresses' => $addresses, 'capacity' => data_get($node, 'status.capacity', []), 'allocatable' => data_get($node, 'status.allocatable', []), 'labels' => data_get($node, 'metadata.labels', []), 'taints' => data_get($node, 'spec.taints', []), 'unschedulable' => (bool) data_get($node, 'spec.unschedulable', false), 'workloads' => $workloads, 'runtime_nodes' => $runtimeAssociations];
        }
        usort($result, fn ($a, $b) => [$a['name'], $a['uid']] <=> [$b['name'], $b['uid']]);
        return $result;
    }

    /** @return array<string, mixed> */
    public function placementForRuntimeNode(string $runtimeNodeId, string $tenantId): array
    {
        $runtimeNode = DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->first();
        if ($runtimeNode === null) {
            abort(404, 'RuntimeNode not found.');
        }

        $identities = app(RuntimeNodeWorkloadIdentityResolver::class);
        try {
            $identity = $identities->resolve($runtimeNode);
        } catch (RuntimeNodeWorkloadIdentityException) {
            return $this->unplaced('no_managed_kubernetes_identity');
        }

        $nodes = $this->client->listNodes();
        $pods = $this->client->listPods();
        $nodeByName = [];
        foreach ($nodes as $node) {
            $name = (string) data_get($node, 'metadata.name', '');
            if ($name !== '') {
                $nodeByName[$name] = $node;
            }
        }

        $matchingPods = array_values(array_filter($pods, function (mixed $pod) use ($identity): bool {
            if (! is_array($pod)) return false;
            $labels = data_get($pod, 'metadata.labels', []);
            return is_array($labels)
                && ($labels['app.kubernetes.io/part-of'] ?? null) === 'utcp'
                && (string) data_get($pod, 'metadata.namespace', '') === $identity->namespace
                && (string) ($labels['app.kubernetes.io/instance'] ?? '') === $identity->deployment;
        }));
        $observedNodeNames = array_values(array_unique(array_filter(array_map(
            fn (array $pod): string => (string) data_get($pod, 'spec.nodeName', ''),
            $matchingPods,
        ), fn (string $name): bool => $name !== '' && isset($nodeByName[$name]))));
        sort($observedNodeNames);

        $workload = [
            'namespace' => $identity->namespace,
            'deployment' => $identity->deployment,
            'pods' => $this->serializePlacementPods($matchingPods),
        ];
        if (count($observedNodeNames) === 0) {
            return [
                'status' => 'identity_present_but_not_currently_observed',
                'kubernetes_node' => null,
                'workload' => $workload,
                'co_resident_runtime_nodes' => [],
            ];
        }
        if (count($observedNodeNames) > 1) {
            return [
                'status' => 'ambiguous_multiple_nodes_observed',
                'kubernetes_node' => null,
                'workload' => $workload,
                'co_resident_runtime_nodes' => [],
            ];
        }

        $nodeName = $observedNodeNames[0];
        $host = $this->hostFacts($nodeByName[$nodeName]);
        $coResident = $this->runtimeAssociationsForNode($tenantId, $nodeName, $pods, $identities);
        return [
            'status' => 'placed',
            'kubernetes_node' => $host,
            'workload' => $workload,
            'co_resident_runtime_nodes' => $coResident,
        ];
    }

    /** @return array<string, mixed> */
    private function unplaced(string $status): array
    {
        return ['status' => $status, 'kubernetes_node' => null, 'workload' => null, 'co_resident_runtime_nodes' => []];
    }

    /** @return array<string, mixed> */
    private function hostFacts(array $node): array
    {
        $conditions = collect(data_get($node, 'status.conditions', []))
            ->filter(fn (mixed $condition): bool => is_array($condition))
            ->map(fn (array $condition): array => ['type' => (string) ($condition['type'] ?? ''), 'status' => (string) ($condition['status'] ?? ''), 'reason' => $condition['reason'] ?? null])
            ->filter(fn (array $condition): bool => $condition['type'] !== '')
            ->sortBy('type')->values()->all();
        $addresses = collect(data_get($node, 'status.addresses', []))
            ->filter(fn (mixed $address): bool => is_array($address))
            ->map(fn (array $address): array => ['type' => (string) ($address['type'] ?? ''), 'address' => (string) ($address['address'] ?? '')])
            ->filter(fn (array $address): bool => $address['type'] !== '' && $address['address'] !== '')
            ->sortBy(fn (array $address): string => $address['type'].'|'.$address['address'])->values()->all();
        $ready = collect($conditions)->firstWhere('type', 'Ready');

        return [
            'uid' => (string) data_get($node, 'metadata.uid', ''),
            'name' => (string) data_get($node, 'metadata.name', ''),
            'ready' => ($ready['status'] ?? '') === 'True',
            'conditions' => $conditions,
            'addresses' => $addresses,
            'capacity' => data_get($node, 'status.capacity', []),
            'allocatable' => data_get($node, 'status.allocatable', []),
            'labels' => data_get($node, 'metadata.labels', []),
            'topology' => collect(data_get($node, 'metadata.labels', []))->only(['topology.kubernetes.io/region', 'topology.kubernetes.io/zone', 'kubernetes.io/hostname'])->all(),
            'taints' => data_get($node, 'spec.taints', []),
            'unschedulable' => (bool) data_get($node, 'spec.unschedulable', false),
            'workloads' => [],
            'runtime_nodes' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function serializePlacementPods(array $pods): array
    {
        $result = array_map(fn (array $pod): array => [
            'name' => (string) data_get($pod, 'metadata.name', ''),
            'namespace' => (string) data_get($pod, 'metadata.namespace', ''),
            'node_name' => (string) data_get($pod, 'spec.nodeName', ''),
            'phase' => data_get($pod, 'status.phase'),
        ], $pods);
        usort($result, fn (array $a, array $b): int => [$a['namespace'], $a['name']] <=> [$b['namespace'], $b['name']]);
        return $result;
    }

    /** @return list<array{id: string, name: string}> */
    private function runtimeAssociationsForNode(string $tenantId, string $nodeName, array $pods, RuntimeNodeWorkloadIdentityResolver $identities): array
    {
        $runtimeNodes = DB::table('runtime_nodes')->where('tenant_id', $tenantId)->orderBy('name')->get();
        $matches = [];
        foreach ($pods as $pod) {
            if ((string) data_get($pod, 'spec.nodeName', '') !== $nodeName) continue;
            $labels = data_get($pod, 'metadata.labels', []);
            if (! is_array($labels) || ($labels['app.kubernetes.io/part-of'] ?? null) !== 'utcp') continue;
            $namespace = (string) data_get($pod, 'metadata.namespace', '');
            $deployment = (string) ($labels['app.kubernetes.io/instance'] ?? '');
            foreach ($runtimeNodes as $runtime) {
                try { $identity = $identities->resolve($runtime); } catch (RuntimeNodeWorkloadIdentityException) { continue; }
                if ($identity->namespace === $namespace && $identity->deployment === $deployment) {
                    $matches[$runtime->id] = ['id' => $runtime->id, 'name' => $runtime->name];
                }
            }
        }
        $result = array_values($matches);
        usort($result, fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
        return $result;
    }
}
