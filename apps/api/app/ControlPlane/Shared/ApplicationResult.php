<?php

namespace App\ControlPlane\Shared;

enum ApplicationResult: string
{
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Conflicted = 'conflicted';
    case RetryScheduled = 'retry_scheduled';
    case TerminalFailure = 'terminal_failure';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
