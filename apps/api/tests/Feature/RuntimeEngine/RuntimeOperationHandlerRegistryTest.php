<?php

namespace Tests\Feature\RuntimeEngine;

use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use PHPUnit\Framework\TestCase;

final class RuntimeOperationHandlerRegistryTest extends TestCase
{
    public function test_duplicate_operation_and_payload_version_fails_loudly(): void
    {
        $registry = new RuntimeOperationHandlerRegistry;
        $registry->register($this->handler('test.operation', 1, 'a'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate runtime operation handler registration: test.operation:1');
        $registry->register($this->handler('test.operation', 1, 'b'));
    }

    public function test_unique_registration_remains_available(): void
    {
        $registry = new RuntimeOperationHandlerRegistry;
        $handler = $this->handler('test.operation', 1, 'a');
        $registry->register($handler);

        $this->assertSame($handler, $registry->get('test.operation', 1));
    }

    private function handler(string $type, int $version, string $result): RuntimeOperationHandler
    {
        return new class($type, $version, $result) implements RuntimeOperationHandler
        {
            public function __construct(private string $type, private int $version, private string $result) {}

            public function operationType(): string
            {
                return $this->type;
            }

            public function payloadVersion(): int
            {
                return $this->version;
            }

            public function requiredRuntimeCapability(): ?string
            {
                return null;
            }

            public function execute(array $operation, ?RuntimeAdapter $adapter): array
            {
                return ['status' => $this->result];
            }
        };
    }
}
