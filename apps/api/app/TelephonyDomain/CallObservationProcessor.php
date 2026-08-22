<?php

namespace App\TelephonyDomain;

use Illuminate\Support\Facades\DB;

final class CallObservationProcessor
{
    /** @var array<string, CallState> */
    private const STATE_OBSERVATIONS = [
        'call.leg.ringing' => CallState::Ringing,
        'call.leg.early_media' => CallState::EarlyMedia,
        'call.leg.answered' => CallState::Answered,
        'call.leg.held' => CallState::Held,
        'call.leg.resumed' => CallState::Answered,
        'call.leg.bridged' => CallState::Bridged,
    ];

    public function __construct(private readonly CallDomainService $calls) {}

    /** @param array<string, mixed> $observation */
    public function process(object $receipt, array $observation): void
    {
        if (! $this->isC6Observation($observation) || ! $this->isCurrentEpoch($receipt)) {
            return;
        }

        $type = (string) $observation['observation_type'];
        $payload = is_array($observation['payload'] ?? null) ? $observation['payload'] : [];
        $nodeId = (string) $receipt->runtime_node_id;
        $tenantId = (string) $receipt->tenant_id;

        if ($type === 'call.leg.offered') {
            $channelId = $this->channel($payload);
            if ($channelId === null) {
                return;
            }
            $subjectId = (string) ($observation['subject_id'] ?? '');
            if ($subjectId !== '' && ! str_starts_with($subjectId, 'runtime:')) {
                $legId = $this->resolveLegId($tenantId, $subjectId, $nodeId, $channelId);
                if ($legId !== null) {
                    $this->calls->bindObservedRuntimeChannel($tenantId, $legId, $nodeId, $channelId);
                    $this->catchUp($receipt, $legId, $channelId);

                    return;
                }
            }
            $adopted = $this->calls->adoptInboundLeg($nodeId, $channelId, $this->string($payload, 'remote_identity'), $this->string($payload, 'called_address'));
            if ($adopted['leg_id'] !== '') {
                $this->catchUp($receipt, $adopted['leg_id'], $channelId);
            }

            return;
        }

        if (in_array($type, ['call.leg.bridged', 'call.leg.unbridged', 'call.legs.bridged', 'call.legs.unbridged'], true)) {
            $legIds = $this->stringList($payload['leg_ids'] ?? null);
            $channels = $this->stringList($payload['runtime_channel_ids'] ?? null);
            $unbridge = in_array($type, ['call.leg.unbridged', 'call.legs.unbridged'], true);
            if ($this->calls->applyObservedBridge($tenantId, $legIds, $nodeId, $channels, $unbridge)) {
                $state = $unbridge ? CallState::Answered : CallState::Bridged;
                foreach ($legIds as $index => $legId) {
                    $this->calls->applyObservedLegTransition($tenantId, $legId, $nodeId, $channels[$index], $state);
                }
            }

            return;
        }

        $channelId = $this->channel($payload);
        $legId = $this->resolveLegId($tenantId, (string) ($observation['subject_id'] ?? ''), $nodeId, $channelId);
        if ($legId === null || $channelId === null) {
            return;
        }

        if ($type === 'call.leg.terminated' || $type === 'call.leg.failed') {
            $state = $type === 'call.leg.failed' ? CallState::Failed : CallState::Completed;
            $this->calls->terminalizeObservedLeg($tenantId, $legId, $nodeId, $channelId, $state, $this->string($payload, 'termination_reason') ?? ($type === 'call.leg.failed' ? 'runtime_lost' : 'requested'));

            return;
        }

        if (isset(self::STATE_OBSERVATIONS[$type])) {
            $this->calls->bindObservedRuntimeChannel($tenantId, $legId, $nodeId, $channelId);
            $this->calls->applyObservedLegTransition($tenantId, $legId, $nodeId, $channelId, self::STATE_OBSERVATIONS[$type]);

            return;
        }

        if ($type === 'call.leg.muted' || $type === 'call.leg.unmuted') {
            $this->calls->applyObservedMute($tenantId, $legId, $nodeId, $channelId, $type === 'call.leg.muted');
        }
    }

    /**
     * Re-evaluate stored facts for a newly identified channel. Application
     * time may follow adoption time; occurrence timestamps remain unchanged.
     */
    private function catchUp(object $receipt, string $legId, string $channelId): void
    {
        $rows = DB::table('runtime_observations')
            ->where('tenant_id', (string) $receipt->tenant_id)
            ->where('runtime_node_id', (string) $receipt->runtime_node_id)
            ->whereIn('observation_type', [
                'call.leg.ringing',
                'call.leg.early_media',
                'call.leg.answered',
                'call.leg.held',
                'call.leg.resumed',
                'call.leg.terminated',
                'call.leg.failed',
            ])
            ->orderBy('observed_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload) || ($payload['runtime_channel_id'] ?? null) !== $channelId) {
                continue;
            }

            $this->process((object) [
                'tenant_id' => $receipt->tenant_id,
                'runtime_node_id' => $receipt->runtime_node_id,
                'connection_epoch_id' => $row->source_connection_epoch,
            ], [
                'observation_type' => $row->observation_type,
                'subject_type' => $row->subject_type,
                'subject_id' => str_starts_with((string) $row->subject_id, 'runtime:') ? $legId : $row->subject_id,
                'observed_state' => $row->observed_state,
                'observed_at' => $row->observed_at,
                'payload' => $payload,
            ]);
        }
    }

    /** @param array<string, mixed> $observation */
    private function isC6Observation(array $observation): bool
    {
        return str_starts_with((string) ($observation['observation_type'] ?? ''), 'call.leg.')
            || str_starts_with((string) ($observation['observation_type'] ?? ''), 'call.legs.');
    }

    private function isCurrentEpoch(object $receipt): bool
    {
        $epoch = DB::table('runtime_event_connection_epochs')->where('id', (string) $receipt->connection_epoch_id)->first();

        return $epoch === null || (string) $epoch->status === 'open';
    }

    /** @param array<string, mixed> $payload */
    private function channel(array $payload): ?string
    {
        $channel = $payload['runtime_channel_id'] ?? null;

        return is_string($channel) && trim($channel) !== '' ? trim($channel) : null;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '' ? trim($payload[$key]) : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    private function resolveLegId(string $tenantId, string $subjectId, string $nodeId, ?string $channelId): ?string
    {
        $leg = null;
        if ($subjectId !== '' && ! str_starts_with($subjectId, 'runtime:')) {
            $leg = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('id', $subjectId)
                ->where(function ($query) use ($nodeId, $channelId): void {
                    $query->where(function ($nested) use ($nodeId, $channelId): void {
                        $nested->where('runtime_node_id', $nodeId)->where('runtime_channel_id', $channelId);
                    })->orWhere(function ($nested) use ($nodeId): void {
                        $nested->where('runtime_node_id', $nodeId)->whereNull('runtime_channel_id');
                    });
                })
                ->first();
        }
        if ($leg === null && $channelId !== null) {
            $leg = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $nodeId)
                ->where('runtime_channel_id', $channelId)
                ->first();
        }

        return $leg === null ? null : (string) $leg->id;
    }
}
