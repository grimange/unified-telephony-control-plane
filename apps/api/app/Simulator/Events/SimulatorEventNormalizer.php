<?php

namespace App\Simulator\Events;

use App\RuntimeEngine\Events\EventNormalizer;
use App\Simulator\SimulatorCatalog;

final class SimulatorEventNormalizer implements EventNormalizer
{
    public function __construct(
        private readonly SimulatorCatalog $catalog,
        private readonly string $eventType,
    ) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function normalize(object $receipt, array $payload): array
    {
        $state = is_string($payload['observed_state'] ?? null) ? $payload['observed_state'] : 'unknown';
        $generation = isset($payload['configuration_generation']) ? (int) $payload['configuration_generation'] : null;
        $observedAt = is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now();

        if (in_array($this->eventType, [
            $this->catalog->eventType('conference_ready'),
            $this->catalog->eventType('conference_closed'),
            $this->catalog->eventType('conference_degraded'),
        ], true)) {
            return [[
                'observation_type' => 'conference.lifecycle.observed',
                'observation_version' => 1,
                'subject_type' => 'conference',
                'subject_id' => (string) ($payload['conference_id'] ?? ''),
                'observed_state' => $state,
                'configuration_version' => $generation,
                'observed_at' => $observedAt,
                'payload' => [
                    'adapter_key' => $this->adapterKey(),
                    'runtime_node_id' => (string) $receipt->runtime_node_id,
                    'event_type' => $this->eventType,
                    'scenario_key' => is_string($payload['scenario_key'] ?? null) ? $payload['scenario_key'] : null,
                ],
            ]];
        }

        if (in_array($this->eventType, [
            $this->catalog->eventType('participant_joined'),
            $this->catalog->eventType('participant_left'),
            $this->catalog->eventType('participant_failed'),
        ], true)) {
            return [[
                'observation_type' => 'conference.participant.observed',
                'observation_version' => 1,
                'subject_type' => 'conference_participant',
                'subject_id' => (string) ($payload['participant_id'] ?? ''),
                'observed_state' => $state,
                'configuration_version' => $generation,
                'observed_at' => $observedAt,
                'payload' => [
                    'adapter_key' => $this->adapterKey(),
                    'conference_id' => (string) ($payload['conference_id'] ?? ''),
                    'telephony_session_id' => (string) ($payload['telephony_session_id'] ?? ''),
                    'runtime_node_id' => (string) $receipt->runtime_node_id,
                    'event_type' => $this->eventType,
                    'scenario_key' => is_string($payload['scenario_key'] ?? null) ? $payload['scenario_key'] : null,
                ],
            ]];
        }

        $observationType = match ($this->eventType) {
            $this->catalog->eventType('connection_opened'),
            $this->catalog->eventType('connection_closed') => 'runtime.connection.observed',
            $this->catalog->eventType('capabilities_observed') => 'runtime.capability.observed',
            $this->catalog->eventType('configuration_observed') => 'runtime.configuration.observed',
            default => 'runtime.readiness.observed',
        };

        return [[
            'observation_type' => $observationType,
            'observation_version' => 1,
            'subject_type' => 'runtime_node',
            'subject_id' => (string) $receipt->runtime_node_id,
            'observed_state' => $state,
            'configuration_version' => $generation,
            'observed_at' => $observedAt,
            'payload' => [
                'adapter_key' => $this->adapterKey(),
                'event_type' => $this->eventType,
                'scenario_key' => is_string($payload['scenario_key'] ?? null) ? $payload['scenario_key'] : null,
            ],
        ]];
    }
}
