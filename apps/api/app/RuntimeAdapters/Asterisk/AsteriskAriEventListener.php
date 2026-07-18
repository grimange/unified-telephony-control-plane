<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class AsteriskAriEventListener
{
    /**
     * @var array<string, array{tenant_id:string,stream:resource,lease_id:string,fencing_token:string,epoch_id:string,configuration_version:int,credential_version:int,worker_id:string,heartbeat_interval_ms:int,next_health_check_at:float}>
     */
    private array $connections = [];

    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly AsteriskAriClient $client,
        private readonly AsteriskAriProfileService $profiles,
        private readonly RuntimeListenerLeaseRepository $leases,
        private readonly RuntimeEventReceiptRepository $receipts,
        private readonly ReconciliationRepository $reconciliation,
        private readonly AsteriskAriReconnectBackoff $backoff = new AsteriskAriReconnectBackoff,
    ) {}

    public function workOnce(string $workerId, int $batchSize = 5): int
    {
        $processed = 0;
        try {
            $nodes = $this->eligibleNodes($batchSize);
        } catch (Throwable $exception) {
            Log::warning('asterisk ari listener discovery failed', [
                'component' => 'asterisk-ari-events',
                'runtime_family' => $this->catalog->runtimeFamily(),
                'adapter_key' => $this->catalog->adapterKey(),
                'result' => 'discovery_failed',
                'failure_class' => 'transient_transport',
            ]);
            $nodes = [];
        }

        $eligibleById = [];
        foreach ($nodes as $node) {
            $eligibleById[(string) $node->id] = $node;
        }

        foreach (array_keys($this->connections) as $nodeId) {
            $node = $eligibleById[$nodeId] ?? null;

            if ($node === null) {
                $this->teardownConnection($nodeId, releaseLease: true);

                continue;
            }

            if ((int) $node->configuration_version !== $this->connections[$nodeId]['configuration_version']) {
                $this->teardownConnection($nodeId, releaseLease: true);

                continue;
            }

            $credentialVersion = $this->currentCredentialVersion($nodeId);
            if ($credentialVersion !== null && $credentialVersion !== $this->connections[$nodeId]['credential_version']) {
                $this->teardownConnection($nodeId, releaseLease: true);

                continue;
            }

            $conn = $this->connections[$nodeId];
            if (! $this->leases->renew($conn['lease_id'], $workerId, $conn['fencing_token'], (int) config('asterisk_ari.lease_seconds', 45))) {
                $this->teardownConnection($nodeId, releaseLease: false);

                continue;
            }

            if (microtime(true) >= $conn['next_health_check_at']) {
                try {
                    $info = $this->client->inspect($conn['tenant_id'], $nodeId);
                    if (! $this->client->stasisApplicationRegistered($conn['tenant_id'], $nodeId)) {
                        Log::warning('asterisk ari event subscription lost', [
                            'component' => 'asterisk-ari-events',
                            'runtime_family' => $this->catalog->runtimeFamily(),
                            'adapter_key' => $this->catalog->adapterKey(),
                            'result' => 'subscription_lost',
                            'failure_class' => FailureClass::TransientTransport->value,
                        ]);

                        throw new AsteriskAriException(FailureClass::TransientTransport, 'ari_stasis_subscription_lost', 'ARI Stasis application is no longer registered on the runtime.', true);
                    }
                    $this->connections[$nodeId]['next_health_check_at'] = microtime(true) + ($conn['heartbeat_interval_ms'] / 1000);
                    $this->ingest($node, $conn['epoch_id'], 'runtime-info:'.(string) $node->configuration_version.':'.$conn['fencing_token'].':'.now()->format('YmdHisu'), $this->catalog->eventType('runtime_info_observed'), [
                        'runtime_node_id' => $nodeId,
                        'configuration_generation' => (int) $node->configuration_version,
                        'asterisk_version_observed' => $info['asterisk_version'] !== 'unknown',
                        'auth_generation' => $info['auth_generation'],
                        'occurred_at' => now()->toISOString(),
                    ]);
                } catch (AsteriskAriException $exception) {
                    $this->ingestFailure($node, $conn['epoch_id'], $conn['fencing_token'], $exception);
                    $this->recordFailureBackoff($conn['tenant_id'], $nodeId, $conn['credential_version'], $conn['configuration_version']);
                    $this->teardownConnection($nodeId, releaseLease: true);

                    continue;
                }
            }

            try {
                for ($eventsRead = 0, $maxEvents = $this->maxEventsPerCycle(); $eventsRead < $maxEvents; $eventsRead++) {
                    $event = $this->client->readEvent($conn['stream']);
                    if ($event === null) {
                        break;
                    }

                    $this->ingestAriEvent($node, $conn['epoch_id'], $event);
                }
            } catch (AsteriskAriException $exception) {
                $this->ingestFailure($node, $conn['epoch_id'], $conn['fencing_token'], $exception);
                $this->recordFailureBackoff($conn['tenant_id'], $nodeId, $conn['credential_version'], $conn['configuration_version']);
                $this->teardownConnection($nodeId, releaseLease: true);

                continue;
            }

            unset($eligibleById[$nodeId]);
            $processed++;
        }

        foreach ($eligibleById as $nodeId => $node) {
            if (! $this->shouldAttempt($nodeId)) {
                continue;
            }

            $lease = $this->leases->claim((string) $node->tenant_id, $nodeId, $this->catalog->listenerKind(), $workerId, (int) config('asterisk_ari.lease_seconds', 45));
            if ($lease === null) {
                continue;
            }

            try {
                $this->openConnection($node, $lease, $workerId);
                $this->clearBackoff($nodeId);
                $processed++;
            } catch (AsteriskAriException $exception) {
                $epochId = $this->receipts->openEpoch((string) $node->tenant_id, $nodeId, $this->catalog->adapterKey(), $workerId);
                $this->ingestFailure($node, $epochId, (string) $lease->fencing_token, $exception);
                $this->receipts->closeEpoch($epochId, $workerId);
                $this->leases->release((string) $lease->id, $workerId, (string) $lease->fencing_token);
                $credentialVersion = $this->currentCredentialVersion($nodeId) ?? 0;
                $this->recordFailureBackoff((string) $node->tenant_id, $nodeId, $credentialVersion, (int) $node->configuration_version);
                Log::warning('asterisk ari listener cycle failed', [
                    'component' => 'asterisk-ari-events',
                    'runtime_family' => $this->catalog->runtimeFamily(),
                    'adapter_key' => $this->catalog->adapterKey(),
                    'result' => 'cycle_failed',
                    'failure_class' => $exception->failureClass->value,
                ]);
            }
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function ingestAriEvent(object $node, string $epochId, array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : 'unknown';
        $payload = [
            'runtime_node_id' => (string) $node->id,
            'configuration_generation' => (int) $node->configuration_version,
            'ari_event_type' => $type,
            'occurred_at' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : now()->toISOString(),
        ];
        $bridge = is_array($event['bridge'] ?? null) ? $event['bridge'] : null;
        $channel = is_array($event['channel'] ?? null) ? $event['channel'] : null;
        if ($bridge !== null) {
            $payload['bridge_id'] = is_string($bridge['id'] ?? null) ? $bridge['id'] : null;
            $payload['bridge_name'] = is_string($bridge['name'] ?? null) ? $bridge['name'] : null;
        }
        if ($channel !== null) {
            $payload['channel_id'] = is_string($channel['id'] ?? null) ? $channel['id'] : null;
            $payload['channel_name'] = is_string($channel['name'] ?? null) ? $channel['name'] : null;
        }

        $this->ingest(
            $node,
            $epochId,
            'ari:'.hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)),
            $this->catalog->eventType($this->catalogEventKey($type)),
            $payload,
        );
        $this->wakeConferenceRecoveryFromAriEvent($node, $type, $payload);
    }

    private function maxEventsPerCycle(): int
    {
        $value = config('asterisk_ari.max_events_per_cycle', 50);
        if (! is_int($value) || $value < 1 || $value > 1000) {
            throw new RuntimeException('Invalid Asterisk ARI max events per cycle.');
        }

        return $value;
    }

    private function openConnection(object $node, object $lease, string $workerId): void
    {
        $nodeId = (string) $node->id;
        $stream = $this->client->openWebSocket((string) $node->tenant_id, $nodeId);
        if (! $this->leases->isCurrent((string) $lease->id, $workerId, (string) $lease->fencing_token)) {
            $this->client->closeWebSocket($stream);

            throw new AsteriskAriException(FailureClass::Conflict, 'ari_lease_lost_during_open', 'Lease was lost while opening the connection.');
        }

        $profile = $this->profiles->requiredProfile((string) $node->tenant_id, $nodeId);
        $this->receipts->closeStaleEpochs($nodeId, $workerId);
        $epochId = $this->receipts->openEpoch((string) $node->tenant_id, $nodeId, $this->catalog->adapterKey(), $workerId);
        $info = $this->client->inspect((string) $node->tenant_id, $nodeId);
        $this->ingest($node, $epochId, 'connection:opened:'.(string) $lease->fencing_token, $this->catalog->eventType('connection_opened'), [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => (int) $node->configuration_version,
            'occurred_at' => now()->toISOString(),
        ]);
        $this->ingest($node, $epochId, 'runtime-info:'.(string) $node->configuration_version.':'.(string) $lease->fencing_token, $this->catalog->eventType('runtime_info_observed'), [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => (int) $node->configuration_version,
            'asterisk_version_observed' => $info['asterisk_version'] !== 'unknown',
            'auth_generation' => $info['auth_generation'],
            'occurred_at' => now()->toISOString(),
        ]);
        $this->ingestConferenceRuntimeInspection($node, $epochId);

        $heartbeatIntervalMs = max(1000, (int) $profile['heartbeat_interval_ms']);
        $this->connections[$nodeId] = [
            'tenant_id' => (string) $node->tenant_id,
            'stream' => $stream,
            'lease_id' => (string) $lease->id,
            'fencing_token' => (string) $lease->fencing_token,
            'epoch_id' => $epochId,
            'configuration_version' => (int) $node->configuration_version,
            'credential_version' => (int) $info['auth_generation'],
            'worker_id' => $workerId,
            'heartbeat_interval_ms' => $heartbeatIntervalMs,
            'next_health_check_at' => microtime(true) + ($heartbeatIntervalMs / 1000),
        ];
    }

    private function ingestConferenceRuntimeInspection(object $node, string $epochId): void
    {
        $conferences = DB::table('conferences')
            ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conferences.id')
            ->where('conferences.tenant_id', (string) $node->tenant_id)
            ->where('conference_runtime_bindings.tenant_id', (string) $node->tenant_id)
            ->where('conference_runtime_bindings.runtime_node_id', (string) $node->id)
            ->where('conference_runtime_bindings.status', 'active')
            ->whereIn('desired_state', ['open', 'closed'])
            ->select('conferences.*')
            ->orderBy('conferences.id')
            ->get();

        $inspectionRun = now()->format('YmdHisv');
        foreach ($conferences as $conference) {
            try {
                $summary = $this->client->conferenceRuntimeSummary(
                    (string) $node->tenant_id,
                    (string) $node->id,
                    (string) $conference->id,
                );
            } catch (AsteriskAriException $exception) {
                Log::warning('asterisk conference reconnect inspection failed', [
                    'component' => 'asterisk-ari-events',
                    'runtime_family' => $this->catalog->runtimeFamily(),
                    'adapter_key' => $this->catalog->adapterKey(),
                    'result' => 'inspection_failed',
                    'failure_class' => $exception->failureClass->value,
                ]);

                return;
            }

            if ((string) $conference->desired_state === 'open' && (bool) ($summary['bridge_exists'] ?? false)) {
                $this->ingest($node, $epochId, 'inspection:bridge-present:'.(string) $conference->id.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('bridge_created'), [
                    'runtime_node_id' => (string) $node->id,
                    'configuration_generation' => (int) $conference->configuration_generation,
                    'bridge_id' => $this->client->conferenceBridgeId((string) $conference->id),
                    'occurred_at' => now()->toISOString(),
                ]);
            }

            if ((string) $conference->desired_state === 'open' && ! (bool) ($summary['bridge_exists'] ?? false)) {
                $this->reconciliation->wakeTarget((string) $conference->tenant_id, 'conference', (string) $conference->id, (int) $conference->configuration_generation, 0);
                Log::info('asterisk conference reconnect inspection woke recovery', [
                    'component' => 'asterisk-ari-events',
                    'runtime_family' => $this->catalog->runtimeFamily(),
                    'adapter_key' => $this->catalog->adapterKey(),
                    'result' => 'runtime_drift_detected',
                    'resource_type' => 'conference',
                ]);
            }

            if ((string) $conference->desired_state === 'closed' && ! (bool) ($summary['bridge_exists'] ?? false)) {
                $this->ingest($node, $epochId, 'inspection:bridge-absent:'.(string) $conference->id.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('bridge_destroyed'), [
                    'runtime_node_id' => (string) $node->id,
                    'configuration_generation' => (int) $conference->configuration_generation,
                    'bridge_id' => $this->client->conferenceBridgeId((string) $conference->id),
                    'occurred_at' => now()->toISOString(),
                ]);
            }

            $participants = DB::table('conference_participants')
                ->where('tenant_id', (string) $node->tenant_id)
                ->where('conference_id', (string) $conference->id)
                ->whereIn('desired_state', ['admitted', 'removed'])
                ->orderBy('id')
                ->get();

            foreach ($participants as $participant) {
                try {
                    $participantSummary = $this->client->conferenceRuntimeSummary(
                        (string) $node->tenant_id,
                        (string) $node->id,
                        (string) $conference->id,
                        (string) $participant->id,
                    );
                } catch (AsteriskAriException $exception) {
                    Log::warning('asterisk participant reconnect inspection failed', [
                        'component' => 'asterisk-ari-events',
                        'runtime_family' => $this->catalog->runtimeFamily(),
                        'adapter_key' => $this->catalog->adapterKey(),
                        'result' => 'inspection_failed',
                        'failure_class' => $exception->failureClass->value,
                    ]);

                    return;
                }

                $channelId = $this->client->participantChannelId((string) $participant->id);
                $participantStillDesired = (string) $conference->desired_state === 'open' && (string) $participant->desired_state === 'admitted';
                $participantInBridge = (bool) ($participantSummary['participant_channel_in_bridge'] ?? false);

                if ($participantStillDesired) {
                    $this->reconciliation->wakeTarget((string) $participant->tenant_id, 'conference_participant', (string) $participant->id, $this->participantDesiredGeneration($conference, 'admitted'), 0);
                }

                if ($participantStillDesired && $participantInBridge) {
                    $this->ingest($node, $epochId, 'inspection:channel-present:'.(string) $participant->id.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('channel_entered_bridge'), [
                        'runtime_node_id' => (string) $node->id,
                        'configuration_generation' => (int) $conference->configuration_generation,
                        'bridge_id' => $this->client->conferenceBridgeId((string) $conference->id),
                        'channel_id' => $channelId,
                        'occurred_at' => now()->toISOString(),
                    ]);
                }

                if ($participantStillDesired && ! $participantInBridge) {
                    Log::info('asterisk conference reconnect inspection woke recovery', [
                        'component' => 'asterisk-ari-events',
                        'runtime_family' => $this->catalog->runtimeFamily(),
                        'adapter_key' => $this->catalog->adapterKey(),
                        'result' => 'runtime_drift_detected',
                        'resource_type' => 'conference_participant',
                    ]);
                }

                if (((string) $participant->desired_state === 'removed' || (string) $conference->desired_state === 'closed') && ! (bool) ($participantSummary['participant_channel_exists'] ?? false)) {
                    $this->ingest($node, $epochId, 'inspection:channel-absent:'.(string) $participant->id.':'.(int) $conference->configuration_generation.':'.$inspectionRun, $this->catalog->eventType('channel_destroyed'), [
                        'runtime_node_id' => (string) $node->id,
                        'configuration_generation' => (int) $conference->configuration_generation,
                        'bridge_id' => $this->client->conferenceBridgeId((string) $conference->id),
                        'channel_id' => $channelId,
                        'occurred_at' => now()->toISOString(),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function wakeConferenceRecoveryFromAriEvent(object $node, string $ariEventType, array $payload): void
    {
        if (! in_array($ariEventType, ['BridgeDestroyed', 'ChannelLeftBridge', 'ChannelDestroyed', 'StasisEnd'], true)) {
            return;
        }

        $bridgeId = is_string($payload['bridge_id'] ?? null) ? $payload['bridge_id'] : null;
        $conferenceId = $this->suffixForPrefix($bridgeId, (string) config('asterisk_ari.conference.bridge_id_prefix', 'utcp-conf-'));
        if ($conferenceId !== null && $ariEventType === 'BridgeDestroyed') {
            $conference = $this->activeBoundConference((string) $node->tenant_id, (string) $node->id, $conferenceId);
            if ($conference !== null && (string) $conference->desired_state === 'open') {
                $this->reconciliation->wakeTarget((string) $conference->tenant_id, 'conference', (string) $conference->id, (int) $conference->configuration_generation, 0);
                Log::info('asterisk conference event woke recovery', [
                    'component' => 'asterisk-ari-events',
                    'runtime_family' => $this->catalog->runtimeFamily(),
                    'adapter_key' => $this->catalog->adapterKey(),
                    'result' => 'runtime_drift_detected',
                    'resource_type' => 'conference',
                ]);
            }
        }

        $channelId = is_string($payload['channel_id'] ?? null) ? $payload['channel_id'] : null;
        $participantId = $this->suffixForPrefix($channelId, (string) config('asterisk_ari.conference.participant_channel_id_prefix', 'utcp-part-'));
        if ($participantId === null) {
            return;
        }

        $participant = DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conference_participants.conference_id')
            ->where('conference_participants.id', $participantId)
            ->where('conference_participants.tenant_id', (string) $node->tenant_id)
            ->where('conferences.tenant_id', (string) $node->tenant_id)
            ->where('conference_runtime_bindings.tenant_id', (string) $node->tenant_id)
            ->where('conference_runtime_bindings.runtime_node_id', (string) $node->id)
            ->where('conference_runtime_bindings.status', 'active')
            ->select([
                'conference_participants.id',
                'conference_participants.tenant_id',
                'conference_participants.desired_state',
                'conferences.desired_state as conference_desired_state',
                'conferences.configuration_generation',
            ])
            ->first();

        if ($participant === null || (string) $participant->conference_desired_state !== 'open' || (string) $participant->desired_state !== 'admitted') {
            return;
        }

        $this->reconciliation->wakeTarget((string) $participant->tenant_id, 'conference_participant', (string) $participant->id, $this->participantDesiredGeneration($participant, 'admitted'), 0);
        Log::info('asterisk conference event woke recovery', [
            'component' => 'asterisk-ari-events',
            'runtime_family' => $this->catalog->runtimeFamily(),
            'adapter_key' => $this->catalog->adapterKey(),
            'result' => 'runtime_drift_detected',
            'resource_type' => 'conference_participant',
        ]);
    }

    private function activeBoundConference(string $tenantId, string $runtimeNodeId, string $conferenceId): ?object
    {
        return DB::table('conferences')
            ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conferences.id')
            ->where('conferences.id', $conferenceId)
            ->where('conferences.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.runtime_node_id', $runtimeNodeId)
            ->where('conference_runtime_bindings.status', 'active')
            ->select('conferences.*')
            ->first();
    }

    private function participantDesiredGeneration(object $conference, string $desiredState): int
    {
        $conferenceGeneration = max(1, (int) ($conference->configuration_generation ?? 1));

        return ($conferenceGeneration * 2) + ($desiredState === 'removed' ? 1 : 0);
    }

    private function teardownConnection(string $nodeId, bool $releaseLease): void
    {
        $conn = $this->connections[$nodeId] ?? null;
        if ($conn === null) {
            return;
        }

        $this->receipts->closeEpoch($conn['epoch_id'], $conn['worker_id']);
        $this->client->closeWebSocket($conn['stream']);
        if ($releaseLease) {
            $this->leases->release($conn['lease_id'], $conn['worker_id'], $conn['fencing_token']);
        }

        unset($this->connections[$nodeId]);
    }

    private function ingestFailure(object $node, string $epochId, string $fencingToken, AsteriskAriException $exception): void
    {
        $eventType = $exception->failureClass->value === 'authentication_failed'
            ? $this->catalog->eventType('authentication_failed')
            : $this->catalog->eventType('connection_closed');
        $this->ingest($node, $epochId, 'failure:'.$fencingToken.':'.$exception->failureCode, $eventType, [
            'runtime_node_id' => (string) $node->id,
            'configuration_generation' => (int) $node->configuration_version,
            'failure_class' => $exception->failureClass->value,
            'failure_code' => $exception->failureCode,
            'occurred_at' => now()->toISOString(),
        ]);
        Log::warning('asterisk ari listener observed failure', [
            'component' => 'asterisk-ari-events',
            'runtime_family' => $this->catalog->runtimeFamily(),
            'adapter_key' => $this->catalog->adapterKey(),
            'result' => 'failure_observed',
            'failure_class' => $exception->failureClass->value,
        ]);
    }

    private function shouldAttempt(string $nodeId): bool
    {
        return $this->backoff->shouldAttempt($nodeId, microtime(true));
    }

    private function recordFailureBackoff(string $tenantId, string $nodeId, int $credentialVersion, int $configurationVersion): void
    {
        try {
            $profile = $this->profiles->requiredProfile($tenantId, $nodeId);
        } catch (AsteriskAriException) {
            $this->backoff->clear($nodeId);

            return;
        }

        $this->backoff->recordFailure(
            $nodeId,
            microtime(true),
            (int) $profile['reconnect_min_delay_ms'],
            (int) $profile['reconnect_max_delay_ms'],
            $credentialVersion,
            $configurationVersion,
        );
    }

    private function clearBackoff(string $nodeId): void
    {
        $this->backoff->clear($nodeId);
    }

    private function catalogEventKey(string $ariEventType): string
    {
        return match ($ariEventType) {
            'BridgeCreated' => 'bridge_created',
            'BridgeDestroyed' => 'bridge_destroyed',
            'ChannelEnteredBridge' => 'channel_entered_bridge',
            'ChannelLeftBridge' => 'channel_left_bridge',
            'ChannelDestroyed' => 'channel_destroyed',
            'StasisStart' => 'stasis_start',
            'StasisEnd' => 'stasis_end',
            default => 'unknown_event_observed',
        };
    }

    private function suffixForPrefix(?string $value, string $prefix): ?string
    {
        if ($value === null || ! str_starts_with($value, $prefix)) {
            return null;
        }

        $suffix = mb_substr($value, mb_strlen($prefix));
        $suffix = preg_replace('/;\d+$/', '', $suffix) ?? $suffix;

        return $suffix !== '' ? $suffix : null;
    }

    private function currentCredentialVersion(string $nodeId): ?int
    {
        $version = DB::table('runtime_node_credentials')
            ->where('runtime_node_id', $nodeId)
            ->where('credential_type', $this->catalog->credentialType())
            ->where('status', 'active')
            ->orderByDesc('version')
            ->value('version');

        return $version === null ? null : (int) $version;
    }

    /**
     * @return list<object>
     */
    private function eligibleNodes(int $batchSize): array
    {
        return DB::table('runtime_nodes')
            ->where('adapter_key', $this->catalog->adapterKey())
            ->whereIn('desired_state', ['active', 'draining'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('asterisk_ari_profiles')
                    ->whereColumn('asterisk_ari_profiles.runtime_node_id', 'runtime_nodes.id');
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingest(object $node, string $epochId, string $externalKey, string $eventType, array $payload): void
    {
        $this->receipts->ingest(
            (string) $node->tenant_id,
            (string) $node->id,
            $this->catalog->adapterKey(),
            $epochId,
            $externalKey,
            $eventType,
            1,
            $payload,
        );
    }
}
