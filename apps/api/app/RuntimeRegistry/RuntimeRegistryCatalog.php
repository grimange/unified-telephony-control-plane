<?php

namespace App\RuntimeRegistry;

use InvalidArgumentException;

final class RuntimeRegistryCatalog
{
    public function assertAdapterForFamily(string $runtimeFamily, string $adapterKey): void
    {
        $family = config("runtime_registry.runtime_families.$runtimeFamily");
        $adapter = config("runtime_registry.adapter_keys.$adapterKey");

        if (! is_array($family) || ! is_array($adapter) || ($adapter['runtime_family'] ?? null) !== $runtimeFamily) {
            throw new InvalidArgumentException('Invalid runtime family or adapter key.');
        }
    }

    public function assertDesiredState(string $state): void
    {
        if (! in_array($state, config('runtime_registry.desired_states', []), true)) {
            throw new InvalidArgumentException('Invalid desired state.');
        }
    }

    public function assertDesiredTransition(string $from, string $to): void
    {
        $this->assertDesiredState($to);
        $allowed = [
            'draft' => ['active', 'disabled'],
            'active' => ['draining', 'disabled'],
            'draining' => ['active', 'disabled'],
            'disabled' => ['draft', 'active'],
        ];

        if ($from === $to) {
            return;
        }

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw new InvalidArgumentException('Invalid desired-state transition.');
        }
    }

    public function assertEndpoint(string $purpose, string $transport, int $port, string $tlsMode, ?string $path): void
    {
        if (! in_array($purpose, config('runtime_registry.endpoint_purposes', []), true)) {
            throw new InvalidArgumentException('Invalid endpoint purpose.');
        }

        if (! in_array($transport, config('runtime_registry.endpoint_transports', []), true)) {
            throw new InvalidArgumentException('Invalid endpoint transport.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid endpoint port.');
        }

        if (! in_array($tlsMode, config('runtime_registry.endpoint_tls_modes', []), true)) {
            throw new InvalidArgumentException('Invalid endpoint TLS mode.');
        }

        if ($path !== null && $path !== '' && ! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Endpoint path must start with /.');
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function assertRuntimeCapabilities(array $capabilities): void
    {
        $known = array_keys(config('runtime_registry.runtime_capabilities', []));
        foreach ($capabilities as $capability) {
            if (! in_array($capability, $known, true)) {
                throw new InvalidArgumentException('Unknown runtime capability.');
            }
        }
    }
}
