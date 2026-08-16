<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Sources\EventSourceRepository;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationDescriptorCollection;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationFieldDescriptor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AsteriskAriProfileService
{
    public const APPLICATION_NAME_MIN_LENGTH = 3;

    public const APPLICATION_NAME_MAX_LENGTH = 80;

    public const CONNECT_TIMEOUT_MIN_MS = 250;

    public const CONNECT_TIMEOUT_MAX_MS = 30000;

    public const REQUEST_TIMEOUT_MIN_MS = 250;

    public const REQUEST_TIMEOUT_MAX_MS = 60000;

    public const WEBSOCKET_HANDSHAKE_TIMEOUT_MIN_MS = 250;

    public const WEBSOCKET_HANDSHAKE_TIMEOUT_MAX_MS = 60000;

    public const HEARTBEAT_INTERVAL_MIN_MS = 1000;

    public const HEARTBEAT_INTERVAL_MAX_MS = 120000;

    public const RECONNECT_MIN_DELAY_MIN_MS = 100;

    public const RECONNECT_MIN_DELAY_MAX_MS = 120000;

    public const RECONNECT_MAX_DELAY_MIN_MS = 100;

    public const RECONNECT_MAX_DELAY_MAX_MS = 300000;

    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly EventSourceRepository $sources,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(string $tenantId, string $runtimeNodeId): array
    {
        $node = $this->asteriskNode($tenantId, $runtimeNodeId);
        $profile = DB::table('asterisk_ari_profiles')->where('runtime_node_id', $runtimeNodeId)->first();

        return [
            'runtime_node_id' => $runtimeNodeId,
            'adapter_key' => $node->adapter_key,
            'configured' => $profile !== null,
            'profile' => $profile === null ? null : $this->serialize($profile),
            'defaults' => $this->defaults(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function put(ExecutionContext $context, string $tenantId, string $runtimeNodeId, array $input): array
    {
        $validated = $this->validate($input);

        DB::transaction(function () use ($context, $tenantId, $runtimeNodeId, $validated): void {
            $node = $this->asteriskNode($tenantId, $runtimeNodeId, true);
            if ((string) $node->desired_state === 'retired') {
                throw new InvalidArgumentException('Retired runtime nodes are read-only historical records.');
            }
            $generation = ((int) $node->configuration_version) + 1;
            DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->update([
                'configuration_version' => $generation,
                'updated_by' => $context->actorId,
                'updated_at' => now(),
            ]);
            DB::table('asterisk_ari_profiles')->updateOrInsert(
                ['runtime_node_id' => $runtimeNodeId],
                [
                    ...$validated,
                    'configuration_version' => $generation,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('runtime_reconciliation_states')->where('target_type', 'runtime_node')->where('target_id', $runtimeNodeId)->delete();
            $source = $this->sources->ensureRuntimeNodeSource($tenantId, $runtimeNodeId);
            DB::table('runtime_listener_leases')->where('event_source_id', $source->id)->where('listener_kind', $this->catalog->listenerKind())->update([
                'status' => 'released',
                'released_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

            $payload = [
                'adapter_key' => $this->catalog->adapterKey(),
                'configuration_generation' => $generation,
                'connection_settings_changed' => true,
            ];
            $this->audit->append($context, 'runtime_node.asterisk_ari_configuration_changed', 'runtime_node', $runtimeNodeId, $payload);
            $this->outbox->append(EventEnvelope::forAggregate('runtime_node.asterisk_ari_configuration_changed', 1, 'runtime_node', $runtimeNodeId, $payload, $context));
        });

        return $this->show($tenantId, $runtimeNodeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function requiredProfile(string $tenantId, string $runtimeNodeId): array
    {
        $this->asteriskNode($tenantId, $runtimeNodeId);
        $profile = DB::table('asterisk_ari_profiles')->where('runtime_node_id', $runtimeNodeId)->first();
        if ($profile === null) {
            throw new AsteriskAriException(FailureClass::InvalidRequest, 'ari_profile_missing', 'Asterisk ARI adapter configuration is missing.');
        }

        return $this->serialize($profile);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $application = (string) ($input['application_name'] ?? '');
        if (
            strlen($application) < self::APPLICATION_NAME_MIN_LENGTH
            || strlen($application) > self::APPLICATION_NAME_MAX_LENGTH
            || ! preg_match('/^[A-Za-z0-9_.-]+$/', $application)
        ) {
            throw new InvalidArgumentException('Invalid Asterisk ARI application name.');
        }

        $connect = $this->boundedInt($input['connect_timeout_ms'] ?? null, self::CONNECT_TIMEOUT_MIN_MS, self::CONNECT_TIMEOUT_MAX_MS, 'Invalid connect timeout.');
        $request = $this->boundedInt($input['request_timeout_ms'] ?? null, self::REQUEST_TIMEOUT_MIN_MS, self::REQUEST_TIMEOUT_MAX_MS, 'Invalid request timeout.');
        $handshake = $this->boundedInt($input['websocket_handshake_timeout_ms'] ?? null, self::WEBSOCKET_HANDSHAKE_TIMEOUT_MIN_MS, self::WEBSOCKET_HANDSHAKE_TIMEOUT_MAX_MS, 'Invalid WebSocket handshake timeout.');
        $heartbeat = $this->boundedInt($input['heartbeat_interval_ms'] ?? null, self::HEARTBEAT_INTERVAL_MIN_MS, self::HEARTBEAT_INTERVAL_MAX_MS, 'Invalid heartbeat interval.');
        $minReconnect = $this->boundedInt($input['reconnect_min_delay_ms'] ?? null, self::RECONNECT_MIN_DELAY_MIN_MS, self::RECONNECT_MIN_DELAY_MAX_MS, 'Invalid minimum reconnect delay.');
        $maxReconnect = $this->boundedInt($input['reconnect_max_delay_ms'] ?? null, self::RECONNECT_MAX_DELAY_MIN_MS, self::RECONNECT_MAX_DELAY_MAX_MS, 'Invalid maximum reconnect delay.');
        if ($minReconnect > $maxReconnect) {
            throw new InvalidArgumentException('Minimum reconnect delay must not exceed maximum reconnect delay.');
        }

        return [
            'application_name' => $application,
            'connect_timeout_ms' => $connect,
            'request_timeout_ms' => $request,
            'websocket_handshake_timeout_ms' => $handshake,
            'heartbeat_interval_ms' => $heartbeat,
            'reconnect_min_delay_ms' => $minReconnect,
            'reconnect_max_delay_ms' => $maxReconnect,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function descriptors(): AdapterConfigurationDescriptorCollection
    {
        $defaults = $this->defaults();

        return new AdapterConfigurationDescriptorCollection([
            AdapterConfigurationFieldDescriptor::text(
                'application_name',
                'ARI application name',
                'Stasis application name subscribed by the Asterisk ARI listener.',
                true,
                $defaults['application_name'],
                10,
                ['min_length' => self::APPLICATION_NAME_MIN_LENGTH, 'max_length' => self::APPLICATION_NAME_MAX_LENGTH],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'connect_timeout_ms',
                'Connect timeout',
                'HTTP connection timeout for Asterisk ARI requests, in milliseconds.',
                true,
                $defaults['connect_timeout_ms'],
                20,
                ['min' => self::CONNECT_TIMEOUT_MIN_MS, 'max' => self::CONNECT_TIMEOUT_MAX_MS, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'request_timeout_ms',
                'Request timeout',
                'Total timeout for Asterisk ARI HTTP requests, in milliseconds.',
                true,
                $defaults['request_timeout_ms'],
                30,
                ['min' => self::REQUEST_TIMEOUT_MIN_MS, 'max' => self::REQUEST_TIMEOUT_MAX_MS, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'websocket_handshake_timeout_ms',
                'WebSocket handshake timeout',
                'Timeout for establishing the Asterisk ARI event WebSocket, in milliseconds.',
                true,
                $defaults['websocket_handshake_timeout_ms'],
                40,
                ['min' => self::WEBSOCKET_HANDSHAKE_TIMEOUT_MIN_MS, 'max' => self::WEBSOCKET_HANDSHAKE_TIMEOUT_MAX_MS, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'heartbeat_interval_ms',
                'Heartbeat interval',
                'Interval for ARI event connection heartbeat checks, in milliseconds.',
                true,
                $defaults['heartbeat_interval_ms'],
                50,
                ['min' => self::HEARTBEAT_INTERVAL_MIN_MS, 'max' => self::HEARTBEAT_INTERVAL_MAX_MS, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'reconnect_min_delay_ms',
                'Minimum reconnect delay',
                'Minimum backoff delay before reconnecting the ARI event stream, in milliseconds.',
                true,
                $defaults['reconnect_min_delay_ms'],
                60,
                ['min' => self::RECONNECT_MIN_DELAY_MIN_MS, 'max' => self::RECONNECT_MIN_DELAY_MAX_MS, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'reconnect_max_delay_ms',
                'Maximum reconnect delay',
                'Maximum backoff delay before reconnecting the ARI event stream, in milliseconds.',
                true,
                $defaults['reconnect_max_delay_ms'],
                70,
                ['min' => self::RECONNECT_MAX_DELAY_MIN_MS, 'max' => self::RECONNECT_MAX_DELAY_MAX_MS, 'step' => 1],
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->validate([
            'application_name' => config('asterisk_ari.defaults.application_name', 'utcp-t0-observation'),
            'connect_timeout_ms' => config('asterisk_ari.defaults.connect_timeout_ms', 2000),
            'request_timeout_ms' => config('asterisk_ari.defaults.request_timeout_ms', 4000),
            'websocket_handshake_timeout_ms' => config('asterisk_ari.defaults.websocket_handshake_timeout_ms', 4000),
            'heartbeat_interval_ms' => config('asterisk_ari.defaults.heartbeat_interval_ms', 15000),
            'reconnect_min_delay_ms' => config('asterisk_ari.defaults.reconnect_min_delay_ms', 1000),
            'reconnect_max_delay_ms' => config('asterisk_ari.defaults.reconnect_max_delay_ms', 30000),
        ]);
    }

    private function asteriskNode(string $tenantId, string $runtimeNodeId, bool $lock = false): object
    {
        $query = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $node = $query->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        if ($node->adapter_key !== $this->catalog->adapterKey()) {
            throw new InvalidArgumentException('Runtime node is not configured for Asterisk ARI.');
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(object $profile): array
    {
        return [
            'configuration_version' => (int) $profile->configuration_version,
            'application_name' => $profile->application_name,
            'connect_timeout_ms' => (int) $profile->connect_timeout_ms,
            'request_timeout_ms' => (int) $profile->request_timeout_ms,
            'websocket_handshake_timeout_ms' => (int) $profile->websocket_handshake_timeout_ms,
            'heartbeat_interval_ms' => (int) $profile->heartbeat_interval_ms,
            'reconnect_min_delay_ms' => (int) $profile->reconnect_min_delay_ms,
            'reconnect_max_delay_ms' => (int) $profile->reconnect_max_delay_ms,
            'updated_at' => $profile->updated_at,
        ];
    }

    private function boundedInt(mixed $value, int $min, int $max, string $message): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException($message);
        }
        $integer = (int) $value;
        if ($integer < $min || $integer > $max) {
            throw new InvalidArgumentException($message);
        }

        return $integer;
    }
}
