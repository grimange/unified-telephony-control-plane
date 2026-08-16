<?php

namespace App\RuntimeAdapters\Asterisk;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AsteriskConferenceParticipantBinder
{
    public function __construct(private readonly AsteriskAriClient $client) {}

    /**
     * Bind and bridge an inbound signaling Stasis channel for self admission.
     *
     * @param  array{channel_id:string, application_args:list<string>}  $event
     */
    public function bind(object $node, array $event): AsteriskConferenceParticipantBindResult
    {
        $channelId = is_string($event['channel_id'] ?? null) ? trim($event['channel_id']) : '';
        $reference = $this->admissionReference($event['application_args'] ?? null);
        if ($channelId === '' || $reference === null) {
            return AsteriskConferenceParticipantBindResult::TERMINAL;
        }

        try {
            return DB::transaction(function () use ($node, $channelId, $reference): AsteriskConferenceParticipantBindResult {
                $participantId = substr($reference, 5);
                $participant = DB::table('conference_participants')
                    ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
                    ->join('conference_runtime_bindings', function ($join): void {
                        $join->on('conference_runtime_bindings.conference_id', '=', 'conferences.id')
                            ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                            ->where('conference_runtime_bindings.status', 'active');
                    })
                    ->join('runtime_nodes', function ($join): void {
                        $join->on('runtime_nodes.id', '=', 'conference_runtime_bindings.runtime_node_id')
                            ->whereColumn('runtime_nodes.tenant_id', 'conference_runtime_bindings.tenant_id');
                    })
                    ->join('telephony_sessions', 'telephony_sessions.id', '=', 'conference_participants.telephony_session_id')
                    ->where('conference_participants.id', $participantId)
                    ->where('conference_participants.tenant_id', (string) $node->tenant_id)
                    ->where('conference_participants.desired_state', 'admitted')
                    ->where('conference_participants.admission_reason', 'self_admission')
                    ->where('conferences.desired_state', 'open')
                    ->whereColumn('conference_runtime_bindings.runtime_node_id', 'conferences.runtime_node_id')
                    ->where('conference_runtime_bindings.runtime_node_id', (string) $node->id)
                    ->where('runtime_nodes.desired_state', 'active')
                    ->where('telephony_sessions.status', 'active')
                    ->where('telephony_sessions.expires_at', '>', now())
                    ->select([
                        'conference_participants.*',
                        'conferences.id as conference_id',
                        'conferences.configuration_generation',
                        'runtime_nodes.observed_state',
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($participant === null) {
                    return AsteriskConferenceParticipantBindResult::TERMINAL;
                }

                if ((string) $participant->observed_state !== 'ready') {
                    return AsteriskConferenceParticipantBindResult::RETRYABLE;
                }

                $existingChannel = is_string($participant->runtime_channel_id ?? null) ? trim($participant->runtime_channel_id) : '';
                if ($existingChannel !== '' && $existingChannel !== $channelId) {
                    return AsteriskConferenceParticipantBindResult::TERMINAL;
                }

                if ($existingChannel !== '') {
                    return AsteriskConferenceParticipantBindResult::ALREADY_BOUND;
                }

                $this->client->attachInboundParticipantChannel(
                    (string) $node->tenant_id,
                    (string) $node->id,
                    (string) $participant->conference_id,
                    (string) $participant->id,
                    $channelId,
                    (int) $participant->configuration_generation,
                );
                DB::table('conference_participants')->where('id', (string) $participant->id)->update([
                    'runtime_channel_id' => $channelId,
                    'runtime_channel_lost_at' => null,
                    'updated_at' => now(),
                ]);

                return AsteriskConferenceParticipantBindResult::BOUND;
            });
        } catch (AsteriskAriException $exception) {
            Log::warning('asterisk inbound conference participant binding deferred', [
                'component' => 'asterisk-ari-events',
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'result' => $exception->retryable ? 'retryable' : 'binding_failed',
                'failure_class' => $exception->failureClass->value,
            ]);

            return $exception->retryable
                ? AsteriskConferenceParticipantBindResult::RETRYABLE
                : AsteriskConferenceParticipantBindResult::TERMINAL;
        } catch (Throwable $exception) {
            Log::warning('asterisk inbound conference participant binding rejected', [
                'component' => 'asterisk-ari-events',
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'result' => 'binding_failed',
                'failure_class' => 'runtime_unavailable',
            ]);

            return AsteriskConferenceParticipantBindResult::TERMINAL;
        }
    }

    /**
     * Clear only the channel that currently owns the participant mapping.
     */
    public function clear(object $node, ?string $channelId): void
    {
        if ($channelId === null || trim($channelId) === '') {
            return;
        }

        DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->join('conference_runtime_bindings', function ($join): void {
                $join->on('conference_runtime_bindings.conference_id', '=', 'conferences.id')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->where('conference_participants.tenant_id', (string) $node->tenant_id)
            ->where('conference_runtime_bindings.runtime_node_id', (string) $node->id)
            ->where('conference_participants.runtime_channel_id', trim($channelId))
            ->update([
                'conference_participants.runtime_channel_id' => null,
                'conference_participants.runtime_channel_lost_at' => now(),
                'conference_participants.updated_at' => now(),
            ]);
    }

    private function admissionReference(mixed $args): ?string
    {
        $value = is_array($args) && is_string($args[0] ?? null) ? trim($args[0]) : '';
        if (! preg_match('/^conf-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value)) {
            return null;
        }

        return $value;
    }
}
