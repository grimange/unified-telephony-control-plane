<?php

namespace App\TelephonyDomain;

enum CallState: string
{
    case Requested = 'requested';
    case SelectingRoute = 'selecting_route';
    case Originating = 'originating';
    case Offered = 'offered';
    case Ringing = 'ringing';
    case EarlyMedia = 'early_media';
    case Answered = 'answered';
    case Bridged = 'bridged';
    case Held = 'held';
    case Transferring = 'transferring';
    case Terminating = 'terminating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
