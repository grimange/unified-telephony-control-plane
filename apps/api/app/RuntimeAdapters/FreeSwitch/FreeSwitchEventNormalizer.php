<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\RuntimeEngine\Events\EventNormalizer;
use App\TelephonyDomain\MediaReference;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FreeSwitchEventNormalizer implements EventNormalizer
{
    public function __construct(
        private readonly FreeSwitchCatalog $catalog,
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
        if ($this->eventType === 'runtime.readiness.observed') {
            return [[
                'observation_type' => 'runtime.readiness.observed',
                'observation_version' => 1,
                'subject_type' => 'runtime_node',
                'subject_id' => (string) $receipt->runtime_node_id,
                'observed_state' => $this->string($payload, 'observed_state') ?? 'ready',
                'configuration_version' => isset($payload['configuration_generation']) ? (int) $payload['configuration_generation'] : null,
                'observed_at' => $this->observedAt($payload),
                'payload' => [
                    'adapter_key' => $this->adapterKey(),
                    'runtime_family' => $this->catalog->runtimeFamily(),
                    'event_type' => $this->eventType,
                ],
            ]];
        }

        $channel = $this->string($payload, 'Unique-ID');
        if ($channel === null && $this->eventType !== 'CHANNEL_CREATE') {
            throw new InvalidArgumentException('FreeSWITCH mapped event is missing Unique-ID.');
        }

        if ($this->eventType === 'CHANNEL_CREATE') {
            if ($this->string($payload, 'Caller-Direction') !== 'inbound') {
                return [];
            }
        }

        if ($this->eventType === 'CHANNEL_BRIDGE' || $this->eventType === 'CHANNEL_UNBRIDGE') {
            $peer = $this->string($payload, 'Other-Leg-Unique-ID');
            if ($channel === null || $peer === null) {
                throw new InvalidArgumentException('FreeSWITCH bridge event is missing a channel pair.');
            }

            return [$this->bridgeObservation($receipt, $channel, $peer)];
        }

        if ($channel === null) {
            throw new InvalidArgumentException('FreeSWITCH mapped event is missing Unique-ID.');
        }

        $type = match ($this->eventType) {
            'CHANNEL_CREATE' => 'call.leg.offered',
            'CHANNEL_ANSWER' => 'call.leg.answered',
            'CHANNEL_HOLD' => 'call.leg.held',
            'CHANNEL_UNHOLD' => 'call.leg.resumed',
            'CHANNEL_HANGUP_COMPLETE' => 'call.leg.terminated',
            'DTMF' => 'call.leg.dtmf_received',
            'PLAYBACK_START' => 'call.leg.media_started',
            'PLAYBACK_STOP' => 'call.leg.media_stopped',
            default => throw new InvalidArgumentException('Unsupported FreeSWITCH event type: '.$this->eventType),
        };

        $leg = DB::table('call_legs')
            ->where('tenant_id', (string) $receipt->tenant_id)
            ->where('runtime_node_id', (string) $receipt->runtime_node_id)
            ->where('runtime_channel_id', $channel)
            ->first();
        $safe = [
            'runtime_node_id' => (string) $receipt->runtime_node_id,
            'runtime_channel_id' => $channel,
        ];
        if ($type === 'call.leg.offered') {
            $this->copy($payload, 'Caller-Caller-ID-Number', 'remote_identity', $safe);
            $this->copy($payload, 'Caller-Destination-Number', 'called_address', $safe);
            $called = $this->string($payload, 'variable_utcp_called_address');
            if ($called !== null && preg_match('/^utcp-in-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $called) === 1) {
                $safe['called_address'] = $called;
            }
            foreach ([
                'ingress_external_trunk_id' => 'variable_sip_h_X-UTCP-Ingress-External-Trunk-ID',
                'ingress_telephony_address_id' => 'variable_sip_h_X-UTCP-Ingress-Telephony-Address-ID',
                'ingress_trunk_endpoint_id' => 'variable_sip_h_X-UTCP-Ingress-Trunk-Endpoint-ID',
                'ingress_runtime_node_id' => 'variable_sip_h_X-UTCP-Ingress-Runtime-Node-ID',
            ] as $target => $source) {
                $value = $this->string($payload, $source) ?? $this->string($payload, 'variable_utcp_'.$target);
                if ($value !== null && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) === 1) {
                    $safe[$target] = strtolower($value);
                }
            }
        }
        if ($type === 'call.leg.terminated') {
            $this->copy($payload, 'Hangup-Cause', 'termination_reason', $safe);
        }
        if ($type === 'call.leg.dtmf_received') {
            $this->copy($payload, 'DTMF-Digit', 'digit', $safe);
        }
        if (in_array($type, ['call.leg.media_started', 'call.leg.media_stopped'], true)) {
            $path = $this->string($payload, 'Playback-File-Path');
            $name = $this->string($payload, 'Playback-File-Name');
            if ($path === null && $name !== null) {
                $path = '/usr/share/freeswitch/sounds/'.$name;
            }
            $mediaRef = MediaReference::canonicalFromProviderReference(
                $path
                    ?? $this->string($payload, 'media_ref'),
            );
            if ($mediaRef === null) {
                throw new InvalidArgumentException('FreeSWITCH playback event is missing a resolvable media reference.');
            }
            $safe['media_ref'] = $mediaRef;
        }

        return [[
            'observation_type' => $type,
            'observation_version' => 1,
            'subject_type' => 'call_leg',
            'subject_id' => $leg === null ? 'runtime:'.$channel : (string) $leg->id,
            'observed_state' => $type === 'call.leg.offered' ? 'offered' : 'observed',
            'observed_at' => $this->observedAt($payload),
            'payload' => $safe,
        ]];
    }

    /** @return array<string,mixed> */
    private function bridgeObservation(object $receipt, string $channel, string $peer): array
    {
        $channels = [$channel, $peer];
        $legIds = DB::table('call_legs')
            ->where('tenant_id', (string) $receipt->tenant_id)
            ->where('runtime_node_id', (string) $receipt->runtime_node_id)
            ->whereIn('runtime_channel_id', $channels)
            ->orderByRaw('CASE runtime_channel_id WHEN ? THEN 0 ELSE 1 END', [$channel])
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [
            'observation_type' => $this->eventType === 'CHANNEL_BRIDGE' ? 'call.legs.bridged' : 'call.legs.unbridged',
            'observation_version' => 1,
            'subject_type' => 'call_leg',
            'subject_id' => $legIds[0] ?? 'runtime:'.$channel,
            'observed_state' => 'observed',
            'observed_at' => now(),
            'payload' => [
                'runtime_node_id' => (string) $receipt->runtime_node_id,
                'runtime_channel_id' => $channel,
                'runtime_channel_ids' => $channels,
                'leg_ids' => count($legIds) === 2 ? $legIds : [],
            ],
        ];
    }

    private function observedAt(array $payload): mixed
    {
        return is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now();
    }

    private function string(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '' ? trim($payload[$key]) : null;
    }

    /** @param array<string,mixed> $safe */
    private function copy(array $payload, string $source, string $target, array &$safe): void
    {
        $value = $this->string($payload, $source);
        if ($value !== null) {
            $safe[$target] = $value;
        }
    }
}
