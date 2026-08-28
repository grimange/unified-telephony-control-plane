<?php

namespace App\TelephonyDomain\Projection;

use App\ControlPlane\Shared\StableJson;
use App\TelephonyDomain\RouteDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * T6 derived projection authority. It renders canonical C7A/C7B state into
 * provider-local artifacts; it is not an editable telephony configuration.
 */
final class ExternalTrunkProjectionService
{
    private const PROVIDERS = ['kamailio', 'asterisk'];

    public function __construct(
        private readonly AsteriskExternalTrunkProjection $asteriskProjection = new AsteriskExternalTrunkProjection,
    ) {}

    /** @return list<string> */
    public function providers(): array
    {
        return self::PROVIDERS;
    }

    /** @return list<array<string, mixed>> */
    public function projectTenant(string $tenantId): array
    {
        return DB::transaction(function () use ($tenantId): array {
            $trunks = DB::table('external_trunks')->where('tenant_id', $tenantId)->orderBy('id')->get();
            $activeKeys = [];
            $results = [];

            foreach ($trunks as $trunk) {
                foreach (self::PROVIDERS as $provider) {
                    $artifact = $this->render($tenantId, $trunk, $provider);
                    $projectionKey = 'external-trunk:'.$trunk->id;
                    $activeKeys[] = $trunk->id.'|'.$provider.'|'.$projectionKey;
                    $results[] = $this->upsert($tenantId, $trunk, $provider, $projectionKey, $artifact);
                }
                $this->projectKamailioRegistrations($tenantId, $trunk);
            }

            DB::table('external_trunk_projection_artifacts')
                ->where('tenant_id', $tenantId)
                ->get()
                ->each(function (object $row) use ($activeKeys): void {
                    $key = $row->external_trunk_id.'|'.$row->provider.'|'.$row->projection_key;
                    if (in_array($key, $activeKeys, true)) {
                        return;
                    }
                    DB::table('external_trunk_projection_artifacts')->where('id', $row->id)->update([
                        'desired_state' => 'removed',
                        'artifact' => json_encode(['schema' => 'utcp.t6.projection.v1', 'desired_state' => 'removed'], JSON_THROW_ON_ERROR),
                        'artifact_hash' => hash('sha256', 'removed'),
                        'observed_state' => 'projected',
                        'projected_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

            return $results;
        });
    }

    /**
     * Translate an already selected RouteDecision. This method never evaluates
     * routes and therefore cannot silently select another trunk.
     *
     * @return array<string, mixed>
     */
    public function executionIntent(string $tenantId, RouteDecision $decision, string $provider): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unsupported T6 projection provider.');
        }
        $data = $decision->toArray();
        if ($data['status'] !== 'selected' || $data['external_trunk_id'] === null) {
            throw new InvalidArgumentException('A selected route decision is required.');
        }
        $projection = DB::table('external_trunk_projection_artifacts')
            ->where('tenant_id', $tenantId)
            ->where('external_trunk_id', $data['external_trunk_id'])
            ->where('provider', $provider)
            ->where('desired_state', '!=', 'removed')
            ->first();
        if ($projection === null) {
            throw new InvalidArgumentException('External trunk projection is unavailable.');
        }

        $artifact = json_decode((string) $projection->artifact, true, 512, JSON_THROW_ON_ERROR);
        $localId = $this->providerLocalId($provider, (string) $data['external_trunk_id']);

        $artifact = [
            'provider' => $provider,
            'projection_key' => $projection->projection_key,
            'provider_local_trunk_id' => $localId,
            'route_id' => $data['route_id'],
            'destination_ref' => $data['destination_ref'],
            'caller_identity_id' => $data['caller_identity_id'],
            'artifact_hash' => $projection->artifact_hash,
            'provider_target' => $provider === 'asterisk'
                ? ['endpoint_id' => $localId, 'route_id' => $data['route_id']]
                : ['trunk_key' => $localId, 'route_id' => $data['route_id']],
            'artifact_generation' => (int) $projection->desired_generation,
            'artifact_schema' => $artifact['schema'] ?? null,
        ];

        return $artifact;
    }

    /** @return array<string, mixed> */
    private function render(string $tenantId, object $trunk, string $provider): array
    {
        $state = (string) $trunk->desired_state;
        $removed = in_array($state, ['draft', 'validating', 'disabled', 'retired'], true);
        $endpoints = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunk->id)->where('desired_state', 'active')->orderBy('priority')->orderBy('id')->get();
        $addresses = DB::table('external_trunk_addresses as ta')
            ->join('telephony_addresses as a', 'a.id', '=', 'ta.telephony_address_id')
            ->select([
                'ta.telephony_address_id',
                'ta.direction',
                'a.address_type',
                'a.normalized_value',
            ])
            ->where('ta.external_trunk_id', $trunk->id)
            ->where('a.desired_state', 'active')
            ->orderBy('a.normalized_value')
            ->get();
        $credentialIds = $endpoints->pluck('credential_reference_id')->filter()->unique()->values()->all();
        $credentials = DB::table('trunk_credential_references')->whereIn('id', $credentialIds)->where('status', 'active')->get()->keyBy('id');
        $routes = $this->routes($tenantId, (string) $trunk->id, $removed);

        $artifact = [
            'schema' => 'utcp.t6.projection.v1',
            'provider' => $provider,
            'external_trunk_id' => $trunk->id,
            'desired_state' => $removed ? 'removed' : $state,
            'accept_new_calls' => ! $removed && $state === 'active',
            'provider_local_trunk_id' => $this->providerLocalId($provider, (string) $trunk->id),
            'endpoints' => $endpoints->map(function (object $endpoint) use ($credentials): array {
                $credential = $endpoint->credential_reference_id === null ? null : $credentials->get($endpoint->credential_reference_id);

                return [
                    'endpoint_id' => $endpoint->id,
                    'uri' => $endpoint->endpoint_uri,
                    'signaling_mode' => $endpoint->signaling_mode ?? 'static',
                    'registration_target' => $endpoint->registration_target ?? null,
                    'registration_realm' => $endpoint->registration_realm ?? null,
                    'registration_identity' => $endpoint->registration_identity ?? null,
                    'transport' => $endpoint->transport,
                    'authentication_mode' => $endpoint->authentication_mode,
                    'credential_reference_id' => $credential?->id,
                    'credential_version' => $credential?->version === null ? null : (int) $credential->version,
                ];
            })->all(),
            'addresses' => $addresses->map(fn (object $address): array => [
                'telephony_address_id' => $address->telephony_address_id,
                'type' => $address->address_type,
                'normalized_value' => $address->normalized_value,
                'direction' => $address->direction,
            ])->all(),
            'routes' => $routes,
        ];

        if ($provider === 'asterisk') {
            $artifact['provider_representation'] = $this->asteriskProjection->render($artifact);
        }

        return $artifact;
    }

    /** @return list<array<string, mixed>> */
    private function routes(string $tenantId, string $trunkId, bool $removed): array
    {
        if ($removed) {
            return [];
        }

        $inbound = DB::table('inbound_routes as r')
            ->join('telephony_addresses as a', 'a.id', '=', 'r.telephony_address_id')
            ->select([
                'r.id as route_id',
                'r.priority',
                'r.telephony_address_id',
                'r.destination_ref',
                'a.normalized_value',
            ])
            ->where('r.tenant_id', $tenantId)->where('r.external_trunk_id', $trunkId)->where('r.desired_state', 'active')->orderBy('r.priority')->orderBy('r.id')->get()
            ->map(fn (object $route): array => ['direction' => 'inbound', 'route_id' => $route->route_id, 'priority' => (int) $route->priority, 'address_id' => $route->telephony_address_id, 'address' => $route->normalized_value, 'destination_user' => $this->destinationUser((string) $route->normalized_value), 'destination_ref' => $route->destination_ref, 'caller_identity_id' => null])->all();
        $outbound = DB::table('outbound_routes as r')
            ->join('telephony_addresses as a', 'a.id', '=', 'r.telephony_address_id')
            ->select([
                'r.id as route_id',
                'r.priority',
                'r.telephony_address_id',
                'r.destination_ref',
                'r.caller_identity_id',
                'a.normalized_value',
            ])
            ->where('r.tenant_id', $tenantId)->where('r.external_trunk_id', $trunkId)->where('r.desired_state', 'active')->orderBy('r.priority')->orderBy('r.id')->get()
            ->map(fn (object $route): array => ['direction' => 'outbound', 'route_id' => $route->route_id, 'priority' => (int) $route->priority, 'address_id' => $route->telephony_address_id, 'address' => $route->normalized_value, 'destination_user' => $this->destinationUser((string) $route->normalized_value), 'destination_ref' => 'telephony_address:'.$route->telephony_address_id, 'caller_identity_id' => $route->caller_identity_id])->all();

        return [...$inbound, ...$outbound];
    }

    /** @return array<string, mixed> */
    private function upsert(string $tenantId, object $trunk, string $provider, string $projectionKey, array $artifact): array
    {
        $encoded = StableJson::encode($artifact);
        $hash = hash('sha256', $encoded);
        $existing = DB::table('external_trunk_projection_artifacts')->where('external_trunk_id', $trunk->id)->where('provider', $provider)->where('projection_key', $projectionKey)->first();
        $values = [
            'tenant_id' => $tenantId,
            'external_trunk_id' => $trunk->id,
            'provider' => $provider,
            'projection_key' => $projectionKey,
            'desired_state' => $artifact['desired_state'],
            'desired_generation' => (int) $trunk->configuration_version,
            'artifact' => $encoded,
            'artifact_hash' => $hash,
            'observed_state' => 'projected',
            'failure_code' => null,
            'failure_message' => null,
            'projected_at' => now(),
            'updated_at' => now(),
        ];
        if ($existing === null) {
            $values['id'] = (string) Str::uuid();
            $values['created_at'] = now();
            DB::table('external_trunk_projection_artifacts')->insert($values);
        } else {
            DB::table('external_trunk_projection_artifacts')->where('id', $existing->id)->update($values);
        }

        return [...$values, 'id' => $existing?->id ?? $values['id']];
    }

    private function providerLocalId(string $provider, string $trunkId): string
    {
        return 'utcp-'.$provider.'-'.str_replace('-', '', strtolower($trunkId));
    }

    private function destinationUser(string $normalizedAddress): string
    {
        if (preg_match('/^sips?:([^@]+)@/i', $normalizedAddress, $matches) === 1) {
            return $matches[1];
        }

        return $normalizedAddress;
    }

    private function projectKamailioRegistrations(string $tenantId, object $trunk): void
    {
        $endpoints = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunk->id)->where('desired_state', 'active')->where('signaling_mode', 'outbound_registration')->get();
        $keep = [];
        $credentials = DB::table('trunk_credential_references')->whereIn('id', $endpoints->pluck('credential_reference_id')->filter()->unique()->all())->where('status', 'active')->get()->keyBy('id');
        foreach ($endpoints as $endpoint) {
            if ($trunk->desired_state !== 'active' && $trunk->desired_state !== 'draining') {
                continue;
            }
            $credential = $credentials->get($endpoint->credential_reference_id);
            if ($credential === null || trim((string) $endpoint->registration_realm) === '') {
                throw new InvalidArgumentException('Registration projection requires an active credential and realm.');
            }
            $cleartext = Crypt::decryptString((string) $credential->encrypted_secret);
            $target = $this->registrationTarget((string) $endpoint->registration_target);
            $values = [
                'trunk_endpoint_id' => $endpoint->id,
                'tenant_id' => $tenantId,
                'external_trunk_id' => $trunk->id,
                'l_uuid' => $endpoint->id,
                'l_username' => (string) $endpoint->registration_identity,
                'l_domain' => (string) $endpoint->registration_realm,
                'r_username' => ($target['username'] ?? '') !== '' ? $target['username'] : (string) $endpoint->registration_identity,
                'r_domain' => $target['domain'],
                'realm' => (string) $endpoint->registration_realm,
                'auth_username' => (string) ($credential->identifier ?: $endpoint->registration_identity),
                'auth_password' => '',
                'auth_ha1' => md5((string) ($credential->identifier ?: $endpoint->registration_identity).':'.$endpoint->registration_realm.':'.$cleartext),
                'auth_proxy' => $endpoint->registration_target,
                'expires' => 120,
                'flags' => 0,
                'reg_delay' => 0,
                'contact_addr' => null,
                'socket' => null,
                'credential_reference_id' => $credential->id,
                'credential_version' => (int) $credential->version,
                'desired_generation' => (int) $trunk->configuration_version,
                'desired_state' => $trunk->desired_state,
                'updated_at' => now(),
            ];
            $existing = DB::table('kamailio_external_trunk_registration_view')->where('trunk_endpoint_id', $endpoint->id)->first();
            if ($existing === null) {
                $values['created_at'] = now();
                DB::table('kamailio_external_trunk_registration_view')->insert($values);
            } else {
                DB::table('kamailio_external_trunk_registration_view')->where('trunk_endpoint_id', $endpoint->id)->update($values);
            }
            $keep[] = $endpoint->id;
        }
        $query = DB::table('kamailio_external_trunk_registration_view')->where('external_trunk_id', $trunk->id);
        if ($keep === []) {
            $query->delete();
        } else {
            $query->whereNotIn('trunk_endpoint_id', $keep)->delete();
        }
        // Registration observations are runtime-owned. T6 only projects the
        // desired provider representation; the registration observer creates
        // and advances observation rows through its own authority.
    }

    /** @return array{username:?string,domain:string} */
    private function registrationTarget(string $uri): array
    {
        $authority = substr($uri, strpos($uri, ':') + 1);
        $parts = explode('@', $authority, 2);
        $host = $parts[count($parts) - 1];
        $username = count($parts) === 2 ? $parts[0] : null;
        $host = explode(':', $host, 2)[0];
        return ['username' => $username, 'domain' => $host];
    }
}
