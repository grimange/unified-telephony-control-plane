<?php

namespace App\RuntimeAdapters\Asterisk;

use App\RuntimeEngine\Events\EventNormalizer;
use App\TelephonyDomain\MediaReference;
use App\TelephonyDomain\RuntimeChannelIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AsteriskAriEventNormalizer implements EventNormalizer
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
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
        if ($this->isConferenceEvent() && $this->hasConferenceOwnership($receipt, $payload)) {
            return $this->normalizeConferenceEvent($receipt, $payload);
        }

        $generic = $this->normalizeGenericCallEvent($receipt, $payload);
        if ($generic !== null) {
            return [$generic];
        }

        if ($this->isConferenceEvent()) {
            return [];
        }

        $state = match ($this->eventType) {
            $this->catalog->eventType('connection_opened'),
            $this->catalog->eventType('runtime_info_observed') => 'ready',
            $this->catalog->eventType('connection_closed') => 'unavailable',
            $this->catalog->eventType('event_listener_degraded') => 'events_degraded',
            $this->catalog->eventType('event_listener_recovered') => 'ready',
            $this->catalog->eventType('authentication_failed') => 'degraded',
            default => 'unknown',
        };

        $observationType = match ($this->eventType) {
            $this->catalog->eventType('connection_opened'),
            $this->catalog->eventType('connection_closed') => 'runtime.connection.observed',
            $this->catalog->eventType('authentication_failed') => 'runtime.connection.observed',
            $this->catalog->eventType('runtime_info_observed') => 'runtime.readiness.observed',
            $this->catalog->eventType('event_listener_degraded'),
            $this->catalog->eventType('event_listener_recovered') => 'runtime.event_stream.observed',
            default => 'runtime.capability.observed',
        };

        return [[
            'observation_type' => $observationType,
            'observation_version' => 1,
            'subject_type' => 'runtime_node',
            'subject_id' => (string) $receipt->runtime_node_id,
            'observed_state' => $state,
            'configuration_version' => isset($payload['configuration_generation']) ? (int) $payload['configuration_generation'] : null,
            'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
            'payload' => [
                'adapter_key' => $this->adapterKey(),
                'event_type' => $this->eventType,
                'runtime_family' => $this->catalog->runtimeFamily(),
                'failure_class' => is_string($payload['failure_class'] ?? null) ? $payload['failure_class'] : null,
                'ari_event_type' => is_string($payload['ari_event_type'] ?? null) ? $payload['ari_event_type'] : null,
                'capabilities' => $this->capabilities($payload),
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>|null
     */
    private function capabilities(array $payload): ?array
    {
        if (! array_key_exists('capabilities', $payload)) {
            return null;
        }

        $capabilities = array_filter($payload['capabilities'] ?? [], 'is_string');
        $capabilities = array_values(array_unique(array_filter($capabilities, static fn (string $value): bool => $value !== '')));
        sort($capabilities);

        return $capabilities;
    }

    private function isConferenceEvent(): bool
    {
        return in_array($this->eventType, [
            $this->catalog->eventType('bridge_created'),
            $this->catalog->eventType('bridge_destroyed'),
            $this->catalog->eventType('channel_entered_bridge'),
            $this->catalog->eventType('channel_left_bridge'),
            $this->catalog->eventType('channel_destroyed'),
            $this->catalog->eventType('stasis_end'),
        ], true);
    }

    private function hasConferenceOwnership(object $receipt, array $payload): bool
    {
        if (! isset($receipt->tenant_id, $receipt->runtime_node_id)) {
            return false;
        }

        $channelId = is_string($payload['channel_id'] ?? null) ? $payload['channel_id'] : null;
        if ($channelId !== null && AsteriskConferenceChannelOwnership::owns(
            (string) $receipt->tenant_id,
            (string) $receipt->runtime_node_id,
            $channelId,
        )) {
            return true;
        }

        $bridgeId = is_string($payload['bridge_id'] ?? null) ? $payload['bridge_id'] : null;

        return $bridgeId !== null && $this->suffixForPrefix($bridgeId, (string) config('asterisk_ari.conference.bridge_id_prefix', 'utcp-conf-')) !== null;
    }

    /** @return array<string, mixed>|null */
    private function normalizeGenericCallEvent(object $receipt, array $payload): ?array
    {
        if (! isset($receipt->tenant_id, $receipt->runtime_node_id)) {
            return null;
        }

        $channelId = is_string($payload['channel_id'] ?? null) ? trim($payload['channel_id']) : '';
        if ($channelId === '' || $this->conferenceChannel($receipt, $channelId)) {
            return null;
        }

        $type = match ($this->eventType) {
            $this->catalog->eventType('stasis_start') => 'call.leg.offered',
            $this->catalog->eventType('channel_state_change') => match (mb_strtolower((string) ($payload['channel_state'] ?? ''))) {
                'ring', 'ringing' => 'call.leg.ringing',
                'earlymedia', 'early_media' => 'call.leg.early_media',
                'up', 'answered' => 'call.leg.answered',
                default => null,
            },
            $this->catalog->eventType('channel_destroyed') => 'call.leg.terminated',
            $this->catalog->eventType('stasis_end') => null,
            $this->catalog->eventType('channel_dtmf_received') => 'call.leg.dtmf_received',
            $this->catalog->eventType('channel_entered_bridge') => 'call.legs.bridged',
            $this->catalog->eventType('channel_left_bridge') => 'call.legs.unbridged',
            $this->catalog->eventType('playback_started') => 'call.leg.media_started',
            $this->catalog->eventType('playback_finished') => 'call.leg.media_stopped',
            $this->catalog->eventType('recording_started') => 'call.leg.recording_started',
            $this->catalog->eventType('recording_finished') => 'call.leg.recording_stopped',
            $this->catalog->eventType('channel_hold') => 'call.leg.held',
            $this->catalog->eventType('channel_unhold') => 'call.leg.resumed',
            $this->catalog->eventType('channel_mute') => 'call.leg.muted',
            $this->catalog->eventType('channel_unmute') => 'call.leg.unmuted',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        $leg = DB::table('call_legs')->where('tenant_id', (string) $receipt->tenant_id)->where('runtime_node_id', (string) $receipt->runtime_node_id)->where('runtime_channel_id', $channelId)->first();
        if ($leg === null && $type === 'call.leg.offered') {
            $correlatedLegId = RuntimeChannelIdentity::callLegId($channelId);
            if ($correlatedLegId !== null) {
                $leg = DB::table('call_legs')
                    ->where('tenant_id', (string) $receipt->tenant_id)
                    ->where('id', $correlatedLegId)
                    ->where('runtime_node_id', (string) $receipt->runtime_node_id)
                    ->whereNull('runtime_channel_id')
                    ->where('direction', 'outbound')
                    ->first();
            }
        }
        $channels = is_array($payload['bridge_channel_ids'] ?? null) ? array_values(array_filter($payload['bridge_channel_ids'], 'is_string')) : [$channelId];
        $safe = [
            'runtime_node_id' => (string) $receipt->runtime_node_id,
            'runtime_channel_id' => $channelId,
        ];
        if (is_string($payload['ari_event_type'] ?? null)) {
            $safe['ari_event_type'] = $payload['ari_event_type'];
        }
        if (is_string($payload['remote_identity'] ?? null) && trim($payload['remote_identity']) !== '') {
            $safe['remote_identity'] = trim($payload['remote_identity']);
        }
        if ($type === 'call.leg.offered') {
            $args = is_array($payload['application_args'] ?? null) ? array_values(array_filter($payload['application_args'], 'is_string')) : [];
            if (isset($args[0]) && preg_match('/^utcp-in-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $args[0]) === 1) {
                $safe['called_address'] = $args[0];
                foreach ([1 => 'ingress_external_trunk_id', 2 => 'ingress_telephony_address_id', 3 => 'ingress_trunk_endpoint_id', 4 => 'ingress_runtime_node_id'] as $index => $key) {
                    if (isset($args[$index]) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $args[$index]) === 1) {
                        $safe[$key] = strtolower($args[$index]);
                    }
                }
            }
        }
        if (is_string($payload['digit'] ?? null)) {
            $safe['digit'] = $payload['digit'];
        }
        if (isset($payload['duration_ms']) && is_int($payload['duration_ms'])) {
            $safe['duration_ms'] = $payload['duration_ms'];
        }
        if (in_array($type, ['call.leg.media_started', 'call.leg.media_stopped'], true)) {
            $mediaRef = MediaReference::canonicalFromProviderReference(is_string($payload['media_ref'] ?? null) ? $payload['media_ref'] : null);
            if ($mediaRef === null) {
                throw new InvalidArgumentException('Asterisk playback event is missing a resolvable media reference.');
            }
            $safe['media_ref'] = $mediaRef;
        }
        if (in_array($type, ['call.legs.bridged', 'call.legs.unbridged'], true)) {
            $safe['runtime_channel_ids'] = count($channels) === 2 ? $channels : [];
            if (count($channels) === 2) {
                $safe['leg_ids'] = DB::table('call_legs')
                    ->where('tenant_id', (string) $receipt->tenant_id)
                    ->where('runtime_node_id', (string) $receipt->runtime_node_id)
                    ->whereIn('runtime_channel_id', $channels)
                    ->orderBy('runtime_channel_id')
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();
            }
        }
        if ($type === 'call.leg.terminated') {
            foreach (['cause', 'cause_txt', 'tech_cause'] as $fact) {
                if (array_key_exists($fact, $payload) && $payload[$fact] !== null) {
                    $safe[$fact] = $payload[$fact];
                }
            }
        }

        return [
            'observation_type' => $type,
            'observation_version' => 1,
            'subject_type' => 'call_leg',
            'subject_id' => $leg === null ? 'runtime:'.$channelId : (string) $leg->id,
            'observed_state' => $type === 'call.leg.offered' ? 'offered' : 'observed',
            'configuration_version' => isset($payload['configuration_generation']) ? (int) $payload['configuration_generation'] : null,
            'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
            'payload' => $safe,
        ];
    }

    private function conferenceChannel(object $receipt, string $channelId): bool
    {
        return AsteriskConferenceChannelOwnership::owns(
            (string) $receipt->tenant_id,
            (string) $receipt->runtime_node_id,
            $channelId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeConferenceEvent(object $receipt, array $payload): array
    {
        $bridgeId = is_string($payload['bridge_id'] ?? null) ? $payload['bridge_id'] : null;
        $conferenceId = $this->suffixForPrefix($bridgeId, (string) config('asterisk_ari.conference.bridge_id_prefix', 'utcp-conf-'));
        $channelId = is_string($payload['channel_id'] ?? null) ? $payload['channel_id'] : null;
        $participantId = $this->suffixForPrefix($channelId, (string) config('asterisk_ari.conference.participant_channel_id_prefix', 'utcp-part-'));
        $participant = null;

        if ($conferenceId === null && $channelId !== null) {
            $participantQuery = DB::table('conference_participants')
                ->where('tenant_id', (string) $receipt->tenant_id);
            if ($participantId !== null) {
                $participantQuery->where('id', $participantId);
            } else {
                $participantQuery->where('runtime_channel_id', $channelId);
            }
            $participant = $participantQuery->first();
            $conferenceId = $participant !== null ? (string) $participant->conference_id : null;
        }

        if ($conferenceId === null) {
            return [];
        }

        $conference = DB::table('conferences')
            ->join('conference_runtime_bindings', 'conference_runtime_bindings.conference_id', '=', 'conferences.id')
            ->where('conferences.id', $conferenceId)
            ->where('conferences.tenant_id', (string) $receipt->tenant_id)
            ->where('conference_runtime_bindings.tenant_id', (string) $receipt->tenant_id)
            ->where('conference_runtime_bindings.runtime_node_id', (string) $receipt->runtime_node_id)
            ->where('conference_runtime_bindings.status', 'active')
            ->select('conferences.*')
            ->first();
        if ($conference === null) {
            return [];
        }

        if ($this->eventType === $this->catalog->eventType('bridge_created')) {
            if ((string) $conference->desired_state !== 'open') {
                return [];
            }

            return [[
                'observation_type' => 'conference.lifecycle.observed',
                'observation_version' => 1,
                'subject_type' => 'conference',
                'subject_id' => (string) $conference->id,
                'observed_state' => 'ready',
                'configuration_version' => (int) $conference->configuration_generation,
                'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
                'payload' => $this->safePayload('conference.available'),
            ]];
        }

        if ($this->eventType === $this->catalog->eventType('bridge_destroyed')) {
            if ((string) $conference->desired_state !== 'closed') {
                return [];
            }

            return [[
                'observation_type' => 'conference.lifecycle.observed',
                'observation_version' => 1,
                'subject_type' => 'conference',
                'subject_id' => (string) $conference->id,
                'observed_state' => 'closed',
                'configuration_version' => (int) $conference->configuration_generation,
                'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
                'payload' => $this->safePayload('conference.ended'),
            ]];
        }

        if ($participant === null) {
            if ($participantId === null || $channelId === null) {
                return [];
            }

            $participant = DB::table('conference_participants')
                ->where('id', $participantId)
                ->where('tenant_id', (string) $receipt->tenant_id)
                ->where('conference_id', (string) $conference->id)
                ->first();
        } elseif ((string) $participant->conference_id !== (string) $conference->id) {
            return [];
        }
        if ($participant === null) {
            return [];
        }

        $state = $this->eventType === $this->catalog->eventType('channel_entered_bridge') ? 'joined' : 'left';
        if ($state === 'joined' && ((string) $conference->desired_state !== 'open' || (string) $participant->desired_state !== 'admitted')) {
            return [];
        }
        if ($state === 'left' && (string) $participant->desired_state !== 'removed' && (string) $conference->desired_state !== 'closed') {
            return [];
        }

        $runtimeEvent = $state === 'joined' ? 'conference-participant.joined' : 'conference-participant.left';

        return [[
            'observation_type' => 'conference.participant.observed',
            'observation_version' => 1,
            'subject_type' => 'conference_participant',
            'subject_id' => (string) $participant->id,
            'observed_state' => $state,
            'configuration_version' => (int) $conference->configuration_generation,
            'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
            'payload' => array_merge($this->safePayload($runtimeEvent), [
                'conference_id' => (string) $conference->id,
                'telephony_session_id' => (string) $participant->telephony_session_id,
            ]),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function safePayload(string $runtimeEvent): array
    {
        return [
            'adapter_key' => $this->adapterKey(),
            'event_type' => $this->eventType,
            'runtime_event' => $runtimeEvent,
            'runtime_family' => $this->catalog->runtimeFamily(),
            'runtime_reference_present' => true,
        ];
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
}
