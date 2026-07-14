<?php

namespace App\ControlPlane\Shared;

use InvalidArgumentException;
use Stringable;

abstract readonly class OpaqueId implements Stringable
{
    final public function __construct(private string $value)
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $value) !== 1) {
            throw new InvalidArgumentException('opaque identifier must be 32 lowercase hexadecimal characters');
        }
    }

    public static function new(): static
    {
        return new static(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): static
    {
        return new static(strtolower($value));
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
