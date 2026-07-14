<?php

namespace App\RuntimeEngine\Commands;

final class RuntimeOperationHandlerRegistry
{
    /** @var array<string, RuntimeOperationHandler> */
    private array $handlers = [];

    /**
     * @param  iterable<RuntimeOperationHandler>  $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(RuntimeOperationHandler $handler): void
    {
        $this->handlers[$handler->operationType().':'.$handler->payloadVersion()] = $handler;
    }

    public function get(string $operationType, int $payloadVersion): ?RuntimeOperationHandler
    {
        return $this->handlers[$operationType.':'.$payloadVersion] ?? null;
    }
}
