<?php

namespace App\ControlPlane\RuntimeOperations;

final readonly class ClaimedOperation
{
    public function __construct(
        public string $id,
        public string $leaseOwner,
        public string $leaseToken,
        public int $attemptCount,
    ) {}
}
