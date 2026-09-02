<?php

namespace App\Simulator\Commands;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\TelephonyDomain\CallOperationCatalog;
use App\TelephonyDomain\CaptureReference;
use InvalidArgumentException;

final class SimulatorCallOperationHandler implements RuntimeOperationHandler
{
    /** @var array{target:string, capability:string} */
    private array $definition;

    public function __construct(private readonly string $type)
    {
        $this->definition = CallOperationCatalog::all()[$type] ?? throw new InvalidArgumentException('unknown C6 operation type');
    }

    public function operationType(): string
    {
        return $this->type;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return $this->definition['capability'];
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        if (! $adapter instanceof RuntimeAdapter) {
            return $this->failure(FailureClass::UnsupportedCapability, 'call_adapter_not_registered', 'C6 operations require a registered call-capable runtime adapter');
        }

        try {
            $this->validateOperation($operation);
        } catch (InvalidArgumentException $exception) {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_call_operation_payload', $exception->getMessage());
        }

        return $adapter->execute($operation);
    }

    /** @param array<string, mixed> $operation */
    private function validateOperation(array $operation): void
    {
        if (($operation['operation_type'] ?? null) !== $this->type || ($operation['aggregate_type'] ?? null) !== $this->definition['target']) {
            throw new InvalidArgumentException('operation envelope does not match the C6 catalog');
        }

        $payload = $operation['payload'] ?? null;
        if (! is_array($payload)) {
            throw new InvalidArgumentException('operation payload must be an object');
        }

        $aggregateId = (string) ($operation['aggregate_id'] ?? '');
        if ($aggregateId === '') {
            throw new InvalidArgumentException('operation aggregate id is required');
        }

        if ($this->definition['target'] === 'call') {
            $this->assertStringEquals($payload, 'call_id', $aggregateId, 'call_id is required and must match the Call target');
        } elseif ($this->definition['target'] === 'call_leg') {
            $this->assertStringEquals($payload, 'call_id', '', 'call_id is required');
            $this->assertStringEquals($payload, 'leg_id', $aggregateId, 'leg_id is required and must match the CallLeg target');
        } else {
            $this->validateRelationship($payload, $aggregateId);
        }

        if ($this->type === 'call.leg.send_dtmf' && (! is_string($payload['digit'] ?? null) || ! preg_match('/^[0-9*#]$/', $payload['digit']))) {
            throw new InvalidArgumentException('sendDtmf requires one normalized DTMF digit');
        }
        if ($this->type === 'call.leg.play_media' && (! is_string($payload['media_ref'] ?? null) || ! str_starts_with($payload['media_ref'], 'utcp:media/'))) {
            throw new InvalidArgumentException('playMedia requires an opaque utcp:media reference');
        }
        if (in_array($this->type, ['call.leg.start_recording', 'call.leg.stop_recording'], true)) {
            if (! is_string($payload['capture_ref'] ?? null)) {
                throw new InvalidArgumentException('recording operations require an opaque capture reference');
            }
            CaptureReference::parse($payload['capture_ref']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateRelationship(array $payload, string $callId): void
    {
        $this->assertStringEquals($payload, 'call_id', $callId, 'relationship call_id is required and must match the Call target');
        $legIds = $payload['leg_ids'] ?? null;
        if (! is_array($legIds) || count($legIds) !== 2 || count(array_unique($legIds)) !== 2 || ! array_reduce($legIds, static fn (bool $valid, mixed $id): bool => $valid && is_string($id) && $id !== '', true)) {
            throw new InvalidArgumentException('relationship operations require two distinct normalized leg ids');
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertStringEquals(array $payload, string $key, string $expected, string $message): void
    {
        if (! is_string($payload[$key] ?? null) || ($expected !== '' && $payload[$key] !== $expected)) {
            throw new InvalidArgumentException($message);
        }
    }

    /** @return array{status:string,failure_class:string,failure_code:string,failure_message:string} */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return [
            'status' => 'terminal_failure',
            'failure_class' => $class->value,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }
}
