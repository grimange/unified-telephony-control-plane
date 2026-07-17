<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
     * @return array<string, mixed>
     */
    public function removeParticipantChannel(string $tenantId, string $runtimeNodeId, string $conferenceId, string $participantId, int $configurationGeneration): array
    {
        $profile = $this->profiles->requiredProfile($tenantId, $runtimeNodeId);
        $bridgeId = $this->conferenceBridgeId($conferenceId);
        $channelId = $this->participantChannelId($participantId);
        $timeoutMs = (int) $profile['request_timeout_ms'];

        $this->ariRequest($runtimeNodeId, 'POST', 'bridges/'.$bridgeId.'/removeChannel', ['channel' => $channelId], $timeoutMs, [200, 202, 204, 404, 409, 422]);
        $this->ariRequest($runtimeNodeId, 'DELETE', 'channels/'.$channelId, [], $timeoutMs, [200, 202, 204, 404, 409]);

        return [
            'runtime_node_id' => $runtimeNodeId,
            'bridge_id' => $bridgeId,
            'channel_id' => $channelId,
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
        $bridge = $this->getAriResource($runtimeNodeId, 'bridges/'.$bridgeId, $timeoutMs);

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
        ];

        if ($participantId !== null) {
            $channelId = $this->participantChannelId($participantId);
            $channel = $this->getAriResource($runtimeNodeId, 'channels/'.$channelId, $timeoutMs);
            $summary['participant_channel_exists'] = is_array($channel);
            $summary['participant_channel_in_bridge'] = in_array($channelId, $channels, true);
        }

        return $summary;
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
        if (! is_resource($stream)) {
            return null;
        }
        $header = fread($stream, 2);
        if ($header === false || $header === '') {
            return null;
        }
        if (strlen($header) < 2) {
            return null;
        }
        $bytes = array_values(unpack('C2', $header));
        $length = $bytes[1] & 0x7F;
        if ($length === 126) {
            $extended = fread($stream, 2);
            if ($extended === false || strlen($extended) !== 2) {
                return null;
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_too_large', 'ARI event payload exceeded the supported frame size.');
        }
        if ($length > (int) config('asterisk_ari.max_payload_bytes', 32768)) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_too_large', 'ARI event payload exceeded the configured limit.');
        }
        $payload = $length > 0 ? fread($stream, $length) : '';
        if (! is_string($payload) || strlen($payload) !== $length) {
            return null;
        }
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AsteriskAriException(FailureClass::InternalError, 'ari_event_invalid_json', 'ARI event payload was not valid JSON.');
        }

        return is_array($decoded) ? $this->sanitizeAriEvent($decoded) : null;
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
     * @return array{status:int,body:string}
     */
    private function ariRequest(string $runtimeNodeId, string $method, string $resource, array $query, int $timeoutMs, array $acceptedStatuses): array
    {
        $endpoint = $this->endpoint($runtimeNodeId, 'control', ['http', 'https']);
        $credential = $this->credential($runtimeNodeId);
        $url = rtrim($this->endpointUrl($endpoint, '/ari'), '/').'/'.ltrim($resource, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $response = $this->request($method, $url, [
            'Authorization: Basic '.$this->basicToken($credential),
            'Accept: application/json',
            'Content-Length: 0',
            'User-Agent: utcp-asterisk-ari-t2a',
        ], $timeoutMs);

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
        if ($status === 409 || $status === 422) {
            throw new AsteriskAriException(FailureClass::Conflict, 'ari_resource_conflict', 'ARI resource conflict.');
        }

        throw new AsteriskAriException(FailureClass::RuntimeUnavailable, 'ari_http_unavailable', 'ARI HTTP request did not return success.', true);
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
    private function request(string $method, string $url, array $headers, int $timeoutMs): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => max(1, (int) ceil($timeoutMs / 1000)),
                'ignore_errors' => true,
                'max_redirects' => 0,
            ],
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

        return [
            'type' => $type,
            'asterisk_id' => is_string($event['asterisk_id'] ?? null) ? mb_substr($event['asterisk_id'], 0, 120) : null,
            'timestamp' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : now()->toISOString(),
            'bridge' => is_array($event['bridge'] ?? null) ? $this->sanitizeAriObject($event['bridge']) : null,
            'channel' => is_array($event['channel'] ?? null) ? $this->sanitizeAriObject($event['channel']) : null,
        ];
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
        ];
    }
}
