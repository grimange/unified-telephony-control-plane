<?php

namespace App\RuntimeRegistry;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Identity\IdentityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RuntimeRegistryService
{
    public function __construct(
        private readonly RuntimeRegistryCatalog $catalog,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createNode(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $input['slug'] = $this->normalizeSlug($input['slug']);
        $fingerprint = ['tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $input['slug'], 'runtime_family' => $input['runtime_family'], 'adapter_key' => $input['adapter_key']];
        if ($key !== null && ($existing = $this->beginIdempotent('runtime_registry.nodes.create', $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $input): array {
            $this->catalog->assertAdapterForFamily($input['runtime_family'], $input['adapter_key']);
            $id = RuntimeRegistryIds::new();
            $labels = $this->validatedLabels($input['labels'] ?? []);
            DB::table('runtime_nodes')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => $input['name'],
                'slug' => $input['slug'],
                'runtime_family' => $input['runtime_family'],
                'adapter_key' => $input['adapter_key'],
                'desired_state' => 'draft',
                'observed_state' => 'unobserved',
                'configuration_version' => 1,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'placement_region' => $input['placement_region'] ?? null,
                'placement_zone' => $input['placement_zone'] ?? null,
                'placement_priority' => $input['placement_priority'] ?? 100,
                'capacity_weight' => $input['capacity_weight'] ?? 100,
                'labels' => $labels === [] ? null : json_encode($labels, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->emit($request, $tenantId, $id, 'runtime_node.created', [
                'runtime_family' => $input['runtime_family'],
                'adapter_key' => $input['adapter_key'],
                'desired_state' => 'draft',
                'configuration_version' => 1,
            ]);

            return ['runtime_node' => $this->node($id, $tenantId)];
        });

        if ($key !== null) {
            $this->idempotency->complete('runtime_registry.nodes.create', $key, $result);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNodes(string $tenantId): array
    {
        return DB::table('runtime_nodes')->where('tenant_id', $tenantId)->orderBy('name')->get()
            ->map(fn (object $node): array => $this->serializeNode($node))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function node(string $nodeId, string $tenantId): array
    {
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');

        return $this->serializeNode($node);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateNode(Request $request, string $tenantId, string $nodeId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $input): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $runtimeFamily = $input['runtime_family'] ?? $node->runtime_family;
            $adapterKey = $input['adapter_key'] ?? $node->adapter_key;
            $this->catalog->assertAdapterForFamily($runtimeFamily, $adapterKey);
            $labels = array_key_exists('labels', $input) ? $this->validatedLabels($input['labels'] ?? []) : null;
            $version = ((int) $node->configuration_version) + 1;
            $update = [
                'name' => $input['name'] ?? $node->name,
                'runtime_family' => $runtimeFamily,
                'adapter_key' => $adapterKey,
                'placement_region' => $input['placement_region'] ?? $node->placement_region,
                'placement_zone' => $input['placement_zone'] ?? $node->placement_zone,
                'placement_priority' => $input['placement_priority'] ?? $node->placement_priority,
                'capacity_weight' => $input['capacity_weight'] ?? $node->capacity_weight,
                'configuration_version' => $version,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ];
            if (array_key_exists('labels', $input)) {
                $update['labels'] = $labels === [] ? null : json_encode($labels, JSON_THROW_ON_ERROR);
            }
            DB::table('runtime_nodes')->where('id', $nodeId)->update($update);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.updated', ['configuration_version' => $version]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function changeDesiredState(Request $request, string $tenantId, string $nodeId, string $desiredState): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $desiredState): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->catalog->assertDesiredTransition($node->desired_state, $desiredState);
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_state' => $desiredState,
                'configuration_version' => ((int) $node->configuration_version) + 1,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.desired_state_changed', ['from' => $node->desired_state, 'to' => $desiredState]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addEndpoint(Request $request, string $tenantId, string $nodeId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $input): array {
            $this->nodeForUpdate($nodeId, $tenantId);
            $this->catalog->assertEndpoint($input['purpose'], $input['transport'], (int) $input['port'], $input['tls_mode'] ?? 'disabled', $input['path'] ?? null);
            $id = RuntimeRegistryIds::new();
            DB::table('runtime_node_endpoints')->insert([
                'id' => $id,
                'runtime_node_id' => $nodeId,
                'purpose' => $input['purpose'],
                'transport' => $input['transport'],
                'host' => $this->validateHost($input['host']),
                'port' => (int) $input['port'],
                'path' => $input['path'] ?? null,
                'tls_mode' => $input['tls_mode'] ?? 'disabled',
                'priority' => $input['priority'] ?? 100,
                'enabled' => $input['enabled'] ?? true,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'added', 'endpoint_id' => $id, 'purpose' => $input['purpose']]);

            return ['endpoint' => $this->endpoint($id, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateEndpoint(Request $request, string $tenantId, string $nodeId, string $endpointId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $endpointId, $input): array {
            $this->nodeForUpdate($nodeId, $tenantId);
            $endpoint = DB::table('runtime_node_endpoints')->where('id', $endpointId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
            abort_unless($endpoint !== null, 404, 'Endpoint not found.');
            $purpose = $input['purpose'] ?? $endpoint->purpose;
            $transport = $input['transport'] ?? $endpoint->transport;
            $port = (int) ($input['port'] ?? $endpoint->port);
            $tlsMode = $input['tls_mode'] ?? $endpoint->tls_mode;
            $path = $input['path'] ?? $endpoint->path;
            $this->catalog->assertEndpoint($purpose, $transport, $port, $tlsMode, $path);
            DB::table('runtime_node_endpoints')->where('id', $endpointId)->update([
                'purpose' => $purpose,
                'transport' => $transport,
                'host' => array_key_exists('host', $input) ? $this->validateHost($input['host']) : $endpoint->host,
                'port' => $port,
                'path' => $path,
                'tls_mode' => $tlsMode,
                'priority' => $input['priority'] ?? $endpoint->priority,
                'enabled' => $input['enabled'] ?? $endpoint->enabled,
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'updated', 'endpoint_id' => $endpointId, 'purpose' => $purpose]);

            return ['endpoint' => $this->endpoint($endpointId, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    public function removeEndpoint(Request $request, string $tenantId, string $nodeId, string $endpointId): void
    {
        DB::transaction(function () use ($request, $tenantId, $nodeId, $endpointId): void {
            $this->nodeForUpdate($nodeId, $tenantId);
            $deleted = DB::table('runtime_node_endpoints')->where('id', $endpointId)->where('runtime_node_id', $nodeId)->delete();
            abort_unless($deleted === 1, 404, 'Endpoint not found.');
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'removed', 'endpoint_id' => $endpointId]);
        });
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    public function setCapabilities(Request $request, string $tenantId, string $nodeId, array $capabilities): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $capabilities): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $capabilities = array_values(array_unique($capabilities));
            sort($capabilities);
            $this->catalog->assertCapabilitiesForAdapter((string) $node->adapter_key, $capabilities);
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->delete();
            foreach ($capabilities as $capability) {
                DB::table('runtime_node_capabilities')->insert([
                    'id' => RuntimeRegistryIds::new(),
                    'runtime_node_id' => $nodeId,
                    'capability_key' => $capability,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.capabilities_changed', ['capabilities' => $capabilities]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCredential(Request $request, string $tenantId, string $nodeId, array $input, ?IdempotencyKey $key = null): array
    {
        return $this->writeCredential($request, $tenantId, $nodeId, null, $input, $key, 'runtime_registry.credentials.create');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rotateCredential(Request $request, string $tenantId, string $nodeId, string $credentialId, array $input, ?IdempotencyKey $key = null): array
    {
        return $this->writeCredential($request, $tenantId, $nodeId, $credentialId, $input, $key, 'runtime_registry.credentials.rotate');
    }

    /**
     * @return array<string, mixed>
     */
    public function retireCredential(Request $request, string $tenantId, string $nodeId, string $credentialId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $credentialId): array {
            $this->nodeForUpdate($nodeId, $tenantId);
            $credential = DB::table('runtime_node_credentials')->where('id', $credentialId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
            abort_unless($credential !== null, 404, 'Credential not found.');
            if ($credential->status === 'active') {
                $activeCount = DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $credential->credential_type)->where('status', 'active')->count();
                if ($activeCount <= 1) {
                    throw new InvalidArgumentException('Cannot retire the last active credential of this type.');
                }
            }
            $updated = DB::table('runtime_node_credentials')->where('id', $credentialId)->where('runtime_node_id', $nodeId)->update(['status' => 'retired', 'updated_at' => now()]);
            abort_unless($updated === 1, 404, 'Credential not found.');
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.credential_retired', ['change' => 'retired', 'registry_item_id' => $credentialId, 'type' => $credential->credential_type]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function writeCredential(Request $request, string $tenantId, string $nodeId, ?string $previousCredentialId, array $input, ?IdempotencyKey $key, string $scope): array
    {
        $type = $this->slugLike($input['credential_type']);
        $fingerprint = [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'previous_id' => $previousCredentialId,
            'type' => $type,
            'identifier' => $input['identifier'] ?? null,
            'fingerprint_sha256' => $this->fingerprint($input['secret']),
        ];
        if ($key !== null && ($existing = $this->beginIdempotent($scope, $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $nodeId, $previousCredentialId, $input, $type): array {
            $this->nodeForUpdate($nodeId, $tenantId);
            $secret = trim((string) $input['secret']);
            if ($secret === '') {
                throw new InvalidArgumentException('Credential secret is required.');
            }
            if ($previousCredentialId !== null) {
                $previous = DB::table('runtime_node_credentials')->where('id', $previousCredentialId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
                abort_unless($previous !== null, 404, 'Credential not found.');
            }
            $version = ((int) DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $type)->max('version')) + 1;
            $id = RuntimeRegistryIds::new();
            DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $type)->where('status', 'active')->update(['status' => 'retired', 'updated_at' => now()]);
            DB::table('runtime_node_credentials')->insert([
                'id' => $id,
                'runtime_node_id' => $nodeId,
                'credential_type' => $type,
                'identifier' => $input['identifier'] ?? null,
                'encrypted_secret' => Crypt::encryptString($secret),
                'secret_fingerprint' => $this->fingerprint($secret),
                'version' => $version,
                'status' => 'active',
                'rotated_at' => now(),
                'expires_at' => $input['expires_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.credential_rotated', [
                'change' => $previousCredentialId === null ? 'created' : 'rotated',
                'registry_item_id' => $id,
                'type' => $type,
                'version' => $version,
            ]);

            return ['credential' => $this->credential($id, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });

        if ($key !== null) {
            $this->idempotency->complete($scope, $key, $result);
        }

        return $result;
    }

    private function nodeForUpdate(string $nodeId, string $tenantId): object
    {
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNode(object $node): array
    {
        return [
            'id' => $node->id,
            'tenant_id' => $node->tenant_id,
            'name' => $node->name,
            'slug' => $node->slug,
            'runtime_family' => $node->runtime_family,
            'adapter_key' => $node->adapter_key,
            'desired_state' => $node->desired_state,
            'observed_state' => $node->observed_state,
            'observed_at' => $node->observed_at,
            'configuration_version' => (int) $node->configuration_version,
            'placement' => [
                'region' => $node->placement_region,
                'zone' => $node->placement_zone,
                'priority' => (int) $node->placement_priority,
                'capacity_weight' => (int) $node->capacity_weight,
                'labels' => $node->labels === null ? [] : json_decode($node->labels, true, 512, JSON_THROW_ON_ERROR),
            ],
            'endpoints' => DB::table('runtime_node_endpoints')->where('runtime_node_id', $node->id)->orderBy('purpose')->orderBy('priority')->get()->map(fn (object $endpoint): array => $this->serializeEndpoint($endpoint))->all(),
            'credentials' => DB::table('runtime_node_credentials')->where('runtime_node_id', $node->id)->orderBy('credential_type')->orderByDesc('version')->get()->map(fn (object $credential): array => $this->serializeCredential($credential))->all(),
            'capabilities' => DB::table('runtime_node_capabilities')->where('runtime_node_id', $node->id)->orderBy('capability_key')->pluck('capability_key')->all(),
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endpoint(string $endpointId, string $tenantId): array
    {
        $endpoint = DB::table('runtime_node_endpoints')->join('runtime_nodes', 'runtime_nodes.id', '=', 'runtime_node_endpoints.runtime_node_id')
            ->where('runtime_node_endpoints.id', $endpointId)->where('runtime_nodes.tenant_id', $tenantId)->select('runtime_node_endpoints.*')->first();
        abort_unless($endpoint !== null, 404, 'Endpoint not found.');

        return $this->serializeEndpoint($endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function credential(string $credentialId, string $tenantId): array
    {
        $credential = DB::table('runtime_node_credentials')->join('runtime_nodes', 'runtime_nodes.id', '=', 'runtime_node_credentials.runtime_node_id')
            ->where('runtime_node_credentials.id', $credentialId)->where('runtime_nodes.tenant_id', $tenantId)->select('runtime_node_credentials.*')->first();
        abort_unless($credential !== null, 404, 'Credential not found.');

        return $this->serializeCredential($credential);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEndpoint(object $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'purpose' => $endpoint->purpose,
            'transport' => $endpoint->transport,
            'host' => $endpoint->host,
            'port' => (int) $endpoint->port,
            'path' => $endpoint->path,
            'tls_mode' => $endpoint->tls_mode,
            'priority' => (int) $endpoint->priority,
            'enabled' => (bool) $endpoint->enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCredential(object $credential): array
    {
        return [
            'id' => $credential->id,
            'type' => $credential->credential_type,
            'identifier' => $credential->identifier,
            'fingerprint' => $credential->secret_fingerprint,
            'version' => (int) $credential->version,
            'status' => $credential->status,
            'rotated_at' => $credential->rotated_at,
            'expires_at' => $credential->expires_at,
        ];
    }

    private function bumpNode(Request $request, string $nodeId, string $tenantId): void
    {
        DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update([
            'configuration_version' => DB::raw('configuration_version + 1'),
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(Request $request, string $tenantId, string $nodeId, string $eventType, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $this->audit->append($context, $eventType, 'runtime_node', $nodeId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($eventType, 1, 'runtime_node', $nodeId, $payload, $context));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function beginIdempotent(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try {
            $existing = $this->idempotency->begin($scope, $key, $payload);
        } catch (IdempotencyConflict) {
            abort(response()->json(['message' => 'Idempotency key conflict.'], 409));
        }
        if ($existing === null) {
            return null;
        }
        if ($existing->status === 'completed' && $existing->result !== null) {
            return $existing->result;
        }

        abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }

    private function normalizeSlug(string $slug): string
    {
        $value = Str::slug($slug);
        if ($value === '' || strlen($value) > 100) {
            throw new InvalidArgumentException('Invalid runtime-node slug.');
        }

        return $value;
    }

    private function slugLike(string $value): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $value)) {
            throw new InvalidArgumentException('Invalid credential type.');
        }

        return $value;
    }

    private function validateHost(string $host): string
    {
        if (str_contains($host, '@') || str_contains($host, '?') || str_contains($host, '/')) {
            throw new InvalidArgumentException('Endpoint host must not contain credentials, path, or query.');
        }
        if (! preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
            throw new InvalidArgumentException('Invalid endpoint host.');
        }

        return strtolower($host);
    }

    /**
     * @return array<string, string>
     */
    private function validatedLabels(mixed $labels): array
    {
        if ($labels === null || $labels === []) {
            return [];
        }
        if (! is_array($labels)) {
            throw new InvalidArgumentException('Labels must be an object.');
        }
        $validated = [];
        foreach ($labels as $key => $value) {
            if (! is_string($key) || ! is_string($value) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $key) || strlen($value) > 80) {
                throw new InvalidArgumentException('Invalid placement label.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    private function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
