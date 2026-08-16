<?php

namespace App\Simulator;

use InvalidArgumentException;

final class SimulatorCatalog
{
    public function adapterKey(): string
    {
        return (string) config('simulator.adapter_key', 'simulator-deterministic');
    }

    public function family(): string
    {
        return (string) config('simulator.runtime_family', 'simulator');
    }

    /**
     * The simulator reports the capabilities supported by its registered
     * adapter, independently of any RuntimeNode declared capability set.
     *
     * @return list<string>
     */
    public function supportedCapabilities(): array
    {
        $adapter = config('runtime_registry.adapter_keys.'.$this->adapterKey().'.supported_capabilities');
        $capabilities = is_array($adapter) ? array_values(array_filter($adapter, 'is_string')) : [];
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);

        return $capabilities;
    }

    /**
     * @return list<string>
     */
    public function scenarios(): array
    {
        return array_values(config('simulator.scenarios', []));
    }

    public function assertScenario(string $scenarioKey, int $version): void
    {
        if (! in_array($scenarioKey, $this->scenarios(), true)) {
            throw new InvalidArgumentException('Unsupported simulator scenario.');
        }
        if ($version !== 1) {
            throw new InvalidArgumentException('Unsupported simulator scenario version.');
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function validateParameters(array $parameters): array
    {
        $safe = [];
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $key)) {
                throw new InvalidArgumentException('Invalid simulator parameter key.');
            }
            if (! is_int($value) && ! is_bool($value) && ! is_string($value)) {
                throw new InvalidArgumentException('Simulator parameters must be scalar values.');
            }
            if (is_string($value) && strlen($value) > 160) {
                throw new InvalidArgumentException('Simulator parameter value is too long.');
            }
            $safe[$key] = $value;
        }
        ksort($safe);

        return $safe;
    }

    public function eventType(string $name): string
    {
        $types = config('simulator.event_types', []);
        if (! is_array($types) || ! isset($types[$name]) || ! is_string($types[$name])) {
            throw new InvalidArgumentException('Unknown simulator event type.');
        }

        return $types[$name];
    }

    public function operationType(string $name): string
    {
        $types = config('simulator.operation_types', []);
        if (! is_array($types) || ! isset($types[$name]) || ! is_string($types[$name])) {
            throw new InvalidArgumentException('Unknown simulator operation type.');
        }

        return $types[$name];
    }
}
