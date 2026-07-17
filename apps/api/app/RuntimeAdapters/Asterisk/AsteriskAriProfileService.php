<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Sources\EventSourceRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AsteriskAriProfileService
{
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
        if (! preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $application)) {
            throw new InvalidArgumentException('Invalid Asterisk ARI application name.');
        }

        $connect = $this->boundedInt($input['connect_timeout_ms'] ?? null, 250, 30000, 'Invalid connect timeout.');
        $request = $this->boundedInt($input['request_timeout_ms'] ?? null, 250, 60000, 'Invalid request timeout.');
        $handshake = $this->boundedInt($input['websocket_handshake_timeout_ms'] ?? null, 250, 60000, 'Invalid WebSocket handshake timeout.');
        $heartbeat = $this->boundedInt($input['heartbeat_interval_ms'] ?? null, 1000, 120000, 'Invalid heartbeat interval.');
        $minReconnect = $this->boundedInt($input['reconnect_min_delay_ms'] ?? null, 100, 120000, 'Invalid minimum reconnect delay.');
        $maxReconnect = $this->boundedInt($input['reconnect_max_delay_ms'] ?? null, 100, 300000, 'Invalid maximum reconnect delay.');
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
    private function defaults(): array
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
