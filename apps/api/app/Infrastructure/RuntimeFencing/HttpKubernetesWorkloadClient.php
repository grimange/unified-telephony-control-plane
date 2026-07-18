<?php

namespace App\Infrastructure\RuntimeFencing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class HttpKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    private const DNS_LABEL_PATTERN = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';

    /**
     * @return array<string, mixed>|null
     */
    public function getDeployment(string $namespace, string $name): ?array
    {
        $this->assertDnsLabel($namespace, 'namespace');
        $this->assertDnsLabel($name, 'deployment');

        $response = $this->send('get', sprintf(
            '/apis/apps/v1/namespaces/%s/deployments/%s',
            rawurlencode($namespace),
            rawurlencode($name),
        ));

        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccessful($response);

        return $this->decodeObject($response, 'Deployment');
    }

    public function scaleDeployment(string $namespace, string $name, int $replicas): void
    {
        $this->assertDnsLabel($namespace, 'namespace');
        $this->assertDnsLabel($name, 'deployment');
        if ($replicas < 0) {
            throw KubernetesWorkloadClientException::failed('Kubernetes scale replicas must be non-negative.');
        }

        $response = $this->send('patch', sprintf(
            '/apis/apps/v1/namespaces/%s/deployments/%s/scale',
            rawurlencode($namespace),
            rawurlencode($name),
        ), [
            'spec' => ['replicas' => $replicas],
        ], [
            'Content-Type' => 'application/merge-patch+json',
        ]);
        $this->assertSuccessful($response);
        $this->decodeObject($response, 'Scale');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        $this->assertDnsLabel($namespace, 'namespace');
        $this->assertDnsLabel($identity->deployment, 'deployment');

        $selector = http_build_query([
            'labelSelector' => implode(',', [
                'app.kubernetes.io/part-of=utcp',
                'app.kubernetes.io/component=asterisk-ari',
            ]),
        ]);
        $response = $this->send('get', sprintf(
            '/api/v1/namespaces/%s/pods?%s',
            rawurlencode($namespace),
            $selector,
        ));
        $this->assertSuccessful($response);

        $payload = $this->decodeObject($response, 'PodList');
        $items = $payload['items'] ?? null;
        if (! is_array($items)) {
            throw KubernetesWorkloadClientException::failed();
        }

        return array_values(array_filter(
            $items,
            fn ($pod): bool => is_array($pod) && $this->isOwnedByDeployment($pod, $identity),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): Response
    {
        try {
            $request = $this->request();
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            return $body === null
                ? $request->{$method}($this->apiServerUrl().$path)
                : $request->{$method}($this->apiServerUrl().$path, $body);
        } catch (KubernetesWorkloadClientException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw KubernetesWorkloadClientException::unavailable();
        } catch (\Throwable) {
            throw KubernetesWorkloadClientException::unavailable();
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->readToken())
            ->connectTimeout($this->positiveTimeout('connect_timeout_seconds', 2))
            ->timeout($this->positiveTimeout('request_timeout_seconds', 5))
            ->withOptions(['verify' => $this->caPath()]);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        match ($response->status()) {
            401, 403 => throw KubernetesWorkloadClientException::forbidden(),
            404 => throw KubernetesWorkloadClientException::targetMismatch(),
            409 => throw KubernetesWorkloadClientException::conflict(),
            429 => throw KubernetesWorkloadClientException::unavailable(),
            default => $response->serverError()
                ? throw KubernetesWorkloadClientException::unavailable()
                : throw KubernetesWorkloadClientException::failed(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(Response $response, string $expectedKind): array
    {
        $payload = $response->json();
        if (! is_array($payload) || (string) ($payload['kind'] ?? '') !== $expectedKind) {
            throw KubernetesWorkloadClientException::failed();
        }

        return $payload;
    }

    private function isOwnedByDeployment(array $pod, RuntimeNodeWorkloadIdentity $identity): bool
    {
        $ownerReferences = data_get($pod, 'metadata.ownerReferences', []);
        if (! is_array($ownerReferences)) {
            return false;
        }

        foreach ($ownerReferences as $ownerReference) {
            if (! is_array($ownerReference)) {
                continue;
            }
            if ((string) ($ownerReference['kind'] ?? '') === 'ReplicaSet'
                && str_starts_with((string) ($ownerReference['name'] ?? ''), $identity->deployment.'-')) {
                return true;
            }
        }

        return false;
    }

    private function apiServerUrl(): string
    {
        $host = trim((string) config('runtime_engine.kubernetes.service_host', ''));
        $port = trim((string) config('runtime_engine.kubernetes.service_port', ''));
        if ($host === '' || $port === '' || filter_var($port, FILTER_VALIDATE_INT) === false) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }

        return 'https://'.$host.':'.(int) $port;
    }

    private function readToken(): string
    {
        $path = (string) config('runtime_engine.kubernetes.token_path', '');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }
        $token = trim((string) file_get_contents($path));
        if ($token === '') {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }

        return $token;
    }

    private function caPath(): string
    {
        $path = (string) config('runtime_engine.kubernetes.ca_path', '');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw KubernetesWorkloadClientException::unavailable('Kubernetes API connection material is unavailable.');
        }

        return $path;
    }

    private function positiveTimeout(string $key, int $default): int
    {
        $value = filter_var(config('runtime_engine.kubernetes.'.$key, $default), FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 && $value <= 30 ? $value : $default;
    }

    private function assertDnsLabel(string $value, string $field): void
    {
        if (! preg_match(self::DNS_LABEL_PATTERN, $value)) {
            throw KubernetesWorkloadClientException::targetMismatch('Kubernetes '.$field.' did not match the trusted workload identity.');
        }
    }
}
