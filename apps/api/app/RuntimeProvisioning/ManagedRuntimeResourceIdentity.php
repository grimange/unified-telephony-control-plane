<?php

namespace App\RuntimeProvisioning;

use Illuminate\Support\Str;

final class ManagedRuntimeResourceIdentity
{
    /** @return array{deployment:string,service:string,secret:string} */
    public static function names(string $providerPrefix, string $slug, string $runtimeNodeId): array
    {
        $base = substr(trim(Str::slug($slug), '-'), 0, 34).'-'.substr(hash('sha256', $runtimeNodeId), 0, 8);

        return [
            'deployment' => $providerPrefix.'-'.$base,
            'service' => $providerPrefix.'-'.$base,
            'secret' => $providerPrefix.'-'.$base.'-credentials',
        ];
    }
}
