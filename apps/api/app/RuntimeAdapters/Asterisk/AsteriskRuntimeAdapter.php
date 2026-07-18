<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionAdapter;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionResult;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AsteriskRuntimeAdapter implements RuntimeAdapter, RuntimeConferenceInspectionAdapter
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly AsteriskAriClient $client,
        private readonly ?RuntimeEventReceiptRepository $receipts = null,
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
        $operationType = (string) ($operation['operation_type'] ?? '');
        $node = DB::table('runtime_nodes')->where('id', $operation['runtime_node_id'] ?? null)->first();
        if ($node === null || $node->adapter_key !== $this->adapterKey()) {
            return $this->failure(FailureClass::InvalidRequest, 'asterisk_node_mismatch', 'Runtime node is not configured for Asterisk ARI.');
        }

        try {
            return match ($operationType) {
                'runtime.node.inspect' => $this->inspect($operation, $node),
                'conference.ensure' => $this->ensureConference($operation, $node),
                'conference.close' => $this->closeConference($operation, $node),
                'conference.participant.ensure' => $this->ensureParticipant($operation, $node),
                'conference.participant.remove' => $this->removeParticipant($operation, $node),
                'runtime.node.verify_conference_absent' => $this->verifyConferenceAbsent($operation, $node),
                default => $this->failure(FailureClass::UnsupportedCapability, 'asterisk_operation_unsupported', 'Asterisk ARI adapter does not support this operation.'),
            };
        } catch (AsteriskAriException $exception) {
            Log::warning('asterisk ari operation failed', [
                'component' => 'telephony-command-worker',
                'runtime_family' => $this->catalog->runtimeFamily(),
                'adapter_key' => $this->adapterKey(),
                'operation_type' => $operationType,
                'result' => $exception->retryable ? 'retryable_failure' : 'terminal_failure',
                'failure_class' => $exception->failureClass->value,
            ]);

            return $this->failure($exception->failureClass, $exception->failureCode, $exception->getMessage(), $exception->retryable);
        }
    }

    public function inspectConferenceRuntime(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult
    {
        try {
            $summary = $this->client->conferenceRuntimeSummary($tenantId, $runtimeNodeId, $conferenceId, $participantId);
        } catch (AsteriskAriException $exception) {
            if ($exception->retryable) {
                return RuntimeConferenceInspectionResult::unavailable($exception->failureClass->value, $exception->failureCode);
            }

            return RuntimeConferenceInspectionResult::failed($exception->failureClass->value, $exception->failureCode);
        }

        return RuntimeConferenceInspectionResult::observed(
            (bool) ($summary['bridge_exists'] ?? false),
            array_key_exists('participant_channel_exists', $summary) ? (bool) $summary['participant_channel_exists'] : null,
            array_key_exists('participant_channel_in_bridge', $summary) ? (bool) $summary['participant_channel_in_bridge'] : null,
        );
    }

    public function recordConferenceRuntimeInspectionEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool
    {
        $node = DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->first();
        if ($node === null || $node->adapter_key !== $this->adapterKey()) {
            return false;
        }

        $epoch = $this->currentOpenEpoch($runtimeNodeId);
        if ($epoch === null) {
            return false;
        }

        $conference = DB::table('conferences')
            ->where('id', $conferenceId)
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)
            ->first();
        if ($conference === null) {
            return false;
        }

        try {
            $summary = $this->client->conferenceRuntimeSummary($tenantId, $runtimeNodeId, $conferenceId, $participantId);
        } catch (AsteriskAriException) {
            return false;
        }

        $inspectionRun = now()->format('YmdHisv');
        $recorded = false;
        if ((string) $conference->desired_state === 'open' && (bool) ($summary['bridge_exists'] ?? false)) {
            $recorded = $this->ingestInspectionEvidence($node, (string) $epoch->id, 'inspection:bridge-present:'.$conferenceId.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('bridge_created'), [
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => (int) $conference->configuration_generation,
                'bridge_id' => $this->client->conferenceBridgeId($conferenceId),
                'occurred_at' => now()->toISOString(),
            ]) || $recorded;
        }
        if ((string) $conference->desired_state === 'closed' && ! (bool) ($summary['bridge_exists'] ?? false)) {
            $recorded = $this->ingestInspectionEvidence($node, (string) $epoch->id, 'inspection:bridge-absent:'.$conferenceId.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('bridge_destroyed'), [
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => (int) $conference->configuration_generation,
                'bridge_id' => $this->client->conferenceBridgeId($conferenceId),
                'occurred_at' => now()->toISOString(),
            ]) || $recorded;
        }

        if ($participantId === null) {
            return $recorded;
        }

        $participant = DB::table('conference_participants')
            ->where('id', $participantId)
            ->where('tenant_id', $tenantId)
            ->where('conference_id', $conferenceId)
            ->first();
        if ($participant === null) {
            return $recorded;
        }

        $channelId = $this->client->participantChannelId($participantId);
        if ((string) $conference->desired_state === 'open' && (string) $participant->desired_state === 'admitted' && (bool) ($summary['participant_channel_in_bridge'] ?? false)) {
            $recorded = $this->ingestInspectionEvidence($node, (string) $epoch->id, 'inspection:channel-present:'.$participantId.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('channel_entered_bridge'), [
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => (int) $conference->configuration_generation,
                'bridge_id' => $this->client->conferenceBridgeId($conferenceId),
                'channel_id' => $channelId,
                'occurred_at' => now()->toISOString(),
            ]) || $recorded;
        }
        if (((string) $participant->desired_state === 'removed' || (string) $conference->desired_state === 'closed') && ! (bool) ($summary['participant_channel_exists'] ?? false)) {
            $recorded = $this->ingestInspectionEvidence($node, (string) $epoch->id, 'inspection:channel-absent:'.$participantId.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('channel_destroyed'), [
                'runtime_node_id' => $runtimeNodeId,
                'configuration_generation' => (int) $conference->configuration_generation,
                'bridge_id' => $this->client->conferenceBridgeId($conferenceId),
                'channel_id' => $channelId,
                'occurred_at' => now()->toISOString(),
            ]) || $recorded;
        }

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function inspect(array $operation, object $node): array
    {
        $info = $this->client->inspect((string) $node->tenant_id, (string) $node->id);

        Log::info('asterisk ari inspection completed', [
            'component' => 'telephony-command-worker',
            'runtime_family' => $this->catalog->runtimeFamily(),
            'adapter_key' => $this->adapterKey(),
            'operation_type' => (string) ($operation['operation_type'] ?? ''),
            'result' => 'completed',
        ]);

        return [
            'status' => 'completed',
            'event_type' => 'runtime_operation.asterisk_ari_inspected',
            'event_payload' => [
                'adapter_key' => $this->adapterKey(),
                'operation_type' => (string) ($operation['operation_type'] ?? ''),
                'configuration_generation' => $info['configuration_generation'],
                'asterisk_version_observed' => $info['asterisk_version'] !== 'unknown',
                'auth_generation' => $info['auth_generation'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function ensureConference(array $operation, object $node): array
    {
        $conference = $this->conferenceFromOperation($operation, $node);
        if ((string) $conference->desired_state !== 'open' || $this->operationGeneration($operation) < (int) $conference->configuration_generation) {
            return $this->completed('runtime_operation.asterisk_conference_stale', $operation, [
                'conference_id' => (string) $conference->id,
                'configuration_generation' => (int) $conference->configuration_generation,
                'runtime_reference_present' => false,
                'stale_operation' => true,
            ]);
        }

        $result = $this->client->ensureConferenceBridge(
            (string) $node->tenant_id,
            (string) $node->id,
            (string) $conference->id,
            (int) $conference->configuration_generation,
        );

        return $this->completed('runtime_operation.asterisk_conference_ensured', $operation, [
            'conference_id' => (string) $conference->id,
            'configuration_generation' => (int) $result['configuration_generation'],
            'runtime_reference_present' => true,
            'already_existed' => (bool) ($result['already_existed'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function closeConference(array $operation, object $node): array
    {
        $conference = $this->conferenceFromOperation($operation, $node);
        if ((string) $conference->desired_state !== 'closed' || $this->operationGeneration($operation) < (int) $conference->configuration_generation) {
            return $this->completed('runtime_operation.asterisk_conference_stale', $operation, [
                'conference_id' => (string) $conference->id,
                'configuration_generation' => (int) $conference->configuration_generation,
                'runtime_reference_present' => false,
                'stale_operation' => true,
            ]);
        }

        $result = $this->client->closeConferenceBridge(
            (string) $node->tenant_id,
            (string) $node->id,
            (string) $conference->id,
            (int) $conference->configuration_generation,
        );

        return $this->completed('runtime_operation.asterisk_conference_closed', $operation, [
            'conference_id' => (string) $conference->id,
            'configuration_generation' => (int) $result['configuration_generation'],
            'runtime_reference_present' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function ensureParticipant(array $operation, object $node): array
    {
        [$conference, $participant] = $this->participantFromOperation($operation, $node);
        if ((string) $conference->desired_state !== 'open' || (string) $participant->desired_state !== 'admitted' || $this->operationGeneration($operation) < (int) $conference->configuration_generation) {
            return $this->completed('runtime_operation.asterisk_conference_participant_stale', $operation, [
                'conference_id' => (string) $conference->id,
                'conference_participant_id' => (string) $participant->id,
                'configuration_generation' => (int) $conference->configuration_generation,
                'runtime_reference_present' => false,
                'stale_operation' => true,
            ]);
        }

        $result = $this->client->ensureParticipantChannel(
            (string) $node->tenant_id,
            (string) $node->id,
            (string) $conference->id,
            (string) $participant->id,
            (int) $conference->configuration_generation,
        );

        return $this->completed('runtime_operation.asterisk_conference_participant_ensured', $operation, [
            'conference_id' => (string) $conference->id,
            'conference_participant_id' => (string) $participant->id,
            'configuration_generation' => (int) $result['configuration_generation'],
            'runtime_reference_present' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function removeParticipant(array $operation, object $node): array
    {
        [$conference, $participant] = $this->participantFromOperation($operation, $node);
        if ((string) $participant->desired_state !== 'removed' && (string) $conference->desired_state !== 'closed') {
            return $this->completed('runtime_operation.asterisk_conference_participant_stale', $operation, [
                'conference_id' => (string) $conference->id,
                'conference_participant_id' => (string) $participant->id,
                'configuration_generation' => (int) $conference->configuration_generation,
                'runtime_reference_present' => false,
                'stale_operation' => true,
            ]);
        }
        if ($this->operationGeneration($operation) < (int) $conference->configuration_generation) {
            return $this->completed('runtime_operation.asterisk_conference_participant_stale', $operation, [
                'conference_id' => (string) $conference->id,
                'conference_participant_id' => (string) $participant->id,
                'configuration_generation' => (int) $conference->configuration_generation,
                'runtime_reference_present' => false,
                'stale_operation' => true,
            ]);
        }

        $result = $this->client->removeParticipantChannel(
            (string) $node->tenant_id,
            (string) $node->id,
            (string) $conference->id,
            (string) $participant->id,
            (int) $conference->configuration_generation,
        );

        return $this->completed('runtime_operation.asterisk_conference_participant_removed', $operation, [
            'conference_id' => (string) $conference->id,
            'conference_participant_id' => (string) $participant->id,
            'configuration_generation' => (int) $result['configuration_generation'],
            'runtime_reference_present' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function verifyConferenceAbsent(array $operation, object $node): array
    {
        $payload = $operation['payload'] ?? [];
        if (! is_array($payload)) {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_absence_verification_payload', 'Conference absence verification payload is invalid.');
        }

        $conferenceId = (string) ($payload['conference_id'] ?? '');
        $formerBindingId = (string) ($payload['former_runtime_binding_id'] ?? '');
        $formerRuntimeNodeId = (string) ($payload['former_runtime_node_id'] ?? '');
        $generation = (int) ($payload['configuration_generation'] ?? 0);
        if ($conferenceId === '' || $formerBindingId === '' || $formerRuntimeNodeId === '' || $generation < 1 || $formerRuntimeNodeId !== (string) $node->id) {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_absence_verification_payload', 'Conference absence verification payload is invalid.');
        }

        $conference = DB::table('conferences')
            ->where('id', $conferenceId)
            ->where('tenant_id', (string) $node->tenant_id)
            ->first();
        $binding = DB::table('conference_runtime_bindings')
            ->where('id', $formerBindingId)
            ->where('tenant_id', (string) $node->tenant_id)
            ->where('conference_id', $conferenceId)
            ->where('runtime_node_id', $formerRuntimeNodeId)
            ->first();
        if ($conference === null || $binding === null) {
            return $this->failure(FailureClass::InvalidRequest, 'absence_verification_context_not_found', 'Conference absence verification context is not valid.');
        }

        $bridgeSummary = $this->client->conferenceRuntimeSummary((string) $node->tenant_id, (string) $node->id, $conferenceId);
        if ((bool) ($bridgeSummary['bridge_exists'] ?? false)) {
            return $this->absenceVerificationCompleted($operation, $conferenceId, $formerBindingId, $formerRuntimeNodeId, $generation, 'present', [
                'bridge_present' => true,
                'participant_channel_present' => false,
            ]);
        }

        $participantIds = DB::table('conference_participants')
            ->where('tenant_id', (string) $node->tenant_id)
            ->where('conference_id', $conferenceId)
            ->orderBy('id')
            ->pluck('id');
        foreach ($participantIds as $participantId) {
            $participantSummary = $this->client->conferenceRuntimeSummary((string) $node->tenant_id, (string) $node->id, $conferenceId, (string) $participantId);
            if ((bool) ($participantSummary['participant_channel_exists'] ?? false)) {
                return $this->absenceVerificationCompleted($operation, $conferenceId, $formerBindingId, $formerRuntimeNodeId, $generation, 'present', [
                    'bridge_present' => false,
                    'participant_channel_present' => true,
                ]);
            }
        }

        return $this->absenceVerificationCompleted($operation, $conferenceId, $formerBindingId, $formerRuntimeNodeId, $generation, 'absent', [
            'bridge_present' => false,
            'participant_channel_present' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function absenceVerificationCompleted(array $operation, string $conferenceId, string $formerBindingId, string $formerRuntimeNodeId, int $generation, string $result, array $extra): array
    {
        return $this->completed('conference.runtime_fence_verified', $operation, array_merge([
            'operation_id' => (string) ($operation['id'] ?? ''),
            'conference_id' => $conferenceId,
            'former_runtime_binding_id' => $formerBindingId,
            'former_runtime_node_id' => $formerRuntimeNodeId,
            'configuration_generation' => $generation,
            'verification_result' => $result,
            'runtime_reference_present' => $result === 'present',
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function conferenceFromOperation(array $operation, object $node): object
    {
        $conferenceId = (string) data_get($operation, 'payload.conference_id', '');
        $conference = DB::table('conferences')
            ->where('id', $conferenceId)
            ->where('tenant_id', (string) $node->tenant_id)
            ->first();
        if ($conference === null || ((string) $conference->runtime_node_id !== (string) $node->id && $this->operationGeneration($operation) >= (int) $conference->configuration_generation)) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'conference_not_bound_to_node', 'Conference is not bound to this Asterisk runtime node.');
        }

        return $conference;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array{0:object,1:object}
     */
    private function participantFromOperation(array $operation, object $node): array
    {
        $conference = $this->conferenceFromOperation($operation, $node);
        $participantId = (string) data_get($operation, 'payload.participant_id', '');
        $participant = DB::table('conference_participants')
            ->where('id', $participantId)
            ->where('tenant_id', (string) $node->tenant_id)
            ->where('conference_id', (string) $conference->id)
            ->first();
        if ($participant === null) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'conference_participant_not_found', 'Conference participant is not valid for this conference.');
        }

        return [$conference, $participant];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function operationGeneration(array $operation): int
    {
        return (int) data_get($operation, 'payload.configuration_generation', 0);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function completed(string $eventType, array $operation, array $payload): array
    {
        Log::info('asterisk ari operation completed', [
            'component' => 'telephony-command-worker',
            'runtime_family' => $this->catalog->runtimeFamily(),
            'adapter_key' => $this->adapterKey(),
            'operation_type' => (string) ($operation['operation_type'] ?? ''),
            'result' => 'completed',
        ]);

        return [
            'status' => 'completed',
            'event_type' => $eventType,
            'event_payload' => array_merge([
                'adapter_key' => $this->adapterKey(),
                'operation_type' => (string) ($operation['operation_type'] ?? ''),
            ], $payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(FailureClass $class, string $code, string $message, bool $retryable = false): array
    {
        return [
            'status' => $retryable ? 'retry_scheduled' : 'terminal_failure',
            'failure_class' => $class->value,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }

    private function currentOpenEpoch(string $runtimeNodeId): ?object
    {
        return DB::table('runtime_event_connection_epochs')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('adapter_key', $this->adapterKey())
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingestInspectionEvidence(object $node, string $epochId, string $externalKey, string $eventType, array $payload): bool
    {
        $result = ($this->receipts ?? app(RuntimeEventReceiptRepository::class))->ingest(
            (string) $node->tenant_id,
            (string) $node->id,
            $this->adapterKey(),
            $epochId,
            $externalKey,
            $eventType,
            1,
            $payload,
        );

        return in_array($result['status'], ['accepted', 'duplicate'], true);
    }
}
