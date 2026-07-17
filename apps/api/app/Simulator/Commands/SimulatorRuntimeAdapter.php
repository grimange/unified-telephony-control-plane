<?php

namespace App\Simulator\Commands;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionAdapter;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionResult;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\Simulator\SimulatorCatalog;
use App\Simulator\SimulatorScheduledEventRepository;
use Illuminate\Support\Facades\DB;

final class SimulatorRuntimeAdapter implements RuntimeAdapter, RuntimeConferenceInspectionAdapter
{
    public function __construct(
        private readonly SimulatorCatalog $catalog,
        private readonly SimulatorScheduledEventRepository $events,
        private readonly RuntimeEventReceiptRepository $receipts,
    ) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function execute(array $operation): array
    {
        return DB::transaction(function () use ($operation): array {
            $node = DB::table('runtime_nodes')->where('id', $operation['runtime_node_id'])->lockForUpdate()->first();
            if ($node === null || $node->adapter_key !== $this->adapterKey()) {
                return $this->failure(FailureClass::InvalidRequest, 'simulator_node_mismatch', 'runtime node is not configured for the deterministic simulator');
            }

            $profile = DB::table('simulator_profiles')->where('runtime_node_id', $node->id)->lockForUpdate()->first();
            $state = DB::table('simulator_states')->where('runtime_node_id', $node->id)->lockForUpdate()->first();
            if ($profile === null || $state === null) {
                return $this->failure(FailureClass::InvalidRequest, 'simulator_profile_missing', 'simulator profile is missing');
            }

            $scenario = (string) $profile->scenario_key;
            $attempt = ((int) $state->attempt_count) + 1;
            DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
                'attempt_count' => $attempt,
                'updated_at' => now(),
            ]);

            if ($scenario === 'terminal-failure') {
                return $this->failure(FailureClass::InvalidRequest, 'simulator_terminal_failure', 'simulator scenario reached a terminal failure');
            }
            if ($scenario === 'transient-failure-then-ready' && $attempt <= (int) $this->parameter($profile, 'transient_attempts', 1)) {
                return $this->failure(FailureClass::TransientTransport, 'simulator_transient_failure', 'simulator scenario scheduled a retryable failure');
            }
            if ($scenario === 'timeout-then-ready' && $attempt === 1) {
                return $this->failure(FailureClass::Timeout, 'simulator_timeout', 'simulator scenario timed out');
            }

            $operationType = (string) $operation['operation_type'];
            $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
            $targetGeneration = (int) ($payload['configuration_generation'] ?? $node->configuration_version);

            if ($this->isConferenceOperation($operationType)) {
                $this->executeConferenceOperation($node, $profile, $state, $operationType, $payload, $targetGeneration);
                DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
                    'current_phase' => 'conference_events_scheduled',
                    'updated_at' => now(),
                ]);

                return [
                    'status' => 'completed',
                    'event_type' => 'runtime_operation.simulator_completed',
                    'event_payload' => [
                        'adapter_key' => $this->adapterKey(),
                        'operation_type' => $operationType,
                        'scenario_key' => $scenario,
                        'configuration_generation' => $targetGeneration,
                    ],
                ];
            }

            match ($scenario) {
                'duplicate-observation' => $this->scheduleDuplicateReady($node, $profile, $targetGeneration),
                'disconnect-reconnect' => $this->scheduleDisconnectReconnect($node, $profile, $targetGeneration),
                'configuration-drift-then-converge' => $this->scheduleConfigurationDrift($node, $profile, $operationType, $targetGeneration),
                default => $this->scheduleReady($node, $profile, $targetGeneration),
            };

            DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
                'current_phase' => 'events_scheduled',
                'updated_at' => now(),
            ]);

            return [
                'status' => 'completed',
                'event_type' => 'runtime_operation.simulator_completed',
                'event_payload' => [
                    'adapter_key' => $this->adapterKey(),
                    'operation_type' => $operationType,
                    'scenario_key' => $scenario,
                    'configuration_generation' => $targetGeneration,
                ],
            ];
        });
    }

    public function inspectConferenceRuntime(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult
    {
        $state = DB::table('simulator_states')
            ->join('runtime_nodes', 'runtime_nodes.id', '=', 'simulator_states.runtime_node_id')
            ->where('simulator_states.runtime_node_id', $runtimeNodeId)
            ->where('runtime_nodes.tenant_id', $tenantId)
            ->select('simulator_states.state_payload')
            ->first();
        if ($state === null) {
            return RuntimeConferenceInspectionResult::failed('invalid_request', 'simulator_state_missing');
        }

        $payload = $this->statePayload($state);
        $conferenceState = $payload['conferences'][$conferenceId]['state'] ?? null;
        $conferencePresent = $conferenceState === 'ready';
        if ($participantId === null) {
            return RuntimeConferenceInspectionResult::observed($conferencePresent);
        }

        $participant = $payload['participants'][$participantId] ?? null;
        $participantPresent = is_array($participant) && ($participant['state'] ?? null) === 'joined';
        $participantAttached = $participantPresent && ($participant['conference_id'] ?? null) === $conferenceId && $conferencePresent;

        return RuntimeConferenceInspectionResult::observed($conferencePresent, $participantPresent, $participantAttached);
    }

    public function recordConferenceRuntimeInspectionEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool
    {
        return DB::transaction(function () use ($tenantId, $runtimeNodeId, $conferenceId, $participantId): bool {
            $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($node === null || $node->adapter_key !== $this->adapterKey()) {
                return false;
            }

            $profile = DB::table('simulator_profiles')->where('runtime_node_id', $node->id)->lockForUpdate()->first();
            $state = DB::table('simulator_states')->where('runtime_node_id', $node->id)->lockForUpdate()->first();
            if ($profile === null || $state === null) {
                return false;
            }

            $payload = $this->statePayload($state);
            $conferenceState = $payload['conferences'][$conferenceId]['state'] ?? null;
            $epoch = $this->openEpoch($node);
            $conferencePayload = [
                'conference_id' => $conferenceId,
                'runtime_node_id' => $node->id,
                'configuration_generation' => (int) ($payload['conferences'][$conferenceId]['configuration_generation'] ?? $node->configuration_version),
            ];

            if ($participantId === null) {
                if ($conferenceState === 'ready') {
                    $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('conference_ready'), 1, $this->conferencePayload($node, $profile, $conferencePayload, 'ready', (int) $conferencePayload['configuration_generation']));

                    return true;
                }
                if ($conferenceState === 'closed') {
                    $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('conference_closed'), 1, $this->conferencePayload($node, $profile, $conferencePayload, 'closed', (int) $conferencePayload['configuration_generation']));

                    return true;
                }

                return false;
            }

            $participant = $payload['participants'][$participantId] ?? null;
            if (! is_array($participant)) {
                return false;
            }

            $participantPayload = [
                'conference_id' => $conferenceId,
                'participant_id' => $participantId,
                'telephony_session_id' => (string) ($participant['telephony_session_id'] ?? ''),
                'runtime_node_id' => $node->id,
            ];
            $generation = (int) ($participant['configuration_generation'] ?? $node->configuration_version);
            if (($participant['state'] ?? null) === 'joined') {
                $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('participant_joined'), 1, $this->participantPayload($node, $profile, $participantPayload, 'joined', $generation));

                return true;
            }
            if (($participant['state'] ?? null) === 'left') {
                $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('participant_left'), 1, $this->participantPayload($node, $profile, $participantPayload, 'left', $generation));

                return true;
            }

            return false;
        });
    }

    private function isConferenceOperation(string $operationType): bool
    {
        return in_array($operationType, [
            (string) config('telephony_domain.operation_types.conference_ensure'),
            (string) config('telephony_domain.operation_types.conference_close'),
            (string) config('telephony_domain.operation_types.participant_ensure'),
            (string) config('telephony_domain.operation_types.participant_remove'),
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeConferenceOperation(object $node, object $profile, object $state, string $operationType, array $payload, int $configurationGeneration): void
    {
        $statePayload = $this->statePayload($state);
        $statePayload['conferences'] ??= [];
        $statePayload['participants'] ??= [];

        $conferenceId = (string) ($payload['conference_id'] ?? '');
        $participantId = isset($payload['participant_id']) ? (string) $payload['participant_id'] : null;

        $epoch = $this->openEpoch($node);
        if ($operationType === (string) config('telephony_domain.operation_types.conference_close')) {
            $statePayload['conferences'][$conferenceId] = [
                'state' => 'closed',
                'configuration_generation' => $configurationGeneration,
            ];
            $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('conference_closed'), 1, $this->conferencePayload($node, $profile, $payload, 'closed', $configurationGeneration));
        } elseif ($operationType === (string) config('telephony_domain.operation_types.conference_ensure')) {
            $statePayload['conferences'][$conferenceId] = [
                'state' => 'ready',
                'configuration_generation' => $configurationGeneration,
            ];
            $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('conference_ready'), 1, $this->conferencePayload($node, $profile, $payload, 'ready', $configurationGeneration));
        } elseif ($operationType === (string) config('telephony_domain.operation_types.participant_remove')) {
            $statePayload['participants'][$participantId] = [
                'state' => 'left',
                'conference_id' => $conferenceId,
                'telephony_session_id' => (string) ($payload['telephony_session_id'] ?? ''),
                'configuration_generation' => $configurationGeneration,
            ];
            $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('participant_left'), 1, $this->participantPayload($node, $profile, $payload, 'left', $configurationGeneration));
        } else {
            $statePayload['participants'][$participantId] = [
                'state' => 'joined',
                'conference_id' => $conferenceId,
                'telephony_session_id' => (string) ($payload['telephony_session_id'] ?? ''),
                'configuration_generation' => $configurationGeneration,
            ];
            $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('participant_joined'), 1, $this->participantPayload($node, $profile, $payload, 'joined', $configurationGeneration));
        }

        DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
            'state_payload' => json_encode($statePayload, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function scheduleReady(object $node, object $profile, int $configurationGeneration): void
    {
        $epoch = $this->openEpoch($node);
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('connection_opened'), 1, $this->payload($node, $profile, 'connecting', $configurationGeneration));
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('configuration_observed'), 1, $this->payload($node, $profile, 'ready', $configurationGeneration, ['configuration_generation' => $configurationGeneration]), 1);
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('readiness_changed'), 1, $this->payload($node, $profile, 'ready', $configurationGeneration), 2);
    }

    private function scheduleDuplicateReady(object $node, object $profile, int $configurationGeneration): void
    {
        $epoch = $this->openEpoch($node);
        $payload = $this->payload($node, $profile, 'ready', $configurationGeneration);
        $duplicateKey = 'simulator:duplicate:'.$node->id.':'.$configurationGeneration;
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('readiness_changed'), 1, $payload, 0, $duplicateKey);
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('readiness_changed'), 1, $payload, 1, $duplicateKey);
    }

    private function scheduleDisconnectReconnect(object $node, object $profile, int $configurationGeneration): void
    {
        $first = $this->openEpoch($node);
        $this->events->schedule($node->tenant_id, $node->id, $first, $this->catalog->eventType('connection_opened'), 1, $this->payload($node, $profile, 'connecting', $configurationGeneration));
        $this->events->schedule($node->tenant_id, $node->id, $first, $this->catalog->eventType('readiness_changed'), 1, $this->payload($node, $profile, 'ready', $configurationGeneration), 1);
        $this->events->schedule($node->tenant_id, $node->id, $first, $this->catalog->eventType('connection_closed'), 1, $this->payload($node, $profile, 'unavailable', $configurationGeneration), 2);
        $second = $this->openEpoch($node);
        $this->events->schedule($node->tenant_id, $node->id, $second, $this->catalog->eventType('connection_opened'), 1, $this->payload($node, $profile, 'connecting', $configurationGeneration), 3);
        $this->events->schedule($node->tenant_id, $node->id, $second, $this->catalog->eventType('readiness_changed'), 1, $this->payload($node, $profile, 'ready', $configurationGeneration), 4);
    }

    private function scheduleConfigurationDrift(object $node, object $profile, string $operationType, int $configurationGeneration): void
    {
        $epoch = $this->openEpoch($node);
        $observed = $operationType === $this->catalog->operationType('apply_configuration')
            ? $configurationGeneration
            : max(0, $configurationGeneration - 1);
        $state = $observed === $configurationGeneration ? 'ready' : 'degraded';
        $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('configuration_observed'), 1, $this->payload($node, $profile, $state, $observed, ['configuration_generation' => $observed]));
        if ($state === 'ready') {
            $this->events->schedule($node->tenant_id, $node->id, $epoch, $this->catalog->eventType('readiness_changed'), 1, $this->payload($node, $profile, 'ready', $observed), 1);
            DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
                'applied_configuration_generation' => $observed,
                'updated_at' => now(),
            ]);
        }
    }

    private function openEpoch(object $node): string
    {
        $epoch = $this->receipts->openEpoch($node->tenant_id, $node->id, $this->adapterKey(), 'simulator-event-source');
        DB::table('simulator_states')->where('runtime_node_id', $node->id)->update([
            'active_connection_epoch' => $epoch,
            'updated_at' => now(),
        ]);

        return $epoch;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(object $node, object $profile, string $observedState, int $configurationGeneration, array $extra = []): array
    {
        return array_merge([
            'runtime_node_id' => $node->id,
            'scenario_key' => $profile->scenario_key,
            'scenario_version' => (int) $profile->scenario_version,
            'observed_state' => $observedState,
            'configuration_generation' => $configurationGeneration,
            'occurred_at' => now()->toISOString(),
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $operationPayload
     * @return array<string, mixed>
     */
    private function conferencePayload(object $node, object $profile, array $operationPayload, string $observedState, int $configurationGeneration): array
    {
        return $this->payload($node, $profile, $observedState, $configurationGeneration, [
            'conference_id' => (string) ($operationPayload['conference_id'] ?? ''),
            'runtime_node_id' => $node->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operationPayload
     * @return array<string, mixed>
     */
    private function participantPayload(object $node, object $profile, array $operationPayload, string $observedState, int $configurationGeneration): array
    {
        return $this->payload($node, $profile, $observedState, $configurationGeneration, [
            'conference_id' => (string) ($operationPayload['conference_id'] ?? ''),
            'participant_id' => (string) ($operationPayload['participant_id'] ?? ''),
            'telephony_session_id' => (string) ($operationPayload['telephony_session_id'] ?? ''),
            'runtime_node_id' => $node->id,
        ]);
    }

    private function parameter(object $profile, string $key, int $default): int
    {
        $parameters = json_decode((string) $profile->parameters, true, 512, JSON_THROW_ON_ERROR);

        return is_array($parameters) && isset($parameters[$key]) ? (int) $parameters[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return [
            'status' => 'terminal_failure',
            'failure_class' => $class->value,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(object $state): array
    {
        $payload = json_decode((string) $state->state_payload, true, 512, JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }
}
