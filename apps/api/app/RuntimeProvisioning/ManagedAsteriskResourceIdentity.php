<?php

namespace App\RuntimeProvisioning;

final class ManagedAsteriskResourceIdentity
{
    /**
     * @return array{deployment:string,service:string,secret:string}
     */
    public static function names(string $slug, string $runtimeNodeId): array
    {
        return ManagedRuntimeResourceIdentity::names('asterisk', $slug, $runtimeNodeId);
    }
}
