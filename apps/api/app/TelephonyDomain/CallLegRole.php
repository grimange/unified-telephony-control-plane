<?php

namespace App\TelephonyDomain;

enum CallLegRole: string
{
    case Originator = 'originator';
    case Destination = 'destination';
    case Consultation = 'consultation';
}
