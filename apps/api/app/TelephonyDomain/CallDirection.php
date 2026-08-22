<?php

namespace App\TelephonyDomain;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
