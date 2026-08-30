<?php

namespace App\Infrastructure\Kubernetes;

use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpKubernetesInfrastructureClient implements KubernetesInfrastructureClient
{
    public function listNodes(): array
    {
        return $this->list('/api/v1/nodes', 'NodeList');
    }

    public function listPods(): array
    {
        return $this->list('/api/v1/pods', 'PodList');
    }

    private function list(string $path, string $kind): array
    {
        try {
            $response = $this->request()->get($this->url().$path);
        } catch (\Throwable) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes infrastructure observation is unavailable.');
        }
        if (! $response->successful()) {
            if (in_array($response->status(), [401, 403], true)) {
                throw KubernetesWorkloadClientException::forbidden('Kubernetes infrastructure observation was denied.');
            }

            throw KubernetesWorkloadClientException::unavailable('Kubernetes infrastructure observation failed.');
        }
        $payload = $response->json();
        if (! is_array($payload) || ($payload['kind'] ?? null) !== $kind || ! is_array($payload['items'] ?? null)) {
            throw KubernetesWorkloadClientException::failed('Kubernetes infrastructure observation was malformed.');
        }
        return array_values(array_filter($payload['items'], 'is_array'));
    }

    private function request(): PendingRequest
    {
        $tokenPath = (string) config('runtime_engine.kubernetes.token_path', '');
        $caPath = (string) config('runtime_engine.kubernetes.ca_path', '');
        if ($tokenPath === '' || ! is_readable($tokenPath) || $caPath === '' || ! is_readable($caPath)) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }
        $token = trim((string) file_get_contents($tokenPath));
        if ($token === '') {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }

        return Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(2)
            ->timeout(5)
            ->withOptions(['verify' => $caPath]);
    }

    private function url(): string
    {
        $host = trim((string) config('runtime_engine.kubernetes.service_host', ''));
        $port = (int) config('runtime_engine.kubernetes.service_port', 443);
        if ($host === '' || $port < 1) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }
        return "https://{$host}:{$port}";
    }
}
