<?php

namespace App\ControlPlane\RuntimeOperations;

enum OperationStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Running = 'running';
    case RetryScheduled = 'retry_scheduled';
    case Succeeded = 'succeeded';
    case TerminalFailed = 'terminal_failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::TerminalFailed,
            self::Cancelled,
            self::Expired,
        ], true);
    }
}
