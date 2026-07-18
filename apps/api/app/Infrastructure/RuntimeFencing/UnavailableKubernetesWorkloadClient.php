<?php

namespace App\Infrastructure\RuntimeFencing;

final class UnavailableKubernetesWorkloadClient implements KubernetesWorkloadClient
{
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
}
