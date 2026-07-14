<?php

namespace App\RuntimeEngine\Outbox;

final readonly class OutboxClaim
{
    public function __construct(
        public string $id,
        public string $leaseToken,
        public int $attemptCount,
        public string $eventType,
        public string $aggregateType,
        public string $aggregateId,
    ) {}
}
