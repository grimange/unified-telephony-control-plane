<?php

namespace App\TelephonyDomain;

/** Immutable explanation of one bounded route eligibility check. */
final readonly class RouteConstraint
{
    private function __construct(private string $code, private bool $passed, private string $detail) {}

    public static function passed(string $code, string $detail = ''): self
    {
        return new self($code, true, $detail);
    }

    public static function failed(string $code, string $detail): self
    {
        return new self($code, false, $detail);
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}
