<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FreeSwitchEslEventListener
{
    /** @var list<string> */
    private const EVENTS = [
        'CHANNEL_CREATE',
        'CHANNEL_ANSWER',
        'CHANNEL_HOLD',
        'CHANNEL_UNHOLD',
        'CHANNEL_BRIDGE',
        'CHANNEL_UNBRIDGE',
        'CHANNEL_HANGUP_COMPLETE',
        'DTMF',
        'PLAYBACK_START',
        'PLAYBACK_STOP',
    ];

    public function __construct(
        private readonly FreeSwitchCatalog $catalog,
        private readonly RuntimeEventReceiptRepository $receipts,
        private readonly RuntimeListenerLeaseRepository $leases,
        private readonly ?FreeSwitchEslEventTransport $transport = null,
    ) {}

    /** @var array<string,array{stream:resource,lease_id:string,fencing_token:string,epoch_id:string,tenant_id:string,owner:string,configuration_version:int}> */
    private array $connections = [];

    public function workOnce(string $workerId, int $batchSize = 5): int
    {
        $transport = $this->transport ?? app(FreeSwitchEslEventTransport::class);
        $nodes = DB::table('runtime_nodes')
            ->where('adapter_key', $this->catalog->adapterKey())
            ->whereIn('desired_state', ['active', 'draining'])
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        $eligible = [];
        foreach ($nodes as $node) {
            $eligible[(string) $node->id] = $node;
        }
        foreach (array_keys($this->connections) as $nodeId) {
            if (! isset($eligible[$nodeId]) || ! $this->leases->renew($this->connections[$nodeId]['lease_id'], $workerId, $this->connections[$nodeId]['fencing_token'], 45)) {
                $this->closeActiveConnection($nodeId, $workerId, $transport);
            }
        }
        $processed = 0;
        foreach ($eligible as $nodeId => $node) {
            if (! isset($this->connections[$nodeId])) {
                $lease = $this->leases->claim((string) $node->tenant_id, $nodeId, 'freeswitch-esl-events', $workerId, 45);
                if ($lease === null) {
                    continue;
                }
                try {
                    $epochId = $this->receipts->openEpoch((string) $node->tenant_id, $nodeId, $this->catalog->adapterKey(), $workerId);
                    $stream = $transport->openEventStream((string) $node->tenant_id, $nodeId, $this->subscriptionCommand());
                    $this->connections[$nodeId] = [
                        'stream' => $stream,
                        'lease_id' => (string) $lease->id,
                        'fencing_token' => (string) $lease->fencing_token,
                        'epoch_id' => $epochId,
                        'tenant_id' => (string) $node->tenant_id,
                        'owner' => $workerId,
                        'configuration_version' => (int) $node->configuration_version,
                    ];
                    $this->ingestReadiness((string) $node->tenant_id, $nodeId, $epochId, (int) $node->configuration_version, (string) $lease->fencing_token);
                } catch (Throwable) {
                    $this->leases->release((string) $lease->id, $workerId, (string) $lease->fencing_token);

                    continue;
                }
            }
            try {
                $connection = $this->connections[$nodeId];
                $currentConfigurationVersion = (int) $node->configuration_version;
                if ($connection['configuration_version'] < $currentConfigurationVersion) {
                    try {
                        $this->ingestReadiness(
                            (string) $node->tenant_id,
                            $nodeId,
                            $connection['epoch_id'],
                            $currentConfigurationVersion,
                            $connection['fencing_token'],
                        );
                        $this->connections[$nodeId]['configuration_version'] = $currentConfigurationVersion;
                    } catch (Throwable) {
                        // Keep a healthy ESL connection alive while the canonical receipt can retry.
                    }
                }
                $frame = FreeSwitchEslProtocol::readFrame($this->connections[$nodeId]['stream']);
                $event = $this->eventFromFrame($frame);
                if ($event !== []) {
                    $this->ingestEvent((string) $node->tenant_id, $nodeId, $this->connections[$nodeId]['epoch_id'], $event);
                }
                $processed++;
            } catch (Throwable) {
                $this->closeActiveConnection($nodeId, $workerId, $transport);
            }
        }

        return $processed;
    }

    public function subscriptionCommand(): string
    {
        return 'event plain '.implode(' ', self::EVENTS);
    }

    /** @return array{lease_id:string, fencing_token:string, epoch_id:string} */
    public function openConnection(string $tenantId, string $runtimeNodeId, string $owner, int $leaseSeconds = 45): array
    {
        $lease = $this->leases->claim($tenantId, $runtimeNodeId, 'freeswitch-esl-events', $owner, $leaseSeconds);
        if ($lease === null) {
            throw new \RuntimeException('FreeSWITCH ESL event listener lease is unavailable.');
        }
        $this->receipts->closeSupersededOwnerEpochs($runtimeNodeId, $owner);
        $epoch = $this->receipts->openEpoch($tenantId, $runtimeNodeId, $this->catalog->adapterKey(), $owner);

        return ['lease_id' => (string) $lease->id, 'fencing_token' => (string) $lease->fencing_token, 'epoch_id' => $epoch];
    }

    /** @param array<string,mixed> $event @return array{status:string,id:string} */
    public function ingestEvent(string $tenantId, string $runtimeNodeId, string $epochId, array $event): array
    {
        $eventType = is_string($event['Event-Name'] ?? null) ? trim($event['Event-Name']) : '';
        if ($eventType === '') {
            throw new \InvalidArgumentException('FreeSWITCH ESL event is missing Event-Name.');
        }
        $sequence = is_string($event['Event-Sequence'] ?? null) ? trim($event['Event-Sequence']) : null;
        $channel = is_string($event['Unique-ID'] ?? null) ? trim($event['Unique-ID']) : null;
        $externalKey = $sequence !== null && $sequence !== '' ? 'sequence:'.$sequence : ($eventType.':'.($channel ?? 'event').':'.sha1(json_encode($event, JSON_THROW_ON_ERROR)));
        $event['occurred_at'] ??= now()->toISOString();

        return $this->receipts->ingest($tenantId, $runtimeNodeId, $this->catalog->adapterKey(), $epochId, $externalKey, $eventType, 1, $event);
    }

    public function closeConnection(string $epochId, string $owner, string $leaseId, string $fencingToken): void
    {
        $this->receipts->closeEpoch($epochId, $owner);
        $this->leases->release($leaseId, $owner, $fencingToken);
    }

    /** @return array<string,string> */
    private function eventFromFrame(string $frame): array
    {
        $parsed = FreeSwitchEslProtocol::parseFrame($frame);
        $event = [];
        foreach ($parsed['headers'] as $key => $value) {
            if (! in_array(strtolower($key), ['content-length', 'content-type'], true)) {
                $event[$key] = $value;
            }
        }
        foreach (preg_split("/\r?\n/", $parsed['body']) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $event[trim($key)] = trim($value);
        }

        return $event;
    }

    private function ingestReadiness(string $tenantId, string $nodeId, string $epochId, int $configurationVersion, string $fencingToken): void
    {
        $this->receipts->ingest($tenantId, $nodeId, $this->catalog->adapterKey(), $epochId, 'readiness:'.$configurationVersion.':'.$fencingToken, 'runtime.readiness.observed', 1, [
            'observed_state' => 'ready',
            'configuration_generation' => $configurationVersion,
            'occurred_at' => now()->toISOString(),
        ]);
    }

    private function closeActiveConnection(string $nodeId, string $owner, FreeSwitchEslEventTransport $transport): void
    {
        $connection = $this->connections[$nodeId] ?? null;
        if ($connection === null) {
            return;
        }
        $transport->closeEventStream($connection['stream']);
        $this->closeConnection($connection['epoch_id'], $owner, $connection['lease_id'], $connection['fencing_token']);
        unset($this->connections[$nodeId]);
    }
}
