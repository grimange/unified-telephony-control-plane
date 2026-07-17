<?php

namespace App\TelephonyDomain\Runtime;

use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;

final readonly class ConferenceOperationHandler implements RuntimeOperationHandler
{
    public function __construct(
        private string $operationType,
        private string $requiredCapability,
    ) {}

    public function operationType(): string
    {
        return $this->operationType;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return $this->requiredCapability;
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        if ($adapter === null) {
            return ['status' => 'terminal_failure', 'failure_class' => 'unsupported_capability', 'failure_code' => 'runtime_adapter_not_available', 'failure_message' => 'runtime adapter is not available'];
        }

        $payload = $operation['payload'] ?? [];
        if (! is_array($payload) || ! isset($payload['conference_id'], $payload['runtime_node_id'], $payload['configuration_generation'])) {
            return ['status' => 'terminal_failure', 'failure_class' => 'invalid_request', 'failure_code' => 'invalid_conference_operation_payload', 'failure_message' => 'conference operation payload is invalid'];
        }
        if (str_contains($this->operationType, 'participant') && ! isset($payload['participant_id'], $payload['telephony_session_id'])) {
            return ['status' => 'terminal_failure', 'failure_class' => 'invalid_request', 'failure_code' => 'invalid_participant_operation_payload', 'failure_message' => 'conference participant operation payload is invalid'];
        }

        return $adapter->execute($operation);
    }
}
