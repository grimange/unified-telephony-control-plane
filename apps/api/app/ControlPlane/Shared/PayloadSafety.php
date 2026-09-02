<?php

namespace App\ControlPlane\Shared;

use InvalidArgumentException;

final class PayloadSafety
{
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret(?!_fingerprint)|token|authorization|cookie|private[_-]?key|credential(?!_reference_id)|sip[_-]?password/i';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function assertSafe(array $payload): array
    {
        self::walk($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                $redacted[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $redacted[$key] = self::redact($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function walk(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                throw new InvalidArgumentException('payload contains sensitive field: '.$key);
            }
            if (is_array($value)) {
                self::walk($value);
            }
        }
    }
}
