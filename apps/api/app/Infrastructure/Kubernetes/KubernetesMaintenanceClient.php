<?php

namespace App\Infrastructure\Kubernetes;

interface KubernetesMaintenanceClient
{
    /** @return array<string, mixed> */
    public function node(string $name): array;

    public function cordon(string $name): void;

    /** @return list<array{namespace:string,name:string}> */
    public function drainablePods(string $nodeName): array;

    public function evict(string $namespace, string $name): void;
}
