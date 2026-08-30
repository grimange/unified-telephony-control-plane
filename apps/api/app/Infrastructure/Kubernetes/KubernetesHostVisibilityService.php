<?php

namespace App\Infrastructure\Kubernetes;

use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityResolver;
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
}
