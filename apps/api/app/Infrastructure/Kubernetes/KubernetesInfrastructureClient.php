<?php

namespace App\Infrastructure\Kubernetes;

interface KubernetesInfrastructureClient
{
    /** @return list<array<string, mixed>> */
    public function listNodes(): array;

    /** @return list<array<string, mixed>> */
    public function listPods(): array;
}
