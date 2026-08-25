<?php

namespace App\RuntimeRegistry;

final class RuntimeExecutionContract
{
    public static function digest(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }

        if (preg_match('/(?:^|@)(sha256:[0-9a-f]{64})(?:$|[?#])/', $image, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isCurrent(?string $desired, ?string $observed): bool
    {
        $desiredDigest = self::digest($desired);
        $observedDigest = self::digest($observed);

        return $desiredDigest !== null && $desiredDigest === $observedDigest;
    }
}
