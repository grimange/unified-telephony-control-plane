<?php

namespace App\Infrastructure\Kubernetes;

interface KubernetesMaintenanceClient
{
    /** @return array<string, mixed> */
    public function node(string $name): array;

    public function cordon(string $name): void;

    /**
     * @param list<array{namespace:string,deployment:string}> $workloadIdentities
     * @return list<array{namespace:string,name:string}>
     */
    public function drainablePods(string $nodeName, array $workloadIdentities): array;

    public function evict(string $namespace, string $name): void;
}
