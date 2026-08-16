<?php

namespace App\RuntimeAdapters\Asterisk;

enum AsteriskConferenceParticipantBindResult: string
{
    case BOUND = 'bound';
    case ALREADY_BOUND = 'already_bound';
    case RETRYABLE = 'retryable';
    case TERMINAL = 'terminal';
}
