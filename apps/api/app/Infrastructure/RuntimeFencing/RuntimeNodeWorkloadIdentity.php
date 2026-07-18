<?php

namespace App\Infrastructure\RuntimeFencing;

final readonly class RuntimeNodeWorkloadIdentity
{
    public function __construct(
        public string $namespace,
        public string $deployment,
    ) {}
}
