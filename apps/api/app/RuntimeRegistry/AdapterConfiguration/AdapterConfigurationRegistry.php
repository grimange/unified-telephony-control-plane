<?php

namespace App\RuntimeRegistry\AdapterConfiguration;

use InvalidArgumentException;

final class AdapterConfigurationRegistry
{
    /**
     * @param  list<AdapterConfigurationHandler>  $handlers
     */
    public function __construct(private readonly array $handlers) {}

    public function forNode(object $runtimeNode): AdapterConfigurationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($runtimeNode)) {
                return $handler;
            }
        }

        throw new InvalidArgumentException('Adapter configuration is not available for this runtime node.');
    }
}
