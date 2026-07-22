<?php

namespace App\RuntimeRegistry\AdapterConfiguration;

use InvalidArgumentException;

final class AdapterConfigurationRegistry
{
    /**
     * @param  list<AdapterConfigurationHandler>  $handlers
     */
    public function __construct(private readonly array $handlers) {}

    public function forAdapterKey(string $adapterKey): AdapterConfigurationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->adapterKey() === $adapterKey) {
                return $handler;
            }
        }

        throw new InvalidArgumentException('Adapter configuration is not available for this runtime adapter.');
    }

    public function forNode(object $runtimeNode): AdapterConfigurationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($runtimeNode)) {
                return $handler;
            }
        }

        throw new InvalidArgumentException('Adapter configuration is not available for this runtime node.');
    }

    public function hasAdapter(string $adapterKey): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->adapterKey() === $adapterKey) {
                return true;
            }
        }

        return false;
    }

    public function descriptorsForAdapter(string $adapterKey): AdapterConfigurationDescriptorCollection
    {
        return $this->forAdapterKey($adapterKey)->configurationDescriptors();
    }
}
