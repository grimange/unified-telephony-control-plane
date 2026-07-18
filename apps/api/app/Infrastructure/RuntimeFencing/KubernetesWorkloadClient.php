<?php

namespace App\Infrastructure\RuntimeFencing;

interface KubernetesWorkloadClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function getDeployment(string $namespace, string $name): ?array;

    public function scaleDeployment(string $namespace, string $name, int $replicas): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array;
}
