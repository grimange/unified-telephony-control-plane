<?php

namespace App\TelephonyDomain;

use Illuminate\Support\Str;

final class TelephonyDomainIds
{
    public static function new(): string
    {
        return (string) Str::uuid();
    }
}
