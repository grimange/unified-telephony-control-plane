<?php

namespace App\RuntimeEngine\Commands;

final class RuntimeAdapterRegistry
{
    /** @var array<string, RuntimeAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<RuntimeAdapter>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(RuntimeAdapter $adapter): void
    {
        $this->adapters[$adapter->adapterKey()] = $adapter;
    }

    public function get(string $adapterKey): ?RuntimeAdapter
    {
        return $this->adapters[$adapterKey] ?? null;
    }
}
