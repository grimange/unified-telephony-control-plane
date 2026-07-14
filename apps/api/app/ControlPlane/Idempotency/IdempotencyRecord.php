<?php

namespace App\ControlPlane\Idempotency;

final readonly class IdempotencyRecord
{
    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public string $status,
        public ?array $result,
    ) {}

    public function inProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
