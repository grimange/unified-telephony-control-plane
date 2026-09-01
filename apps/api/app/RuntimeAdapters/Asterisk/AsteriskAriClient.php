<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\TelephonyDomain\MediaReference;
use App\TelephonyDomain\RuntimeChannelIdentity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class AsteriskAriClient
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly AsteriskAriProfileService $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $tenantId, string $runtimeNodeId): array
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $endpoint = $this->endpoint($runtimeNodeId, 'control', ['http', 'https']);
        $credential = $this->credential($runtimeNodeId);
        $url = rtrim($this->endpointUrl($endpoint, '/ari'), '/').'/asterisk/info';
        $headers = [
            'Authorization: Basic '.$this->basicToken($credential),
            'Accept: application/json',
            'User-Agent: utcp-asterisk-ari-t0',
        ];

        $response = $this->request('GET', $url, $headers, (int) $profile['request_timeout_ms']);
        $status = (int) $response['status'];
        if ($status === 401) {
            throw new AsteriskAriException(FailureClass::AuthenticationFailed, 'ari_authentication_failed', 'ARI authentication failed.');
        }
        if ($status === 403) {
            throw new AsteriskAriException(FailureClass::AuthorizationFailed, 'ari_authorization_failed', 'ARI authorization failed.');
        }
        if ($status === 404) {
            throw new AsteriskAriException(FailureClass::UnsupportedCapability, 'ari_info_unsupported', 'ARI information resource is unavailable.');
        }
        if ($status < 200 || $status >= 300) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_unavailable', 'ARI HTTP inspection did not return success.', true);
        }

        try {
            $body = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_invalid_json', 'ARI information response was not valid JSON.');
        }
        if (! is_array($body)) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_invalid_schema', 'ARI information response schema was invalid.');
        }

        return [
            'runtime_node_id' => $node->id,
            'asterisk_version' => $this->safeString($body['system']['version'] ?? $body['asterisk_id'] ?? 'unknown'),
            'system_name' => $this->safeString($body['system']['entity_id'] ?? 'unknown'),
            'configuration_generation' => (int) $node->configuration_version,
            'auth_generation' => (int) $credential->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureConferenceBridge(string $tenantId, string $runtimeNodeId, string $conferenceId, int $configurationGeneration): array
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $bridgeName = $this->conferenceBridgeName($conferenceId);
        $timeoutMs = (int) $profile['request_timeout_ms'];

        $existing = $this->getAriResource($runtimeNodeId, 'bridges/'.$bridgeId, $timeoutMs);
        if (is_array($existing)) {
            $this->assertOwnedBridge($existing, $bridgeId, $bridgeName);

            return [
                'runtime_node_id' => $node->id,
                'bridge_id' => $bridgeId,
                'configuration_generation' => $configurationGeneration,
                'already_existed' => true,
            ];
        }

        $this->ariRequest(
            $runtimeNodeId,
            'POST',
            'bridges',
            [
                'bridgeId' => $bridgeId,
                'type' => (string) config('asterisk_ari.conference.bridge_type', 'mixing'),
                'name' => $bridgeName,
            ],
            $timeoutMs,
            [200, 201, 204, 409],
        );

        return [
            'runtime_node_id' => $node->id,
            'bridge_id' => $bridgeId,
            'configuration_generation' => $configurationGeneration,
            'already_existed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function closeConferenceBridge(string $tenantId, string $runtimeNodeId, string $conferenceId, int $configurationGeneration): array
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);

        $this->ariRequest($runtimeNodeId, 'DELETE', 'bridges/'.$bridgeId, [], (int) $profile['request_timeout_ms'], [200, 202, 204, 404]);

        return [
            'runtime_node_id' => $node->id,
            'bridge_id' => $bridgeId,
            'configuration_generation' => $configurationGeneration,
            'absent' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureParticipantChannel(string $tenantId, string $runtimeNodeId, string $conferenceId, string $participantId, int $configurationGeneration): array
    {
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $channelId = $this->participantChannelId($participantId);
        $timeoutMs = (int) $profile['request_timeout_ms'];
        $originateTimeoutSeconds = max(60, min(3600, (int) config('asterisk_ari.conference.proof_originate_timeout_seconds', 3600)));

        $this->ensureConferenceBridge($tenantId, $runtimeNodeId, $conferenceId, $configurationGeneration);

        if (! is_array($this->getAriResource($runtimeNodeId, 'channels/'.$channelId, $timeoutMs))) {
            $this->ariRequest(
                $runtimeNodeId,
                'POST',
                'channels',
                [
                    'endpoint' => (string) config('asterisk_ari.conference.proof_endpoint'),
                    'app' => (string) $profile['application_name'],
                    'channelId' => $channelId,
                    'timeout' => (string) $originateTimeoutSeconds,
                    'callerId' => $this->participantChannelName($participantId),
                ],
                $timeoutMs,
                [200, 201, 202, 204, 409],
            );
        }

        $attached = false;
        $attempts = max(1, min(10, (int) config('asterisk_ari.conference.participant_attach_attempts', 8)));
        $delayMicroseconds = max(50000, min(500000, (int) config('asterisk_ari.conference.participant_attach_retry_microseconds', 200000)));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->ariRequest($runtimeNodeId, 'POST', 'bridges/'.$bridgeId.'/addChannel', ['channel' => $channelId], $timeoutMs, [200, 202, 204, 409]);
                $attached = true;
                break;
            } catch (AsteriskAriException $exception) {
                if ($exception->failureClass !== FailureClass::Conflict || $attempt === $attempts) {
                    throw $exception;
                }
                usleep($delayMicroseconds);
            }
        }

        if (! $attached || ! $this->participantAttachedToBridge($runtimeNodeId, $bridgeId, $channelId, $timeoutMs)) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_participant_attach_pending', 'ARI participant channel was not attached to the conference bridge yet.', true);
        }

        return [
            'runtime_node_id' => $runtimeNodeId,
            'bridge_id' => $bridgeId,
            'channel_id' => $channelId,
            'configuration_generation' => $configurationGeneration,
        ];
    }

    /**
     * Attach the real inbound signaling channel for a self-admitted participant.
     * The inbound channel is the participant runtime reference; no Local proof
     * channel is originated by this path.
     *
     * @return array<string, mixed>
     */
    public function attachInboundParticipantChannel(string $tenantId, string $runtimeNodeId, string $conferenceId, string $participantId, string $channelId, int $configurationGeneration): array
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $timeoutMs = (int) $profile['request_timeout_ms'];

        $this->ensureConferenceBridge($tenantId, $runtimeNodeId, $conferenceId, $configurationGeneration);
        $this->ariRequest(
            $runtimeNodeId,
            'POST',
            'bridges/'.$bridgeId.'/addChannel',
            ['channel' => $channelId],
            $timeoutMs,
            [200, 202, 204, 409],
        );

        if (! $this->participantAttachedToBridge($runtimeNodeId, $bridgeId, $channelId, $timeoutMs)) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_inbound_participant_attach_pending', 'Inbound participant channel was not attached to the conference bridge yet.', true);
        }

        return [
            'runtime_node_id' => $runtimeNodeId,
            'bridge_id' => $bridgeId,
            'participant_id' => $participantId,
            'channel_id' => $channelId,
            'configuration_generation' => $configurationGeneration,
        ];
    }

    public function inboundConferenceChannelExists(string $tenantId, string $runtimeNodeId, string $channelId): bool
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);

        return is_array($this->getAriResource($runtimeNodeId, 'channels/'.rawurlencode($channelId), (int) $profile['request_timeout_ms']));
    }

    /**
     * @return array<string, mixed>
     */
    public function removeParticipantChannel(string $tenantId, string $runtimeNodeId, string $conferenceId, string $participantId, int $configurationGeneration): array
    {
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $channelId = $this->participantRuntimeChannelId($participantId) ?? $this->participantChannelId($participantId);
        $peerChannelId = $this->participantRuntimeChannelId($participantId) === null ? $this->participantPeerChannelId($participantId) : null;
        $timeoutMs = (int) $profile['request_timeout_ms'];

        $this->ariRequest($runtimeNodeId, 'POST', 'bridges/'.$bridgeId.'/removeChannel', ['channel' => $channelId], $timeoutMs, [200, 202, 204, 404, 409, 422]);
        $this->ariRequest($runtimeNodeId, 'DELETE', 'channels/'.$channelId, [], $timeoutMs, [200, 202, 204, 404, 409]);
        if ($peerChannelId !== null) {
            $this->ariRequest($runtimeNodeId, 'DELETE', 'channels/'.$peerChannelId, [], $timeoutMs, [200, 202, 204, 404, 409]);
        }

        return [
            'runtime_node_id' => $runtimeNodeId,
            'bridge_id' => $bridgeId,
            'channel_id' => $channelId,
            'peer_channel_id' => $peerChannelId,
            'configuration_generation' => $configurationGeneration,
            'absent' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $bridgeName = $this->conferenceBridgeName($conferenceId);
        $timeoutMs = (int) $profile['request_timeout_ms'];
        $bridgeInspection = $this->getAriResourceForAuthoritativeInspection($runtimeNodeId, 'bridges/'.$bridgeId, 'bridges', $timeoutMs);
        $bridge = $bridgeInspection['resource'];

        $channels = [];
        if (is_array($bridge)) {
            $this->assertOwnedBridge($bridge, $bridgeId, $bridgeName);
            $channels = is_array($bridge['channels'] ?? null) ? array_values(array_filter($bridge['channels'], 'is_string')) : [];
        }

        $summary = [
            'bridge_exists' => is_array($bridge),
            'bridge_channel_count' => count($channels),
            'owned_bridge' => is_array($bridge),
            'participant_channel_checked' => $participantId !== null,
            'participant_channel_exists' => false,
            'participant_channel_in_bridge' => false,
            'runtime_reference_health' => $bridgeInspection['runtime_reference_health'],
            'bridge_runtime_reference_health' => $bridgeInspection['runtime_reference_health'],
        ];

        if ($participantId !== null) {
            $channelId = $this->participantRuntimeChannelId($participantId) ?? $this->participantChannelId($participantId);
            $peerChannelId = $this->participantRuntimeChannelId($participantId) === null ? $this->participantPeerChannelId($participantId) : null;
            $channelInspection = $this->getAriResourceForAuthoritativeInspection($runtimeNodeId, 'channels/'.$channelId, 'channels', $timeoutMs);
            $channel = $channelInspection['resource'];
            $peerInspection = $peerChannelId === null ? ['resource' => null, 'runtime_reference_health' => 'not_applicable'] : $this->getAriResourceForAuthoritativeInspection($runtimeNodeId, 'channels/'.$peerChannelId, 'channels', $timeoutMs);
            $peerChannel = $peerInspection['resource'];
            $summary['participant_channel_exists'] = is_array($channel);
            $summary['participant_channel_in_bridge'] = in_array($channelId, $channels, true);
            $summary['participant_peer_channel_id'] = $peerChannelId;
            $summary['participant_peer_channel_exists'] = is_array($peerChannel);
            $summary['participant_peer_channel_in_bridge'] = in_array($peerChannelId, $channels, true);
            $summary['participant_any_channel_exists'] = is_array($channel) || is_array($peerChannel);
            $summary['participant_any_channel_in_bridge'] = in_array($channelId, $channels, true) || in_array($peerChannelId, $channels, true);
            $summary['participant_runtime_reference_health'] = $channelInspection['runtime_reference_health'];
            $summary['participant_peer_runtime_reference_health'] = $peerInspection['runtime_reference_health'];
            if (is_array($channel) || is_array($peerChannel) || ! is_array($bridge)) {
                $summary['runtime_reference_health'] = is_array($peerChannel)
                    ? $peerInspection['runtime_reference_health']
                    : $channelInspection['runtime_reference_health'];
            }
        }

        return $summary;
    }

    /**
     * Execute a normalized C6 operation using only provider-local ARI details.
     * Canonical Call/CallLeg state is intentionally not changed here; ARI facts
     * return through the normalized observation ingress.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{id:string,call_id:string,runtime_channel_id:string}>  $legs
     * @return array<string, mixed>
     */
    public function executeCallOperation(string $tenantId, string $runtimeNodeId, string $operationType, array $payload, array $legs): array
    {
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $timeout = (int) $profile['request_timeout_ms'];
        $channels = array_values(array_filter(array_map(static fn (array $leg): string => $leg['runtime_channel_id'], $legs)));
        $channel = $channels[0] ?? null;
        $callId = (string) ($payload['call_id'] ?? ($legs[0]['call_id'] ?? ''));

        $request = function (string $method, string $resource, array $query = [], array $statuses = [200, 201, 202, 204], ?array $body = null) use ($runtimeNodeId, $timeout): array {
            return $this->ariRequest($runtimeNodeId, $method, $resource, $query, $timeout, $statuses, $body);
        };

        if ($operationType === 'call.leg.originate') {
            $destinationAddress = (string) ($payload['destination_uri'] ?? $payload['destination_ref'] ?? '');
            $destination = $this->asteriskEndpoint($destinationAddress);
            $legId = (string) ($payload['leg_id'] ?? ($legs[0]['id'] ?? ''));
            if ($legId === '') {
                throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_call_leg_missing', 'A normalized originate operation requires a CallLeg target.');
            }
            $formats = $this->originateFormats();
            $callerId = $this->callerIdForOriginate($payload);
            $runtimeChannelId = RuntimeChannelIdentity::forCallLeg($legId);
            $request('POST', 'channels', [
                'endpoint' => $destination,
                // Local channels must enter the canonical outbound dialplan. Its
                // Dial() pre-dial handler applies trusted UTCP correlation
                // headers to the outbound PJSIP channel before Kamailio receives
                // it; the Local endpoint is the sole dialplan-entry authority.
                // Supplying context/extension/priority as well would cause the
                // two Local channel halves to enter this Dial() path twice.
                // The existing observation Stasis app is the valid
                // non-provider-dialing control landing for Local ;1. ARI's
                // app model is an alternative to context/extension/priority.
                'app' => (string) $profile['application_name'],
                'timeout' => (string) ($payload['timeout_seconds'] ?? 30),
                'channelId' => $runtimeChannelId,
                'formats' => implode(',', $formats),
                ...($callerId === null ? [] : ['callerId' => $callerId]),
            ], [200, 201, 202], [
                'variables' => [
                    '__UTCP_CALL_LEG_ID' => $legId,
                    '__UTCP_ROUTE_DECISION_ID' => (string) ($payload['route_decision_id'] ?? ''),
                    '__UTCP_TRUNK_ENDPOINT_ID' => (string) ($payload['trunk_endpoint_id'] ?? ''),
                    '__UTCP_CALLER_IDENTITY_ID' => (string) ($payload['caller_identity_id'] ?? ''),
                ],
            ]);

            return ['provider_action' => 'channels.originate', 'destination_ref' => (string) ($payload['destination_ref'] ?? ''), 'runtime_channel_id' => $runtimeChannelId];
        }

        if ($channel === null) {
            throw new AsteriskAriException(FailureClass::Conflict, 'ari_channel_unbound', 'The normalized CallLeg has no current ARI channel.');
        }

        if ($operationType === 'call.hangup') {
            foreach ($channels as $runtimeChannelId) {
                $request('DELETE', 'channels/'.rawurlencode($runtimeChannelId));
            }

            return ['provider_action' => 'channels.hangup', 'runtime_channel_ids' => $channels];
        }

        $resource = 'channels/'.rawurlencode($channel);
        $action = match ($operationType) {
            'call.leg.cancel_origination', 'call.leg.hangup' => ['DELETE', $resource, [], 'channels.hangup'],
            'call.leg.answer' => ['POST', $resource.'/answer', [], 'channels.answer'],
            'call.leg.hold' => ['POST', $resource.'/hold', [], 'channels.hold'],
            'call.leg.resume' => ['DELETE', $resource.'/hold', [], 'channels.resume'],
            'call.leg.mute' => ['POST', $resource.'/mute', ['direction' => 'both'], 'channels.mute'],
            'call.leg.unmute' => ['DELETE', $resource.'/mute', ['direction' => 'both'], 'channels.unmute'],
            'call.leg.send_dtmf' => ['POST', $resource.'/dtmf', ['dtmf' => (string) ($payload['digit'] ?? '')], 'channels.dtmf'],
            'call.leg.redirect', 'call.leg.blind_transfer' => ['POST', $resource.'/redirect', ['endpoint' => $this->asteriskEndpoint((string) ($payload['destination_ref'] ?? ''))], 'channels.redirect'],
            'call.leg.play_media' => ['POST', $resource.'/play', ['media' => $this->asteriskMedia((string) ($payload['media_ref'] ?? ''))], 'channels.play'],
            'call.leg.stop_media' => ['DELETE', 'playbacks/'.rawurlencode((string) ($payload['playback_id'] ?? '')), [], 'playbacks.stop'],
            'call.leg.start_recording' => ['POST', $resource.'/record', ['name' => $this->safeRuntimeReference((string) ($payload['recording_name'] ?? ($legs[0]['id'] ?? 'recording'))), 'format' => 'wav', 'ifExists' => 'overwrite'], 'channels.record'],
            'call.leg.stop_recording' => ['POST', 'recordings/live/'.rawurlencode($this->safeRuntimeReference((string) ($payload['recording_name'] ?? ($legs[0]['id'] ?? 'recording')))).'/stop', [], 'recordings.stop'],
            default => null,
        };

        if ($operationType === 'call.legs.bridge' || $operationType === 'call.legs.unbridge' || $operationType === 'call.leg.attended_transfer') {
            if (count($channels) !== 2) {
                throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_relationship_requires_two_channels', 'The normalized relationship requires two current ARI channels.');
            }
            $bridgeId = 'utcp-call-'.$this->safeRuntimeReference($callId);
            if ($operationType === 'call.legs.bridge') {
                $request('POST', 'bridges', ['bridgeId' => $bridgeId, 'type' => 'mixing', 'name' => 'UTCP call '.$callId], [200, 201, 204]);
                $request('POST', 'bridges/'.rawurlencode($bridgeId).'/addChannel', ['channel' => implode(',', $channels)], [200, 204]);

                return ['provider_action' => 'bridges.add_channel', 'runtime_bridge_id' => $bridgeId];
            }
            if ($operationType === 'call.legs.unbridge') {
                $request('POST', 'bridges/'.rawurlencode($bridgeId).'/removeChannel', ['channel' => implode(',', $channels)], [200, 204, 404]);

                return ['provider_action' => 'bridges.remove_channel', 'runtime_bridge_id' => $bridgeId];
            }
            $request('POST', 'channels/'.rawurlencode($channels[0]).'/redirect', ['endpoint' => 'channel:'.$channels[1]], [200, 204]);

            return ['provider_action' => 'channels.attended_transfer', 'related_runtime_channel_id' => $channels[1]];
        }

        if ($action === null) {
            throw new AsteriskAriException(FailureClass::UnsupportedCapability, 'asterisk_call_operation_unsupported', 'Normalized C6 operation has no deterministic ARI mapping.');
        }

        [$method, $target, $query, $providerAction] = $action;
        $request($method, $target, $query);

        return ['provider_action' => $providerAction, 'runtime_channel_id' => $channel];
    }

    /**
     * @return list<string>
     */
    private function originateFormats(): array
    {
        $configured = config('asterisk_ari.execution_media_formats');
        if (! is_array($configured) || $configured === []) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_execution_media_formats_invalid', 'Managed Asterisk originate media formats are not configured.');
        }

        $formats = [];
        foreach ($configured as $format) {
            if (! is_string($format) || trim($format) === '') {
                throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_execution_media_formats_invalid', 'Managed Asterisk originate media formats must contain only non-blank strings.');
            }
            $formats[] = trim($format);
        }

        if (count(array_unique($formats)) !== count($formats)) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_execution_media_formats_invalid', 'Managed Asterisk originate media formats must not contain duplicates.');
        }

        return $formats;
    }

    private function callerIdForOriginate(array $payload): ?string
    {
        $identityId = trim((string) ($payload['caller_identity_id'] ?? ''));
        $address = trim((string) ($payload['caller_identity_address'] ?? ''));
        if ($identityId === '' && $address === '') {
            return null;
        }
        if ($identityId === '' || $address === '') {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_caller_identity_invalid', 'A normalized originate operation requires a resolvable canonical caller identity address.');
        }

        if (preg_match('/^sips?:([^@;\s]+)@[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/i', $address, $matches) === 1) {
            $user = $matches[1];

            return $user.' <'.$user.'>';
        }
        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $address) === 1) {
            return $address.' <'.$address.'>';
        }

        throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_caller_identity_invalid', 'The canonical caller identity address is not a supported SIP or E.164 address.');
    }

    private function asteriskEndpoint(string $destination): string
    {
        if (str_starts_with($destination, 'sip:')) {
            $endpointAndUri = substr($destination, 4);
            $separator = strpos($endpointAndUri, '/');
            if ($separator !== false) {
                $endpoint = substr($endpointAndUri, 0, $separator);
                $explicitUri = substr($endpointAndUri, $separator + 1);
                if (preg_match('/^[A-Za-z0-9+_.-]+$/', $endpoint) === 1
                    && preg_match('/^sip:[^@\s]+@[^\s]+$/', $explicitUri) === 1) {
                    return 'PJSIP/'.$endpoint.'/'.$explicitUri;
                }
            }
        }

        $destination = preg_replace('/^sip:([^@]+)@.*$/', '$1', $destination) ?: $destination;
        $destination = preg_replace('/^tel:/', '', $destination) ?: $destination;
        if (preg_match('/^[A-Za-z0-9+_.-]+$/', $destination)) {
            return 'Local/'.rawurlencode($destination).'@utcp-outbound';
        }
        if (str_starts_with($destination, 'tel:')) {
            return 'Local/'.rawurlencode(substr($destination, 4)).'@utcp-outbound';
        }
        if (str_starts_with($destination, 'sip:')) {
            return 'PJSIP/'.substr($destination, 4);
        }

        throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_destination_invalid', 'DestinationRef is not a supported normalized telephony address.');
    }

    private function asteriskMedia(string $mediaRef): string
    {
        try {
            return MediaReference::parse($mediaRef)->providerReference('asterisk');
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage() === 'media_ref_unresolved' ? 'media_ref_unresolved' : 'invalid_media_ref';
            throw new AsteriskAriException(FailureClass::InvalidRequest, $code, $exception->getMessage());
        }
    }

    private function participantRuntimeChannelId(string $participantId): ?string
    {
        $channelId = DB::table('conference_participants')->where('id', $participantId)->value('runtime_channel_id');

        return is_string($channelId) && trim($channelId) !== '' ? $channelId : null;
    }

    private function participantAttachedToBridge(string $runtimeNodeId, string $bridgeId, string $channelId, int $timeoutMs): bool
    {
        $bridge = $this->getAriResource($runtimeNodeId, 'bridges/'.$bridgeId, $timeoutMs);
        if (! is_array($bridge)) {
            return false;
        }
        $channels = is_array($bridge['channels'] ?? null) ? array_values(array_filter($bridge['channels'], 'is_string')) : [];

        return in_array($channelId, $channels, true);
    }

    /**
     * @return resource
     */
    public function openWebSocket(string $tenantId, string $runtimeNodeId)
    {
        $node = $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $endpoint = $this->endpoint($runtimeNodeId, 'events', ['ws', 'wss']);
        $credential = $this->credential($runtimeNodeId);
        $path = $endpoint->path ?: '/ari/events';
        $query = $this->eventWebSocketQuery((string) $profile['application_name']);
        $target = $path.(str_contains($path, '?') ? '&' : '?').$query;
        $scheme = $endpoint->transport === 'wss' ? 'ssl' : 'tcp';
        $timeout = max(1, (int) ceil(((int) $profile['websocket_handshake_timeout_ms']) / 1000));
        $stream = @stream_socket_client($scheme.'://'.$endpoint->host.':'.((int) $endpoint->port), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (! is_resource($stream)) {
            throw $this->transportFailure($errno, $errstr ?: 'connection failed');
        }
        stream_set_timeout($stream, $timeout);
        $key = base64_encode(random_bytes(16));
        $host = $endpoint->host.':'.((int) $endpoint->port);
        $headers = [
            'GET '.$target.' HTTP/1.1',
            'Host: '.$host,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
            'Authorization: Basic '.$this->basicToken($credential),
            'User-Agent: utcp-asterisk-ari-t0',
            '',
            '',
        ];
        fwrite($stream, implode("\r\n", $headers));

        $response = '';
        while (! str_contains($response, "\r\n\r\n") && strlen($response) < 8192 && ! feof($stream)) {
            $chunk = fgets($stream, 1024);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        if (! preg_match('/^HTTP\/1\.[01]\s+101\s+/i', $response)) {
            fclose($stream);
            if (preg_match('/^HTTP\/1\.[01]\s+401\s+/i', $response)) {
                throw new AsteriskAriException(FailureClass::AuthenticationFailed, 'ari_websocket_authentication_failed', 'ARI WebSocket authentication failed.');
            }
            if (preg_match('/^HTTP\/1\.[01]\s+403\s+/i', $response)) {
                throw new AsteriskAriException(FailureClass::AuthorizationFailed, 'ari_websocket_authorization_failed', 'ARI WebSocket authorization failed.');
            }
            throw new AsteriskAriException(FailureClass::TransientTransport, 'ari_websocket_handshake_failed', 'ARI WebSocket handshake failed.', true);
        }
        stream_set_blocking($stream, false);

        return $stream;
    }

    public function closeWebSocket(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function stasisApplicationRegistered(string $tenantId, string $runtimeNodeId): bool
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);

        return is_array($this->getAriResource(
            $runtimeNodeId,
            'applications/'.rawurlencode((string) $profile['application_name']),
            (int) $profile['request_timeout_ms'],
        ));
    }

    private function eventWebSocketQuery(string $applicationName): string
    {
        return http_build_query([
            'app' => $applicationName,
            'subscribeAll' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readEvent(mixed $stream): ?array
    {
        $message = $this->readWebSocketMessage($stream);

        return ($message['type'] ?? null) === 'event' && is_array($message['event'] ?? null)
            ? $message['event']
            : null;
    }

    /**
     * @return array{type:string,event?:array<string,mixed>}
     */
    public function readWebSocketMessage(mixed $stream): array
    {
        if (! is_resource($stream)) {
            return ['type' => 'empty'];
        }
        $header = fread($stream, 2);
        if ($header === false || $header === '') {
            return ['type' => 'empty'];
        }
        if (strlen($header) < 2) {
            return ['type' => 'empty'];
        }
        $bytes = array_values(unpack('C2', $header));
        $opcode = $bytes[0] & 0x0F;
        $masked = ($bytes[1] & 0x80) === 0x80;
        $length = $bytes[1] & 0x7F;
        if ($length === 126) {
            $extended = fread($stream, 2);
            if ($extended === false || strlen($extended) !== 2) {
                return ['type' => 'empty'];
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_too_large', 'ARI event payload exceeded the supported frame size.');
        }
        if ($length > (int) config('asterisk_ari.max_payload_bytes', 32768)) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_too_large', 'ARI event payload exceeded the configured limit.');
        }
        $mask = '';
        if ($masked) {
            $mask = fread($stream, 4);
            if ($mask === false || strlen($mask) !== 4) {
                return ['type' => 'empty'];
            }
        }
        $payload = $length > 0 ? fread($stream, $length) : '';
        if (! is_string($payload) || strlen($payload) !== $length) {
            return ['type' => 'empty'];
        }
        if ($masked) {
            $payload = $this->unmaskPayload($payload, $mask);
        }

        if ($opcode === 0x9) {
            $this->sendPong($stream, $payload);

            return ['type' => 'ping'];
        }

        if ($opcode === 0xA) {
            return ['type' => 'pong'];
        }

        if ($opcode === 0x8) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_websocket_closed', 'ARI event WebSocket closed.', true);
        }

        if ($opcode !== 0x1) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_unsupported_frame', 'ARI event WebSocket frame opcode is unsupported.');
        }
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_invalid_json', 'ARI event payload was not valid JSON.');
        }

        return is_array($decoded)
            ? ['type' => 'event', 'event' => $this->sanitizeAriEvent($decoded)]
            : ['type' => 'empty'];
    }

    public function sendPing(mixed $stream): void
    {
        $this->writeWebSocketFrame($stream, 0x9, 'utcp');
    }

    private function node(string $tenantId, string $runtimeNodeId): object
    {
        $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->first();
        if ($node === null || $node->adapter_key !== $this->catalog->adapterKey()) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'asterisk_node_mismatch', 'Runtime node is not configured for Asterisk ARI.');
        }

        return $node;
    }

    /**
     * @param  list<string>  $transports
     */
    private function endpoint(string $runtimeNodeId, string $purpose, array $transports): object
    {
        $endpoint = DB::table('runtime_node_endpoints')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('purpose', $purpose)
            ->whereIn('transport', $transports)
            ->where('enabled', true)
            ->orderBy('priority')
            ->first();
        if ($endpoint === null) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_endpoint_missing', 'Required ARI endpoint is missing.');
        }

        return $endpoint;
    }

    private function credential(string $runtimeNodeId): object
    {
        $credential = DB::table('runtime_node_credentials')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('credential_type', $this->catalog->credentialType())
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
        if ($credential === null) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_credential_missing', 'Active ARI credential is missing.');
        }

        return $credential;
    }

    private function endpointUrl(object $endpoint, string $fallbackPath): string
    {
        $path = $endpoint->path ?: $fallbackPath;

        return $endpoint->transport.'://'.$endpoint->host.':'.((int) $endpoint->port).$path;
    }

    private function basicToken(object $credential): string
    {
        $username = trim((string) $credential->identifier);
        if ($username === '') {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_username_missing', 'ARI credential username is missing.');
        }
        $plain = Crypt::decryptString((string) $credential->{'encrypted_'.'secret'});

        return base64_encode($username.':'.$plain);
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<int>  $acceptedStatuses
     * @param  array<string, mixed>|null  $body
     * @return array{status:int,body:string}
     */
    protected function ariRequest(string $runtimeNodeId, string $method, string $resource, array $query, int $timeoutMs, array $acceptedStatuses, ?array $body = null): array
    {
        $endpoint = $this->endpoint($runtimeNodeId, 'control', ['http', 'https']);
        $credential = $this->credential($runtimeNodeId);
        $url = rtrim($this->endpointUrl($endpoint, '/ari'), '/').'/'.ltrim($resource, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [
            'Authorization: Basic '.$this->basicToken($credential),
            'Accept: application/json',
            'User-Agent: utcp-asterisk-ari-t2a',
        ];
        $encodedBody = null;
        if ($body !== null) {
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new AsteriskAriException(FailureClass::InternalError, 'ari_request_body_invalid', 'ARI request body could not be serialized.');
            }
            $headers[] = 'Content-Type: application/json';
        } else {
            $headers[] = 'Content-Length: 0';
        }
        $response = $this->request($method, $url, $headers, $timeoutMs, $encodedBody);

        $status = (int) $response['status'];
        if (in_array($status, $acceptedStatuses, true)) {
            return $response;
        }
        if ($status === 401) {
            throw new AsteriskAriException(FailureClass::AuthenticationFailed, 'ari_authentication_failed', 'ARI authentication failed.');
        }
        if ($status === 403) {
            throw new AsteriskAriException(FailureClass::AuthorizationFailed, 'ari_authorization_failed', 'ARI authorization failed.');
        }
        if ($status === 404) {
            throw new AsteriskAriException(FailureClass::Conflict, 'ari_resource_not_found', 'ARI resource was not found.');
        }
        if ($status === 409) {
            throw new AsteriskAriException(FailureClass::Conflict, 'ari_resource_conflict', $this->ariErrorMessage($response['body'], 'ARI resource conflict.'));
        }
        if ($status === 422) {
            throw new AsteriskAriException(FailureClass::UnsupportedCapability, 'ari_recording_format_unsupported', $this->ariErrorMessage($response['body'], 'ARI recording format is unsupported by the runtime.'));
        }

        throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_unavailable', 'ARI HTTP request did not return success.', true);
    }

    private function ariErrorMessage(string $body, string $fallback): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;

            return is_string($message) && trim($message) !== '' ? trim($message) : $fallback;
        } catch (JsonException) {
            return $fallback;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAriResource(string $runtimeNodeId, string $resource, int $timeoutMs): ?array
    {
        $response = $this->ariRequest($runtimeNodeId, 'GET', $resource, [], $timeoutMs, [200, 404]);
        if ((int) $response['status'] === 404) {
            return null;
        }

        try {
            $decoded = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_invalid_json', 'ARI resource response was not valid JSON.');
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{resource:array<string,mixed>|null,runtime_reference_health:string}
     */
    private function getAriResourceForAuthoritativeInspection(string $runtimeNodeId, string $resource, string $familyResource, int $timeoutMs): array
    {
        $response = $this->ariRequest($runtimeNodeId, 'GET', $resource, [], $timeoutMs, [200, 404]);
        if ((int) $response['status'] === 404) {
            if (! $this->ariResourceFamilyHealthy($runtimeNodeId, $familyResource, $timeoutMs)) {
                throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_resource_family_degraded', 'ARI resource family is degraded.', true);
            }

            return [
                'resource' => null,
                'runtime_reference_health' => 'healthy_absent',
            ];
        }

        try {
            $decoded = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_invalid_json', 'ARI resource response was not valid JSON.');
        }

        return [
            'resource' => is_array($decoded) ? $decoded : null,
            'runtime_reference_health' => 'healthy_present',
        ];
    }

    public function bridgeResourceFamilyHealthy(string $tenantId, string $runtimeNodeId): bool
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);

        return $this->ariResourceFamilyHealthy($runtimeNodeId, 'bridges', (int) $profile['request_timeout_ms']);
    }

    public function channelResourceFamilyHealthy(string $tenantId, string $runtimeNodeId): bool
    {
        $this->node($tenantId, $runtimeNodeId);
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);

        return $this->ariResourceFamilyHealthy($runtimeNodeId, 'channels', (int) $profile['request_timeout_ms']);
    }

    private function ariResourceFamilyHealthy(string $runtimeNodeId, string $familyResource, int $timeoutMs): bool
    {
        try {
            $this->ariRequest($runtimeNodeId, 'GET', $familyResource, [], $timeoutMs, [200]);

            return true;
        } catch (AsteriskAriException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $bridge
     */
    private function assertOwnedBridge(array $bridge, string $bridgeId, string $bridgeName): void
    {
        $id = is_string($bridge['id'] ?? null) ? $bridge['id'] : '';
        $name = is_string($bridge['name'] ?? null) ? $bridge['name'] : '';
        if ($id !== $bridgeId || ($name !== '' && $name !== $bridgeName)) {
            throw new AsteriskAriException(FailureClass::Conflict, 'ari_foreign_bridge_conflict', 'ARI bridge identity did not match the UTCP-owned bridge.');
        }
    }

    public function conferenceBridgeId(string $conferenceId): string
    {
        return (string) config('asterisk_ari.conference.bridge_id_prefix', 'utcp-conf-').$this->safeRuntimeReference($conferenceId);
    }

    public function participantChannelId(string $participantId): string
    {
        return (string) config('asterisk_ari.conference.participant_channel_id_prefix', 'utcp-part-').$this->safeRuntimeReference($participantId);
    }

    public function participantPeerChannelId(string $participantId): string
    {
        return $this->participantChannelId($participantId).';2';
    }

    private function conferenceBridgeName(string $conferenceId): string
    {
        return mb_substr((string) config('asterisk_ari.conference.bridge_name_prefix', 'UTCP conference ').$this->safeRuntimeReference($conferenceId), 0, 80);
    }

    private function participantChannelName(string $participantId): string
    {
        return mb_substr((string) config('asterisk_ari.conference.participant_channel_name_prefix', 'UTCP participant ').$this->safeRuntimeReference($participantId), 0, 80);
    }

    private function safeRuntimeReference(string $value): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, 80);
    }

    /**
     * @param  list<string>  $headers
     * @return array{status:int,body:string}
     */
    protected function request(string $method, string $url, array $headers, int $timeoutMs, ?string $body = null): array
    {
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => max(1, (int) ceil($timeoutMs / 1000)),
            'ignore_errors' => true,
            'max_redirects' => 0,
        ];
        if ($body !== null) {
            $options['content'] = $body;
        }
        $context = stream_context_create([
            'http' => $options,
        ]);
        $body = @file_get_contents($url, false, $context, 0, (int) config('asterisk_ari.max_payload_bytes', 32768));
        if ($body === false) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_transport_failed', 'ARI HTTP transport failed.', true);
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    private function transportFailure(int $errno, string $message): AsteriskAriException
    {
        $class = str_contains(strtolower($message), 'timed out') ? FailureClass::Timeout : FailureClass::RuntimeUnavailable;

        return new AsteriskAriException($class, $class === FailureClass::Timeout ? 'ari_connection_timeout' : 'ari_connection_failed', 'ARI transport failed.', true);
    }

    private function sendPong(mixed $stream, string $payload): void
    {
        $this->writeWebSocketFrame($stream, 0xA, $payload);
    }

    private function writeWebSocketFrame(mixed $stream, int $opcode, string $payload): void
    {
        if (! is_resource($stream)) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_websocket_unavailable', 'ARI event WebSocket is unavailable.', true);
        }

        $length = strlen($payload);
        if ($length > 125) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_control_frame_too_large', 'ARI WebSocket control frame payload exceeded the supported frame size.');
        }

        $mask = random_bytes(4);
        $frame = chr(0x80 | $opcode).chr(0x80 | $length).$mask.$this->maskPayload($payload, $mask);
        $written = fwrite($stream, $frame);
        if ($written === false || $written !== strlen($frame)) {
            throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_websocket_write_failed', 'ARI event WebSocket write failed.', true);
        }
    }

    private function maskPayload(string $payload, string $mask): string
    {
        $masked = '';
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $masked;
    }

    private function unmaskPayload(string $payload, string $mask): string
    {
        return $this->maskPayload($payload, $mask);
    }

    private function safeString(mixed $value): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $value) ?: 'unknown', 0, 120);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function sanitizeAriEvent(array $event): array
    {
        $type = is_string($event['type'] ?? null) ? mb_substr($event['type'], 0, 120) : 'unknown';

        $sanitized = [
            'type' => $type,
            'asterisk_id' => is_string($event['asterisk_id'] ?? null) ? mb_substr($event['asterisk_id'], 0, 120) : null,
            'timestamp' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : now()->toISOString(),
            'args' => $this->sanitizeAriArguments($event['args'] ?? null),
            'bridge' => is_array($event['bridge'] ?? null) ? $this->sanitizeAriObject($event['bridge']) : null,
            'channel' => is_array($event['channel'] ?? null) ? $this->sanitizeAriObject($event['channel']) : null,
            'digit' => is_string($event['digit'] ?? null) ? mb_substr($event['digit'], 0, 8) : null,
            'duration_ms' => is_numeric($event['duration_ms'] ?? null) ? (int) $event['duration_ms'] : null,
            'playback' => is_array($event['playback'] ?? null) ? $this->sanitizeAriObject($event['playback']) : null,
            'recording' => is_array($event['recording'] ?? null) ? $this->sanitizeAriObject($event['recording']) : null,
        ];

        if ($type === 'ChannelDestroyed') {
            if (is_int($event['cause'] ?? null)) {
                $sanitized['cause'] = $event['cause'];
            }
            if (is_string($event['cause_txt'] ?? null)) {
                $sanitized['cause_txt'] = mb_substr($event['cause_txt'], 0, 120);
            }
            if (is_int($event['tech_cause'] ?? null)) {
                $sanitized['tech_cause'] = $event['tech_cause'];
            }
        }

        return $sanitized;
    }

    /**
     * @return list<string>
     */
    private function sanitizeAriArguments(mixed $args): array
    {
        if (! is_array($args)) {
            return [];
        }

        return array_values(array_map(
            fn (string $value): string => mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, 120),
            array_filter($args, 'is_string'),
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string|null>
     */
    private function sanitizeAriObject(array $value): array
    {
        return [
            'id' => is_string($value['id'] ?? null) ? mb_substr($value['id'], 0, 120) : null,
            'name' => is_string($value['name'] ?? null) ? mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value['name']) ?: 'unknown', 0, 120) : null,
            'state' => is_string($value['state'] ?? null) ? mb_substr($value['state'], 0, 64) : null,
            'caller' => is_array($value['caller'] ?? null) ? ['number' => is_string($value['caller']['number'] ?? null) ? mb_substr($value['caller']['number'], 0, 120) : null] : null,
            'connected' => is_array($value['connected'] ?? null) ? ['number' => is_string($value['connected']['number'] ?? null) ? mb_substr($value['connected']['number'], 0, 120) : null] : null,
            'channelvars' => $this->sanitizeAriChannelvars($value['channelvars'] ?? null),
            'media_uri' => is_string($value['media_uri'] ?? null) ? mb_substr($value['media_uri'], 0, 240) : null,
            'channels' => is_array($value['channels'] ?? null) ? array_values(array_filter(array_map(
                static fn (mixed $channel): ?string => is_string($channel) ? mb_substr($channel, 0, 120) : (is_array($channel) && is_string($channel['id'] ?? null) ? mb_substr($channel['id'], 0, 120) : null),
                $value['channels'],
            ))) : null,
        ];
    }

    /**
     * @return array{UTCP_CALL_LEG_ID:string}|null
     */
    private function sanitizeAriChannelvars(mixed $channelvars): ?array
    {
        if (! is_array($channelvars) || ! is_string($channelvars['UTCP_CALL_LEG_ID'] ?? null)) {
            return null;
        }

        $callLegId = trim($channelvars['UTCP_CALL_LEG_ID']);
        if (! Str::isUuid($callLegId)) {
            return null;
        }

        return ['UTCP_CALL_LEG_ID' => strtolower($callLegId)];
    }
}
