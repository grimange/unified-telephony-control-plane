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

    public function inspectResource(string $kind, string $name): ?array
    {
        if (! in_array($kind, ['Deployment', 'Service', 'Secret'], true)) {
            throw KubernetesWorkloadClientException::invalidRequest('Unsupported Kubernetes resource inspection kind.');
        }

        $resource = $this->getManagedResource($kind, $name);
        if ($resource === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'metadata' => [
                'name' => data_get($resource, 'metadata.name'),
                'namespace' => data_get($resource, 'metadata.namespace'),
                'labels' => data_get($resource, 'metadata.labels', []),
            ],
        ];
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

        $deployment = $this->getDeployment($namespace, $identity->deployment);
        if ($deployment === null) {
            throw KubernetesWorkloadClientException::targetMismatch();
        }
        $deploymentUid = $this->objectUid($deployment);
        if ($deploymentUid === '') {
            throw KubernetesWorkloadClientException::failed('Kubernetes target Deployment UID is unavailable.');
        }

        $replicaSetsByUid = $this->replicaSetsByUid($namespace);

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
            fn ($pod): bool => is_array($pod) && $this->isOwnedByDeploymentUid($pod, $deploymentUid, $replicaSetsByUid),
        ));
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applySecret(array $desired, string $runtimeNodeSlug): array
    {
        return $this->applyManagedResource('Secret', $desired, $runtimeNodeSlug);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applyDeployment(array $desired, string $runtimeNodeSlug): array
    {
        return $this->applyManagedResource('Deployment', $desired, $runtimeNodeSlug);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applyService(array $desired, string $runtimeNodeSlug): array
    {
        return $this->applyManagedResource('Service', $desired, $runtimeNodeSlug);
    }

    public function deleteSecret(string $name, string $runtimeNodeSlug): bool
    {
        return $this->deleteManagedResource('Secret', $name, $runtimeNodeSlug);
    }

    public function deleteDeployment(string $name, string $runtimeNodeSlug): bool
    {
        return $this->deleteManagedResource('Deployment', $name, $runtimeNodeSlug);
    }

    public function deleteService(string $name, string $runtimeNodeSlug): bool
    {
        return $this->deleteManagedResource('Service', $name, $runtimeNodeSlug);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function applyManagedResource(string $expectedKind, array $desired, string $runtimeNodeSlug): array
    {
        [$name, $metadata] = $this->resourceIdentity($expectedKind, $desired);
        $ownership = $this->ownershipLabels($runtimeNodeSlug);
        $labels = $metadata['labels'] ?? [];
        if (! is_array($labels)) {
            throw KubernetesWorkloadClientException::invalidRequest('Kubernetes resource labels must be an object.');
        }
        foreach ($ownership as $key => $value) {
            if (isset($labels[$key]) && (string) $labels[$key] !== $value) {
                throw KubernetesWorkloadClientException::ownershipConflict('Desired Kubernetes resource ownership does not match the RuntimeNode.');
            }
            $labels[$key] = $value;
        }
        $desired['metadata']['labels'] = $labels;

        $existing = $this->getManagedResource($expectedKind, $name);
        if ($existing === null) {
            $response = $this->send('post', $this->collectionPath($expectedKind), $this->createBody($desired, $expectedKind), [
                'Content-Type' => 'application/json',
            ]);
            $this->assertSuccessful($response);

            return $this->publicResource($this->decodeObject($response, $expectedKind), $expectedKind);
        }

        $this->assertOwned($existing, $runtimeNodeSlug);
        $response = $this->send('patch', $this->resourcePath($expectedKind, $name), $this->patchBody($desired, $expectedKind), [
            'Content-Type' => 'application/merge-patch+json',
        ]);
        $this->assertSuccessful($response);

        return $this->publicResource($this->decodeObject($response, $expectedKind), $expectedKind);
    }

    private function deleteManagedResource(string $expectedKind, string $name, string $runtimeNodeSlug): bool
    {
        $this->assertDnsLabel($name, strtolower($expectedKind));
        $existing = $this->getManagedResource($expectedKind, $name);
        if ($existing === null) {
            return false;
        }
        $this->assertOwned($existing, $runtimeNodeSlug);

        $response = $this->send('delete', $this->resourcePath($expectedKind, $name));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccessful($response);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getManagedResource(string $expectedKind, string $name): ?array
    {
        $this->assertDnsLabel($name, strtolower($expectedKind));
        $response = $this->send('get', $this->resourcePath($expectedKind, $name));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccessful($response);

        return $this->decodeObject($response, $expectedKind);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resourceIdentity(string $expectedKind, array $desired): array
    {
        if (($desired['apiVersion'] ?? null) === null || (string) ($desired['kind'] ?? '') !== $expectedKind) {
            throw KubernetesWorkloadClientException::invalidRequest('Kubernetes resource kind or apiVersion is invalid.');
        }
        $metadata = $desired['metadata'] ?? null;
        if (! is_array($metadata)) {
            throw KubernetesWorkloadClientException::invalidRequest('Kubernetes resource metadata is invalid.');
        }
        $namespace = (string) ($metadata['namespace'] ?? '');
        if ($namespace !== (string) config('runtime_engine.kubernetes.runtime_namespace', 'utcp-runtime')) {
            throw KubernetesWorkloadClientException::targetMismatch('Kubernetes resource namespace is outside the managed runtime boundary.');
        }
        $name = (string) ($metadata['name'] ?? '');
        $this->assertDnsLabel($name, strtolower($expectedKind));

        return [$name, $metadata];
    }

    /**
     * @return array<string, string>
     */
    private function ownershipLabels(string $runtimeNodeSlug): array
    {
        $this->assertDnsLabel($runtimeNodeSlug, 'RuntimeNode');

        return [
            'app.kubernetes.io/part-of' => 'utcp',
            'utcp.dev/runtime-node' => $runtimeNodeSlug,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function assertOwned(array $resource, string $runtimeNodeSlug): void
    {
        $labels = data_get($resource, 'metadata.labels', []);
        if (! is_array($labels)) {
            throw KubernetesWorkloadClientException::ownershipConflict();
        }
        foreach ($this->ownershipLabels($runtimeNodeSlug) as $key => $value) {
            if ((string) ($labels[$key] ?? '') !== $value) {
                throw KubernetesWorkloadClientException::ownershipConflict();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function createBody(array $desired, string $expectedKind): array
    {
        if ($expectedKind === 'Service') {
            $desired['spec'] = $this->withoutServiceManagedFields($desired['spec'] ?? []);
        }

        return $desired;
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function patchBody(array $desired, string $expectedKind): array
    {
        foreach (['apiVersion', 'kind'] as $key) {
            unset($desired[$key]);
        }
        $desired['metadata'] = array_intersect_key($desired['metadata'], array_flip(['name', 'namespace', 'labels', 'annotations']));
        if ($expectedKind === 'Service') {
            $desired['spec'] = $this->withoutServiceManagedFields($desired['spec'] ?? []);
        }

        return $desired;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutServiceManagedFields(mixed $spec): array
    {
        if (! is_array($spec)) {
            throw KubernetesWorkloadClientException::invalidRequest('Kubernetes Service spec is invalid.');
        }
        foreach (['clusterIP', 'clusterIPs', 'ipFamilies', 'ipFamilyPolicy', 'healthCheckNodePort'] as $field) {
            unset($spec[$field]);
        }

        return $spec;
    }

    private function collectionPath(string $kind): string
    {
        $resource = match ($kind) {
            'Secret', 'Service' => strtolower($kind).'s',
            'Deployment' => 'deployments',
            default => throw KubernetesWorkloadClientException::invalidRequest('Unsupported Kubernetes resource kind.'),
        };
        $group = $kind === 'Deployment' ? '/apis/apps/v1' : '/api/v1';

        return $group.'/namespaces/'.rawurlencode((string) config('runtime_engine.kubernetes.runtime_namespace', 'utcp-runtime')).'/'.$resource;
    }

    private function resourcePath(string $kind, string $name): string
    {
        return $this->collectionPath($kind).'/'.rawurlencode($name);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function publicResource(array $resource, string $kind): array
    {
        if ($kind !== 'Secret') {
            return $resource;
        }

        return [
            'apiVersion' => $resource['apiVersion'] ?? 'v1',
            'kind' => 'Secret',
            'metadata' => $resource['metadata'] ?? [],
            'type' => $resource['type'] ?? null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function replicaSetsByUid(string $namespace): array
    {
        $response = $this->send('get', sprintf(
            '/apis/apps/v1/namespaces/%s/replicasets',
            rawurlencode($namespace),
        ));
        $this->assertSuccessful($response);

        $payload = $this->decodeObject($response, 'ReplicaSetList');
        $items = $payload['items'] ?? null;
        if (! is_array($items)) {
            throw KubernetesWorkloadClientException::failed();
        }

        $replicaSetsByUid = [];
        foreach ($items as $replicaSet) {
            if (! is_array($replicaSet)) {
                continue;
            }
            $uid = $this->objectUid($replicaSet);
            if ($uid !== '') {
                $replicaSetsByUid[$uid] = $replicaSet;
            }
        }

        return $replicaSetsByUid;
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
            422 => throw KubernetesWorkloadClientException::invalidRequest(),
            408 => throw KubernetesWorkloadClientException::unavailable('Kubernetes API request timed out.'),
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

    /**
     * @param  array<string, array<string, mixed>>  $replicaSetsByUid
     */
    private function isOwnedByDeploymentUid(array $pod, string $deploymentUid, array $replicaSetsByUid): bool
    {
        $replicaSetOwner = $this->controllerOwnerReference($pod);
        if ($replicaSetOwner === null || (string) ($replicaSetOwner['kind'] ?? '') !== 'ReplicaSet') {
            return false;
        }

        $replicaSetUid = (string) ($replicaSetOwner['uid'] ?? '');
        if ($replicaSetUid === '' || ! isset($replicaSetsByUid[$replicaSetUid])) {
            return false;
        }

        $deploymentOwner = $this->controllerOwnerReference($replicaSetsByUid[$replicaSetUid]);

        return $deploymentOwner !== null
            && (string) ($deploymentOwner['kind'] ?? '') === 'Deployment'
            && (string) ($deploymentOwner['uid'] ?? '') === $deploymentUid;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function controllerOwnerReference(array $object): ?array
    {
        $ownerReferences = data_get($object, 'metadata.ownerReferences', []);
        if (! is_array($ownerReferences)) {
            return null;
        }

        foreach ($ownerReferences as $ownerReference) {
            if (is_array($ownerReference) && ($ownerReference['controller'] ?? null) === true) {
                return $ownerReference;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function objectUid(array $object): string
    {
        return (string) data_get($object, 'metadata.uid', '');
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
