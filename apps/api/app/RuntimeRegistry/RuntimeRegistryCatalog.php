<?php

namespace App\RuntimeRegistry;

use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationRegistry;
use InvalidArgumentException;

final class RuntimeRegistryCatalog
{
    public function __construct(private readonly AdapterConfigurationRegistry $adapterConfigurations) {}

    /**
     * @return array<string, mixed>
     */
    public function managementCatalog(): array
    {
        $capabilities = [];
        foreach (config('runtime_registry.runtime_capabilities', []) as $key => $label) {
            $capabilities[$key] = [
                'key' => $key,
                'display_name' => (string) $label,
                'label' => (string) $label,
                'description' => (string) $label,
            ];
        }

        $families = [];
        foreach (config('runtime_registry.runtime_families', []) as $key => $family) {
            if (! is_array($family)) {
                continue;
            }
            $adapterKeys = array_values(array_filter($family['adapter_keys'] ?? [], 'is_string'));
            $families[$key] = [
                'key' => $key,
                'display_name' => (string) ($family['display_name'] ?? $key),
                'adapter_keys' => $adapterKeys,
                'adapters' => $adapterKeys,
            ];
        }

        $adapters = [];
        foreach (config('runtime_registry.adapter_keys', []) as $key => $adapter) {
            if (! is_array($adapter)) {
                continue;
            }
            $configurationAvailable = (bool) ($adapter['adapter_configuration_available'] ?? false);
            $serialized = [
                'key' => $key,
                'runtime_family' => (string) ($adapter['runtime_family'] ?? ''),
                'display_name' => (string) ($adapter['display_name'] ?? $key),
                'description' => (string) ($adapter['description'] ?? ''),
                'supported_capabilities' => $this->stringList($adapter['supported_capabilities'] ?? []),
                'required_capabilities' => $this->stringList($adapter['required_capabilities'] ?? []),
                'endpoint_requirements' => array_values(array_filter($adapter['endpoint_requirements'] ?? [], 'is_array')),
                'credentials_required' => (bool) ($adapter['credentials_required'] ?? false),
                'adapter_configuration_available' => $configurationAvailable,
            ];

            if ($configurationAvailable) {
                $descriptors = $this->adapterConfigurations->descriptorsForAdapter($key);
                if ($descriptors->isEmpty()) {
                    throw new InvalidArgumentException("Configurable runtime adapter {$key} must publish adapter configuration descriptors.");
                }
                $serialized['adapter_configuration'] = $descriptors->toArray();
            }

            $adapters[$key] = $serialized;
        }

        return [
            'catalog_version' => (string) config('runtime_registry.catalog_version', 'unknown'),
            'runtime_families' => $families,
            'adapter_keys' => $adapters,
            'runtime_capabilities' => $capabilities,
            'desired_states' => $this->stringList(config('runtime_registry.desired_states', [])),
            'endpoint_purposes' => $this->stringList(config('runtime_registry.endpoint_purposes', [])),
            'endpoint_transports' => $this->stringList(config('runtime_registry.endpoint_transports', [])),
            'endpoint_tls_modes' => $this->stringList(config('runtime_registry.endpoint_tls_modes', [])),
        ];
    }

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

    /**
     * @param  list<string>  $capabilities
     */
    public function assertCapabilitiesForAdapter(string $adapterKey, array $capabilities): void
    {
        $this->assertRuntimeCapabilities($capabilities);
        $adapter = config("runtime_registry.adapter_keys.$adapterKey");
        if (! is_array($adapter)) {
            throw new InvalidArgumentException('Invalid runtime adapter.');
        }
        $supported = $this->stringList($adapter['supported_capabilities'] ?? []);
        foreach ($capabilities as $capability) {
            if (! in_array($capability, $supported, true)) {
                throw new InvalidArgumentException('Unsupported capability for runtime adapter.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }
}
