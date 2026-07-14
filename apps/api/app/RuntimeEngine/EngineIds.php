<?php

namespace App\RuntimeEngine;

final class EngineIds
{
    public static function new(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
