<?php

namespace App\RuntimeProvisioning;

use Illuminate\Support\Str;

final class ManagedAsteriskResourceIdentity
{
    /**
     * @return array{deployment:string,service:string,secret:string}
     */
    public static function names(string $slug, string $runtimeNodeId): array
    {
        $base = substr(trim(Str::slug($slug), '-'), 0, 34).'-'.substr(hash('sha256', $runtimeNodeId), 0, 8);

        return [
            'deployment' => 'asterisk-'.$base,
            'service' => 'asterisk-'.$base,
            'secret' => 'asterisk-'.$base.'-credentials',
        ];
    }
}
