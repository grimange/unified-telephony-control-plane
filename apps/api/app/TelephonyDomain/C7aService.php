<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Identity\IdentityContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class C7aService
{
    private const TRUNK_STATES = ['draft', 'validating', 'active', 'draining', 'disabled', 'retired'];

    private const RESOURCE_STATES = ['draft', 'active', 'disabled', 'retired'];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
        private readonly TelephonyAddressNormalizer $addresses,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listTrunks(string $tenantId): array
    {
        return DB::table('external_trunks')->where('tenant_id', $tenantId)->orderBy('name')->get()->map(fn (object $row): array => $this->serializeTrunkDetails($row))->all();
    }

    /** @return array<string, mixed> */
    public function trunk(string $tenantId, string $id): array
    {
        return $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $id));
    }

    /** Canonical C7A eligibility fact consumed by C7B route evaluation. */
    public function routingEligibility(string $tenantId, string $id, string $direction): array
    {
        $trunk = $this->trunkForTenant($tenantId, $id);
        if ($trunk->desired_state !== 'active') {
            return ['eligible' => false, 'code' => 'external_trunk_inactive'];
        }
        if ($trunk->observed_health !== 'ready') {
            return ['eligible' => false, 'code' => 'external_trunk_not_ready'];
        }
        $endpoints = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('external_trunk_id', $id)->where('desired_state', 'active')->get();
        if ($endpoints->isEmpty()) {
            return ['eligible' => false, 'code' => 'external_trunk_endpoint_inactive'];
        }
        foreach ($endpoints as $endpoint) {
            if ($endpoint->signaling_mode === 'outbound_registration' && DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpoint->id)->where('state', 'registered')->doesntExist()) {
                return ['eligible' => false, 'code' => 'external_trunk_registration_not_ready'];
            }
        }

        $supported = $this->decode($trunk->supported_directions);
        if ($supported !== [] && ! in_array($direction, $supported, true)) {
            return ['eligible' => false, 'code' => 'external_trunk_direction_unsupported'];
        }

        return ['eligible' => true, 'code' => 'external_trunk_eligible'];
    }

    /** @param array<string, mixed> $input */
    public function createTrunk(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $slug = $this->slug($input['slug']);
        $fingerprint = ['tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $slug];
        if ($key !== null && ($existing = $this->beginIdempotent('c7a.external_trunks.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        try {
            $result = DB::transaction(function () use ($request, $tenantId, $input, $slug): array {
                $id = $this->id();
                DB::table('external_trunks')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $slug,
                    'description' => $input['description'] ?? null,
                    'supported_directions' => $this->json($input['supported_directions'] ?? []),
                    'capabilities' => $this->json($input['capabilities'] ?? []),
                    'desired_state' => 'draft', 'observed_health' => 'unknown', 'configuration_version' => 1,
                    'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->emit($request, $tenantId, $id, 'external_trunk.created', ['desired_state' => 'draft']);

                return ['external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $id))];
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
                throw new InvalidArgumentException('An external trunk with this name or slug already exists.', 0, $exception);
            }
            throw $exception;
        }
        if ($key !== null) {
            $this->idempotency->complete('c7a.external_trunks.create', $key, $result);
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    public function updateTrunk(Request $request, string $tenantId, string $id, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $id, $input): array {
            $trunk = $this->trunkForUpdate($tenantId, $id);
            $this->assertMutable($trunk->desired_state, 'External trunk');
            $update = ['updated_by' => $request->user()->id, 'updated_at' => now(), 'configuration_version' => ((int) $trunk->configuration_version) + 1];
            foreach (['name', 'description'] as $field) {
                if (array_key_exists($field, $input)) {
                    $update[$field] = $input[$field];
                }
            }
            if (array_key_exists('slug', $input)) {
                $update['slug'] = $this->slug($input['slug']);
            }
            foreach (['supported_directions', 'capabilities'] as $field) {
                if (array_key_exists($field, $input)) {
                    $update[$field] = $this->json($input[$field]);
                }
            }
            DB::table('external_trunks')->where('id', $id)->update($update);
            $this->emit($request, $tenantId, $id, 'external_trunk.updated', ['configuration_version' => $update['configuration_version']]);

            return ['external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $id))];
        });
    }

    /** @return array<string, mixed> */
    public function changeTrunkState(Request $request, string $tenantId, string $id, string $state): array
    {
        if (! in_array($state, self::TRUNK_STATES, true)) {
            throw new InvalidArgumentException('Invalid external trunk lifecycle state.');
        }

        return DB::transaction(function () use ($request, $tenantId, $id, $state): array {
            $trunk = $this->trunkForUpdate($tenantId, $id);
            if ($trunk->desired_state === 'retired' && $state !== 'retired') {
                throw new InvalidArgumentException('A retired external trunk cannot be reactivated.');
            }
            if ($state === 'active' && DB::table('trunk_endpoints')->where('external_trunk_id', $id)->where('desired_state', 'active')->doesntExist()) {
                throw new InvalidArgumentException('An external trunk needs an active endpoint before activation.');
            }
            DB::table('external_trunks')->where('id', $id)->update(['desired_state' => $state, 'configuration_version' => ((int) $trunk->configuration_version) + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'external_trunk.desired_state_changed', ['from' => $trunk->desired_state, 'to' => $state]);

            return ['external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $id))];
        });
    }

    /** @param array<string, mixed> $input */
    public function createCredential(Request $request, string $tenantId, string $trunkId, array $input, ?IdempotencyKey $key = null): array
    {
        $this->trunkForTenant($tenantId, $trunkId);
        $type = $this->slugLike($input['credential_type']);
        $fingerprint = ['tenant_id' => $tenantId, 'trunk_id' => $trunkId, 'type' => $type, 'identifier' => $input['identifier'] ?? null, 'fingerprint_sha256' => hash('sha256', trim($input['secret']))];
        if ($key !== null && ($existing = $this->beginIdempotent('c7a.trunk_credentials.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        $result = DB::transaction(function () use ($request, $tenantId, $trunkId, $input, $type): array {
            $secret = trim($input['secret']);
            if ($secret === '') {
                throw new InvalidArgumentException('Credential secret is required.');
            }
            $current = DB::table('trunk_credential_references')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunkId)->where('credential_type', $type)->where('status', 'active')->lockForUpdate()->get();
            $version = (int) DB::table('trunk_credential_references')->where('external_trunk_id', $trunkId)->where('credential_type', $type)->max('version') + 1;
            $id = $this->id();
            DB::table('trunk_credential_references')->insert(['id' => $id, 'tenant_id' => $tenantId, 'external_trunk_id' => $trunkId, 'credential_type' => $type, 'identifier' => $input['identifier'] ?? null, 'encrypted_secret' => Crypt::encryptString($secret), 'secret_fingerprint' => hash('sha256', $secret), 'version' => $version, 'status' => 'active', 'rotated_at' => now(), 'expires_at' => $input['expires_at'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($current as $old) {
                DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunkId)->where('credential_reference_id', $old->id)->update(['credential_reference_id' => $id, 'updated_at' => now()]);
                DB::table('trunk_credential_references')->where('id', $old->id)->update(['status' => 'retired', 'updated_at' => now()]);
            }
            $this->bumpTrunk($request, $tenantId, $trunkId);
            $this->emit($request, $tenantId, $trunkId, 'external_trunk.credential_rotated', ['registry_item_id' => $id, 'type' => $type, 'version' => $version]);

            return ['credential_reference' => $this->credential($tenantId, $id)];
        });
        if ($key !== null) {
            $this->idempotency->complete('c7a.trunk_credentials.create', $key, $result);
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    public function createEndpoint(Request $request, string $tenantId, string $trunkId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $trunkId, $input): array {
            $trunk = $this->trunkForUpdate($tenantId, $trunkId);
            $this->assertMutable($trunk->desired_state, 'External trunk');
            $uri = $this->normalizeEndpointUri($input['endpoint_uri']);
            $signalingMode = strtolower((string) ($input['signaling_mode'] ?? 'static'));
            $this->validateRegistrationIntent($signalingMode, $input);
            $credential = null;
            $authenticationMode = $input['authentication_mode'] ?? 'none';
            if ($authenticationMode === 'credentials') {
                $credential = DB::table('trunk_credential_references')->where('id', $input['credential_reference_id'] ?? '')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunkId)->where('status', 'active')->first();
                if ($credential === null) {
                    throw new InvalidArgumentException('An active credential reference for this trunk is required.');
                }
            }
            $id = $this->id();
            DB::table('trunk_endpoints')->insert(['id' => $id, 'tenant_id' => $tenantId, 'external_trunk_id' => $trunkId, 'endpoint_uri' => $uri, 'signaling_mode' => $signalingMode, 'transport' => strtolower($input['transport'] ?? 'udp'), 'authentication_mode' => $authenticationMode, 'credential_reference_id' => $credential?->id, 'registration_target' => $signalingMode === 'outbound_registration' ? $this->normalizeEndpointUri((string) $input['registration_target']) : null, 'registration_realm' => $signalingMode === 'outbound_registration' ? trim((string) $input['registration_realm']) : null, 'registration_identity' => $signalingMode === 'outbound_registration' ? trim((string) $input['registration_identity']) : null, 'capabilities' => $this->json($input['capabilities'] ?? []), 'desired_state' => 'active', 'priority' => $input['priority'] ?? 100, 'created_at' => now(), 'updated_at' => now()]);
            $this->bumpTrunk($request, $tenantId, $trunkId);
            $this->emit($request, $tenantId, $trunkId, 'external_trunk.endpoint_created', ['endpoint_id' => $id]);

            return ['endpoint' => $this->endpoint($tenantId, $id), 'external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $trunkId))];
        });
    }

    /** @return array<string, mixed> */
    public function changeEndpointState(Request $request, string $tenantId, string $trunkId, string $endpointId, string $state): array
    {
        $this->assertResourceState($state);

        return DB::transaction(function () use ($request, $tenantId, $trunkId, $endpointId, $state): array {
            $trunk = $this->trunkForUpdate($tenantId, $trunkId);
            $endpoint = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunkId)->where('id', $endpointId)->lockForUpdate()->first();
            abort_unless($endpoint !== null, 404, 'Trunk endpoint not found.');
            if ($endpoint->desired_state === 'retired' && $state !== 'retired') {
                throw new InvalidArgumentException('A retired trunk endpoint cannot be reactivated.');
            }
            DB::table('trunk_endpoints')->where('id', $endpointId)->update(['desired_state' => $state, 'updated_at' => now()]);
            $this->bumpTrunk($request, $tenantId, $trunk->id);
            $this->emit($request, $tenantId, $endpointId, 'external_trunk.endpoint_desired_state_changed', ['from' => $endpoint->desired_state, 'to' => $state]);

            return ['endpoint' => $this->endpoint($tenantId, $endpointId), 'external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $trunkId))];
        });
    }

    /** @param array<string, mixed> $input */
    public function attachAddress(Request $request, string $tenantId, string $trunkId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $trunkId, $input): array {
            $trunk = $this->trunkForUpdate($tenantId, $trunkId);
            $address = $this->addressForTenant($tenantId, $input['telephony_address_id']);
            if ($trunk->desired_state === 'retired' || $address->desired_state === 'retired') {
                throw new InvalidArgumentException('Retired authorities cannot be associated.');
            }
            if ($address->desired_state !== 'active') {
                throw new InvalidArgumentException('Only active telephony addresses can be associated.');
            }
            $direction = $input['direction'];
            $supported = $this->decode($trunk->supported_directions);
            if ($supported !== [] && $direction !== 'both' && ! in_array($direction, $supported, true)) {
                throw new InvalidArgumentException('The address direction is not supported by this external trunk.');
            }
            DB::table('external_trunk_addresses')->insert(['external_trunk_id' => $trunkId, 'telephony_address_id' => $address->id, 'direction' => $direction, 'created_at' => now(), 'updated_at' => now()]);
            $this->bumpTrunk($request, $tenantId, $trunkId);
            $this->emit($request, $tenantId, $trunkId, 'external_trunk.address_attached', ['telephony_address_id' => $address->id, 'direction' => $direction]);

            return ['external_trunk' => $this->serializeTrunkDetails($this->trunkForTenant($tenantId, $trunkId))];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listAddresses(string $tenantId): array
    {
        return DB::table('telephony_addresses')->where('tenant_id', $tenantId)->orderBy('normalized_value')->get()->map(fn (object $row): array => $this->serializeAddress($row))->all();
    }

    /** @param array<string, mixed> $input */
    public function createAddress(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $normalized = $this->addresses->normalize($input['address_type'], $input['value']);
        $fingerprint = ['tenant_id' => $tenantId, ...$normalized];
        if ($key !== null && ($existing = $this->beginIdempotent('c7a.telephony_addresses.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        try {
            $result = DB::transaction(function () use ($request, $tenantId, $normalized): array {
                $id = $this->id();
                DB::table('telephony_addresses')->insert(['id' => $id, 'tenant_id' => $tenantId, 'address_type' => $normalized['type'], 'normalized_value' => $normalized['normalized_value'], 'desired_state' => 'active', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->emit($request, $tenantId, $id, 'telephony_address.created', ['address_type' => $normalized['type']]);

                return ['telephony_address' => $this->address($tenantId, $id)];
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
                throw new InvalidArgumentException('This telephony address already exists for the tenant.', 0, $exception);
            }
            throw $exception;
        }
        if ($key !== null) {
            $this->idempotency->complete('c7a.telephony_addresses.create', $key, $result);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function address(string $tenantId, string $id): array
    {
        return $this->serializeAddress($this->addressForTenant($tenantId, $id));
    }

    /** @return array<string, mixed> */
    public function changeAddressState(Request $request, string $tenantId, string $id, string $state): array
    {
        $this->assertResourceState($state);
        $address = $this->addressForTenant($tenantId, $id);
        if ($address->desired_state === 'retired' && $state !== 'retired') {
            throw new InvalidArgumentException('A retired telephony address cannot be reactivated.');
        }

        return DB::transaction(function () use ($request, $tenantId, $id, $state, $address): array {
            DB::table('telephony_addresses')->where('id', $id)->update(['desired_state' => $state, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'telephony_address.desired_state_changed', ['from' => $address->desired_state, 'to' => $state]);

            return ['telephony_address' => $this->address($tenantId, $id)];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listCallerIdentities(string $tenantId): array
    {
        return DB::table('caller_identities')->where('tenant_id', $tenantId)->orderBy('name')->get()->map(fn (object $row): array => $this->callerIdentity($tenantId, $row->id))->all();
    }

    /** @param array<string, mixed> $input */
    public function createCallerIdentity(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $address = $this->addressForTenant($tenantId, $input['telephony_address_id']);
        if ($address->desired_state !== 'active') {
            throw new InvalidArgumentException('Caller identity requires an active telephony address.');
        }
        $fingerprint = ['tenant_id' => $tenantId, 'name' => $input['name'], 'telephony_address_id' => $address->id];
        if ($key !== null && ($existing = $this->beginIdempotent('c7a.caller_identities.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        $result = DB::transaction(function () use ($request, $tenantId, $input, $address): array {
            $id = $this->id();
            DB::table('caller_identities')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => $input['name'], 'telephony_address_id' => $address->id, 'display_name' => $input['display_name'] ?? null, 'desired_state' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'caller_identity.created', ['telephony_address_id' => $address->id]);

            return ['caller_identity' => $this->callerIdentity($tenantId, $id)];
        });
        if ($key !== null) {
            $this->idempotency->complete('c7a.caller_identities.create', $key, $result);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function callerIdentity(string $tenantId, string $id): array
    {
        $row = DB::table('caller_identities')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Caller identity not found.');

        return [...$this->serializeCallerIdentity($row), 'policies' => DB::table('caller_identity_policies as p')->join('external_trunks as t', 't.id', '=', 'p.external_trunk_id')->where('p.tenant_id', $tenantId)->where('p.caller_identity_id', $id)->orderBy('t.name')->get()->map(fn (object $policy): array => ['id' => $policy->id, 'external_trunk_id' => $policy->external_trunk_id, 'external_trunk_name' => $policy->name, 'desired_state' => $policy->desired_state])->all()];
    }

    /** @return array<string, mixed> */
    public function changeCallerIdentityState(Request $request, string $tenantId, string $id, string $state): array
    {
        $this->assertResourceState($state);
        $identity = DB::table('caller_identities')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($identity !== null, 404, 'Caller identity not found.');
        if ($identity->desired_state === 'retired' && $state !== 'retired') {
            throw new InvalidArgumentException('A retired caller identity cannot be reactivated.');
        }
        if ($state === 'active' && DB::table('telephony_addresses')->where('id', $identity->telephony_address_id)->where('desired_state', 'active')->doesntExist()) {
            throw new InvalidArgumentException('Caller identity requires an active telephony address.');
        }

        return DB::transaction(function () use ($request, $tenantId, $id, $state, $identity): array {
            DB::table('caller_identities')->where('id', $id)->update(['desired_state' => $state, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'caller_identity.desired_state_changed', ['from' => $identity->desired_state, 'to' => $state]);

            return ['caller_identity' => $this->callerIdentity($tenantId, $id)];
        });
    }

    /** @param array<string, mixed> $input */
    public function createPolicy(Request $request, string $tenantId, string $identityId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $identityId, $input): array {
            $identity = DB::table('caller_identities')->where('id', $identityId)->where('tenant_id', $tenantId)->first();
            abort_unless($identity !== null, 404, 'Caller identity not found.');
            $trunk = $this->trunkForUpdate($tenantId, $input['external_trunk_id']);
            if ($identity->desired_state === 'retired' || $trunk->desired_state === 'retired') {
                throw new InvalidArgumentException('Retired authorities cannot receive caller-identity policy.');
            }
            $id = $this->id();
            DB::table('caller_identity_policies')->insert(['id' => $id, 'tenant_id' => $tenantId, 'caller_identity_id' => $identityId, 'external_trunk_id' => $trunk->id, 'desired_state' => 'active', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'caller_identity_policy.created', ['caller_identity_id' => $identityId, 'external_trunk_id' => $trunk->id]);

            return ['caller_identity' => $this->callerIdentity($tenantId, $identityId)];
        });
    }

    private function trunkForTenant(string $tenantId, string $id): object
    {
        $row = DB::table('external_trunks')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'External trunk not found.');

        return $row;
    }

    private function trunkForUpdate(string $tenantId, string $id): object
    {
        $row = DB::table('external_trunks')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row !== null, 404, 'External trunk not found.');

        return $row;
    }

    private function addressForTenant(string $tenantId, string $id): object
    {
        $row = DB::table('telephony_addresses')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Telephony address not found.');

        return $row;
    }

    private function assertMutable(string $state, string $label): void
    {
        if (in_array($state, ['draining', 'retired'], true)) {
            throw new InvalidArgumentException($label.' cannot be changed in its current lifecycle state.');
        }
    }

    private function assertResourceState(string $state): void
    {
        if (! in_array($state, self::RESOURCE_STATES, true)) {
            throw new InvalidArgumentException('Invalid lifecycle state.');
        }
    }

    private function id(): string
    {
        return (string) Str::uuid();
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value);
        if ($slug === '' || strlen($slug) > 100) {
            throw new InvalidArgumentException('Invalid slug.');
        }

return $slug;
    }

    private function slugLike(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/', $value)) {
            throw new InvalidArgumentException('Invalid credential type.');
        }

return $value;
    }

    private function normalizeEndpointUri(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^(sips?):(?:[^@\s]+@)?([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('External trunk endpoint must be a valid SIP URI.');
        }

        $port = $matches[3] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            throw new InvalidArgumentException('SIP endpoint port is invalid.');
        }

        $authority = substr($value, strpos($value, ':') + 1);
        $user = str_contains($authority, '@') ? substr($authority, 0, strrpos($authority, '@') + 1) : '';

        return strtolower($matches[1]).':'.$user.strtolower($matches[2]).($port === '' ? '' : ':'.$port);
    }

    private function json(array $value): ?string
    {
        return $value === [] ? null : json_encode(array_values($value), JSON_THROW_ON_ERROR);
    }

    private function bumpTrunk(Request $request, string $tenantId, string $id): void
    {
        DB::table('external_trunks')->where('tenant_id', $tenantId)->where('id', $id)->update(['configuration_version' => DB::raw('configuration_version + 1'), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
    }

    private function serializeTrunkDetails(object $row): array
    {
        $activeEndpoint = DB::table('trunk_endpoints')->where('external_trunk_id', $row->id)->where('desired_state', 'active')->exists();

        return [...$this->serializeTrunk($row), 'ready' => $row->desired_state === 'active' && $row->observed_health === 'ready', 'eligible_for_future_use' => $row->desired_state === 'active' && $row->observed_health === 'ready' && $activeEndpoint, 'endpoints' => DB::table('trunk_endpoints')->where('external_trunk_id', $row->id)->orderBy('priority')->get()->map(fn (object $endpoint): array => $this->serializeEndpoint($endpoint))->all(), 'credential_references' => DB::table('trunk_credential_references')->where('external_trunk_id', $row->id)->orderBy('credential_type')->orderByDesc('version')->get()->map(fn (object $credential): array => $this->serializeCredential($credential))->all(), 'addresses' => DB::table('external_trunk_addresses as ta')->join('telephony_addresses as a', 'a.id', '=', 'ta.telephony_address_id')->where('ta.external_trunk_id', $row->id)->orderBy('a.normalized_value')->get()->map(fn (object $address): array => ['id' => $address->telephony_address_id, 'type' => $address->address_type, 'value' => $address->normalized_value, 'direction' => $address->direction, 'desired_state' => $address->desired_state])->all()];
    }

    private function serializeTrunk(object $row): array
    {
        return ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'slug' => $row->slug, 'description' => $row->description, 'supported_directions' => $this->decode($row->supported_directions), 'capabilities' => $this->decode($row->capabilities), 'desired_state' => $row->desired_state, 'observed_health' => $row->observed_health, 'observed_health_reason' => $row->observed_health_reason, 'configuration_version' => (int) $row->configuration_version];
    }

    private function serializeAddress(object $row): array
    {
        return ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'type' => $row->address_type, 'value' => $row->normalized_value, 'desired_state' => $row->desired_state];
    }

    private function serializeCallerIdentity(object $row): array
    {
        return ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'telephony_address_id' => $row->telephony_address_id, 'telephony_address' => $this->serializeAddress($this->addressForTenant($row->tenant_id, $row->telephony_address_id)), 'display_name' => $row->display_name, 'desired_state' => $row->desired_state];
    }

    private function endpoint(string $tenantId, string $id): array
    {
        $row = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Trunk endpoint not found.');

        return $this->serializeEndpoint($row);
    }

    private function serializeEndpoint(object $row): array
    {
        $observation = null;
        if (($row->signaling_mode ?? 'static') === 'outbound_registration') {
            $observation = DB::table('external_trunk_registration_observations')
                ->where('tenant_id', $row->tenant_id)
                ->where('trunk_endpoint_id', $row->id)
                ->first();
        }

        return ['id' => $row->id, 'external_trunk_id' => $row->external_trunk_id, 'endpoint_uri' => $row->endpoint_uri, 'signaling_mode' => $row->signaling_mode ?? 'static', 'transport' => $row->transport, 'authentication_mode' => $row->authentication_mode, 'credential_reference_id' => $row->credential_reference_id, 'registration_target' => $row->registration_target ?? null, 'registration_realm' => $row->registration_realm ?? null, 'registration_identity' => $row->registration_identity ?? null, 'registration_observation' => $observation === null ? null : ['state' => $observation->state, 'failure_category' => $observation->failure_category, 'last_attempt_at' => $observation->last_attempt_at, 'last_success_at' => $observation->last_success_at, 'expires_at' => $observation->expires_at, 'observed_at' => $observation->updated_at, 'observation_version' => (int) $observation->observation_version], 'capabilities' => $this->decode($row->capabilities), 'desired_state' => $row->desired_state, 'priority' => (int) $row->priority];
    }

    /** @param array<string, mixed> $input */
    private function validateRegistrationIntent(string $mode, array $input): void
    {
        if (! in_array($mode, ['static', 'outbound_registration'], true)) {
            throw new InvalidArgumentException('Invalid external trunk signaling mode.');
        }
        $registrationFields = ['registration_target', 'registration_realm', 'registration_identity'];
        if ($mode === 'static' && array_filter($registrationFields, fn (string $field): bool => isset($input[$field]) && trim((string) $input[$field]) !== '') !== []) {
            throw new InvalidArgumentException('Static endpoints cannot include registration intent.');
        }
        if ($mode !== 'outbound_registration') {
            return;
        }
        if (($input['authentication_mode'] ?? 'none') !== 'credentials' || empty($input['credential_reference_id'])) {
            throw new InvalidArgumentException('Outbound registration requires credential authentication and a credential reference.');
        }
        foreach ($registrationFields as $field) {
            if (! isset($input[$field]) || trim((string) $input[$field]) === '') {
                throw new InvalidArgumentException('Outbound registration requires '.$field.'.');
            }
        }
    }

    private function credential(string $tenantId, string $id): array
    {
        $row = DB::table('trunk_credential_references')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Credential reference not found.');

        return $this->serializeCredential($row);
    }

    private function serializeCredential(object $row): array
    {
        return ['id' => $row->id, 'credential_type' => $row->credential_type, 'identifier' => $row->identifier, 'secret_fingerprint' => $row->secret_fingerprint, 'version' => (int) $row->version, 'status' => $row->status, 'rotated_at' => $row->rotated_at, 'expires_at' => $row->expires_at];
    }

    private function decode(?string $value): array
    {
        return $value === null ? [] : (json_decode($value, true, 512, JSON_THROW_ON_ERROR) ?: []);
    }

    private function emit(Request $request, string $tenantId, string $id, string $event, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $this->audit->append($context, $event, 'c7a_authority', $id, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, 'c7a_authority', $id, $payload, $context));
    }

    private function beginIdempotent(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try {
            $existing = $this->idempotency->begin($scope, $key, $payload);
        } catch (IdempotencyConflict) {
            abort(response()->json(['message' => 'Idempotency key conflict.'], 409));
        } if ($existing === null) {
            return null;
        } if ($existing->status === 'completed' && $existing->result !== null) {
            return $existing->result;
        } abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }
}
