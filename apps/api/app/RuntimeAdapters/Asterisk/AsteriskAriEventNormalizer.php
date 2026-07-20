<?php

namespace App\RuntimeAdapters\Asterisk;

use App\RuntimeEngine\Events\EventNormalizer;
use Illuminate\Support\Facades\DB;

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
        if ($this->isConferenceEvent()) {
            return $this->normalizeConferenceEvent($receipt, $payload);
        }

        $state = match ($this->eventType) {
            $this->catalog->eventType('connection_opened'),
            $this->catalog->eventType('runtime_info_observed') => 'ready',
            $this->catalog->eventType('connection_closed') => 'unavailable',
            $this->catalog->eventType('event_listener_degraded') => 'events_degraded',
            $this->catalog->eventType('event_listener_recovered') => 'ready',
            $this->catalog->eventType('authentication_failed') => 'degraded',
            default => 'degraded',
        };

        $observationType = match ($this->eventType) {
            $this->catalog->eventType('connection_opened'),
            $this->catalog->eventType('connection_closed') => 'runtime.connection.observed',
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
            ],
        ]];
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

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeConferenceEvent(object $receipt, array $payload): array
    {
        $bridgeId = is_string($payload['bridge_id'] ?? null) ? $payload['bridge_id'] : null;
        $conferenceId = $this->suffixForPrefix($bridgeId, (string) config('asterisk_ari.conference.bridge_id_prefix', 'utcp-conf-'));
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

        $channelId = is_string($payload['channel_id'] ?? null) ? $payload['channel_id'] : null;
        $participantId = $this->suffixForPrefix($channelId, (string) config('asterisk_ari.conference.participant_channel_id_prefix', 'utcp-part-'));
        if ($participantId === null) {
            return [];
        }

        $participant = DB::table('conference_participants')
            ->where('id', $participantId)
            ->where('tenant_id', (string) $receipt->tenant_id)
            ->where('conference_id', (string) $conference->id)
            ->first();
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
