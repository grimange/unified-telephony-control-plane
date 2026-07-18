<?php

namespace App\Infrastructure\RuntimeFencing;

final class InfrastructureAdapterRegistry
{
    /** @var array<string, RuntimeInfrastructureFenceAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<RuntimeInfrastructureFenceAdapter>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(RuntimeInfrastructureFenceAdapter $adapter): void
    {
        $this->adapters[$adapter->adapterKey()] = $adapter;
    }

    public function get(string $adapterKey): ?RuntimeInfrastructureFenceAdapter
    {
        return $this->adapters[$adapterKey] ?? null;
    }
}
