<?php

namespace App\Infrastructure\Kubernetes;

use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpKubernetesMaintenanceClient implements KubernetesMaintenanceClient
{
    public function node(string $name): array
    {
        try {
            $response = $this->request()->get($this->url().'/api/v1/nodes/'.rawurlencode($name));
        } catch (\Throwable $exception) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes Node observation is unavailable.');
        }
        $this->assertResponse($response, 'Node observation');
        $node = $response->json();
        if (! is_array($node)) {
            throw KubernetesWorkloadClientException::failed('Kubernetes Node observation was malformed.');
        }

        return $node;
    }

    public function cordon(string $name): void
    {
        $response = $this->request()->withHeaders(['Content-Type' => 'application/merge-patch+json'])
            ->patch($this->url().'/api/v1/nodes/'.rawurlencode($name), ['spec' => ['unschedulable' => true]]);
        $this->assertResponse($response, 'cordon');
    }

    public function drainablePods(string $nodeName): array
    {
        $response = $this->request()->get($this->url().'/api/v1/pods', ['fieldSelector' => 'spec.nodeName='.$nodeName]);
        $this->assertResponse($response, 'pod observation');
        $items = $response->json('items', []);
        if (! is_array($items)) {
            throw KubernetesWorkloadClientException::failed('Kubernetes Pod observation was malformed.');
        }
        $pods = [];
        foreach ($items as $pod) {
            if (! is_array($pod) || ($pod['metadata']['labels']['app.kubernetes.io/part-of'] ?? null) !== 'utcp') continue;
            $owners = $pod['metadata']['ownerReferences'] ?? [];
            $annotations = $pod['metadata']['annotations'] ?? [];
            if (is_array($owners) && collect($owners)->contains(fn ($owner) => is_array($owner) && ($owner['kind'] ?? null) === 'DaemonSet')) continue;
            if (is_array($annotations) && isset($annotations['kubernetes.io/config.mirror'])) continue;
            if (($pod['metadata']['deletionTimestamp'] ?? null) !== null || in_array($pod['status']['phase'] ?? null, ['Succeeded', 'Failed'], true)) continue;
            $namespace = (string) ($pod['metadata']['namespace'] ?? '');
            $name = (string) ($pod['metadata']['name'] ?? '');
            if ($namespace !== '' && $name !== '') $pods[] = ['namespace' => $namespace, 'name' => $name];
        }
        usort($pods, fn (array $a, array $b): int => [$a['namespace'], $a['name']] <=> [$b['namespace'], $b['name']]);
        return $pods;
    }

    public function evict(string $namespace, string $name): void
    {
        $response = $this->request()->post($this->url().'/api/v1/namespaces/'.rawurlencode($namespace).'/pods/'.rawurlencode($name).'/eviction', [
            'apiVersion' => 'policy/v1', 'kind' => 'Eviction', 'metadata' => ['name' => $name, 'namespace' => $namespace],
        ]);
        if ($response->status() === 404) return;
        $this->assertResponse($response, 'pod eviction');
    }

    private function request(): PendingRequest
    {
        $tokenPath = (string) config('runtime_engine.kubernetes.token_path', '');
        $caPath = (string) config('runtime_engine.kubernetes.ca_path', '');
        if ($tokenPath === '' || ! is_readable($tokenPath) || $caPath === '' || ! is_readable($caPath)) throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        $token = trim((string) file_get_contents($tokenPath));
        if ($token === '') throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        return Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(5)->withOptions(['verify' => $caPath]);
    }

    private function url(): string
    {
        $host = trim((string) config('runtime_engine.kubernetes.service_host', ''));
        $port = (int) config('runtime_engine.kubernetes.service_port', 443);
        if ($host === '' || $port < 1) throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        return "https://{$host}:{$port}";
    }

    private function assertResponse($response, string $operation): void
    {
        if ($response->successful()) return;
        if (in_array($response->status(), [401, 403], true)) throw KubernetesWorkloadClientException::forbidden("Kubernetes {$operation} was denied.");
        if (in_array($response->status(), [409, 429], true)) throw KubernetesWorkloadClientException::conflict("Kubernetes {$operation} is currently blocked.");
        throw KubernetesWorkloadClientException::unavailable("Kubernetes {$operation} failed.");
    }
}
