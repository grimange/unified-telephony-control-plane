<?php

namespace App\TelephonyDomain;

final class RuntimeChannelIdentity
{
    public static function forCallLeg(string $callLegId): string
    {
        return 'utcp-call-leg-'.mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $callLegId) ?: 'unknown', 0, 80);
    }

    public static function callLegId(string $runtimeChannelId): ?string
    {
        $prefix = 'utcp-call-leg-';
        if (! str_starts_with($runtimeChannelId, $prefix)) {
            return null;
        }
        $legId = substr($runtimeChannelId, strlen($prefix));

        return $legId === '' ? null : $legId;
    }
}
