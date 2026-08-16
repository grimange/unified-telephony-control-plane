<?php

namespace App\Infrastructure\RuntimeFencing;

final class UnavailableKubernetesWorkloadClient implements KubernetesWorkloadClient
{
    public function inspectResource(string $kind, string $name): ?array
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function getDeployment(string $namespace, string $name): ?array
    {
        unset($namespace, $name);

        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function scaleDeployment(string $namespace, string $name, int $replicas): void
    {
        unset($namespace, $name, $replicas);

        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array
    {
        unset($namespace, $identity);

        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function applySecret(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function applyDeployment(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function applyService(array $desired, string $runtimeNodeSlug): array
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function deleteSecret(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function deleteDeployment(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }

    public function deleteService(string $name, string $runtimeNodeSlug): bool
    {
        throw KubernetesWorkloadClientException::unavailable('Kubernetes workload client is not configured.');
    }
}
