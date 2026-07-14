<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Str;

final class RuntimeRegistryIds
{
    public static function new(): string
    {
        return (string) Str::uuid();
    }
}
