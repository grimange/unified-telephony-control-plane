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
        $key = $handler->operationType().':'.$handler->payloadVersion();
        if (isset($this->handlers[$key])) {
            throw new \LogicException('Duplicate runtime operation handler registration: '.$key);
        }
        $this->handlers[$key] = $handler;
    }

    public function get(string $operationType, int $payloadVersion): ?RuntimeOperationHandler
    {
        return $this->handlers[$operationType.':'.$payloadVersion] ?? null;
    }

    /**
     * @return list<string>
     */
    public function operationTypes(): array
    {
        return array_values(array_unique(array_map(
            static fn (RuntimeOperationHandler $handler): string => $handler->operationType(),
            $this->handlers,
        )));
    }
}
