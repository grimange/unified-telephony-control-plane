<?php

namespace App\ControlPlane\Shared;

use InvalidArgumentException;
use Stringable;

final readonly class IdempotencyKey implements Stringable
{
    public function __construct(private string $value)
    {
        if (preg_match('/\A[a-zA-Z0-9:._-]{8,160}\z/', $value) !== 1) {
            throw new InvalidArgumentException('idempotency key must be 8-160 safe characters');
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
