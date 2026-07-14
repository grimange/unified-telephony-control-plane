<?php

namespace App\RuntimeEngine\Reconciliation;

final readonly class ReconciliationResult
{
    /**
     * @param  array<string, mixed>  $operationPayload
     */
    private function __construct(
        public string $status,
        public ?string $reasonCode = null,
        public ?string $operationType = null,
        public array $operationPayload = [],
        public int $nextCheckSeconds = 300,
    ) {}

    public static function converged(int $nextCheckSeconds = 300): self
    {
        return new self('converged', nextCheckSeconds: $nextCheckSeconds);
    }

    public static function waiting(string $reasonCode, int $nextCheckSeconds = 120): self
    {
        return new self('waiting', $reasonCode, nextCheckSeconds: $nextCheckSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function operationRequired(string $operationType, array $payload, string $reasonCode = 'drift_detected'): self
    {
        return new self('operation_required', $reasonCode, $operationType, $payload, 60);
    }

    public static function blocked(string $reasonCode, int $nextCheckSeconds = 900): self
    {
        return new self('blocked', $reasonCode, nextCheckSeconds: $nextCheckSeconds);
    }

    public static function unsupported(string $reasonCode = 'reconciler_not_registered', int $nextCheckSeconds = 1800): self
    {
        return new self('unsupported', $reasonCode, nextCheckSeconds: $nextCheckSeconds);
    }
}
