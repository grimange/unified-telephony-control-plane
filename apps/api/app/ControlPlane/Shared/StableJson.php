<?php

namespace App\ControlPlane\Shared;

final class StableJson
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        self::sortRecursive($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', self::encode(PayloadSafety::assertSafe($payload)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function sortRecursive(array &$payload): void
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                self::sortRecursive($value);
            }
        }
    }
}
