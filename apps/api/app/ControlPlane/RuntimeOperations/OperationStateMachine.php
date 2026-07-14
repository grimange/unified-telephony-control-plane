<?php

namespace App\ControlPlane\RuntimeOperations;

final class OperationStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pending' => ['leased', 'cancelled', 'expired'],
        'leased' => ['leased', 'running', 'retry_scheduled', 'succeeded', 'terminal_failed', 'cancelled', 'expired'],
        'running' => ['leased', 'retry_scheduled', 'succeeded', 'terminal_failed', 'cancelled', 'expired'],
        'retry_scheduled' => ['leased', 'cancelled', 'expired'],
        'succeeded' => [],
        'terminal_failed' => [],
        'cancelled' => [],
        'expired' => [],
    ];

    public function assertTransition(OperationStatus $from, OperationStatus $to): void
    {
        if (! in_array($to->value, self::ALLOWED[$from->value], true)) {
            throw new InvalidOperationTransition("invalid runtime operation transition {$from->value} -> {$to->value}");
        }
    }

    public function canTransition(OperationStatus $from, OperationStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }
}
