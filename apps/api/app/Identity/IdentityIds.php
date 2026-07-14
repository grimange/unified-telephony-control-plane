<?php

namespace App\Identity;

use Illuminate\Support\Str;

final class IdentityIds
{
    public static function new(): string
    {
        return (string) Str::uuid();
    }
}
