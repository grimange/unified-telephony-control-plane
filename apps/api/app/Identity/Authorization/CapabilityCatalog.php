<?php

namespace App\Identity\Authorization;

use Illuminate\Support\Facades\Config;

final class CapabilityCatalog
{
    /**
     * @return array<string, array{scope: string, description: string}>
     */
    public function capabilities(): array
    {
        return Config::get('identity.capabilities', []);
    }

    /**
     * @return array<string, array{scope: string, display_name: string, capabilities: list<string>}>
     */
    public function roles(): array
    {
        return Config::get('identity.roles', []);
    }

    public function roleScope(string $roleKey): ?string
    {
        return $this->roles()[$roleKey]['scope'] ?? null;
    }

    public function hasCapability(string $capabilityKey): bool
    {
        return array_key_exists($capabilityKey, $this->capabilities());
    }
}
