<?php

namespace App\RuntimeProvisioning;

use App\RuntimeEngine\Commands\RuntimeAdapter;

interface ManagedRuntimeProvisioningProvider
{
    public function adapterKey(): string;

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    public function provisionOperation(array $operation, ?RuntimeAdapter $adapter): array;

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    public function deprovisionOperation(array $operation, ?RuntimeAdapter $adapter): array;

    /** @return array<string,mixed> */
    public function desiredDeployment(string $runtimeNodeId, string $slug): array;
}
