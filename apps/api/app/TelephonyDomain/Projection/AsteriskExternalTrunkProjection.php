<?php

namespace App\TelephonyDomain\Projection;

use InvalidArgumentException;

/**
 * Renders the provider-local, file-backed PJSIP representation used by the
 * managed Asterisk runtime. The result is derived projection data, not an
 * editable Asterisk or canonical telephony resource.
 */
final class AsteriskExternalTrunkProjection
{
    /** @param array<string, mixed> $artifact */
    public function render(array $artifact): ?array
    {
        if (($artifact['desired_state'] ?? null) === 'removed') {
            return null;
        }

        $trunkId = (string) ($artifact['external_trunk_id'] ?? '');
        $providerId = (string) ($artifact['provider_local_trunk_id'] ?? '');
        if ($trunkId === '' || $providerId === '') {
            throw new InvalidArgumentException('Asterisk projection requires a canonical trunk identity.');
        }

        $endpoint = collect($artifact['endpoints'] ?? [])->first();
        if (! is_array($endpoint) || ! isset($endpoint['uri'], $endpoint['endpoint_id'])) {
            return [
                'canonical_external_trunk_id' => $trunkId,
                'endpoint_id' => $providerId,
                'aor_id' => $providerId.'-aor',
                'auth_id' => null,
                'pjsip' => [],
                'route_correlations' => $this->routeCorrelations($artifact),
            ];
        }

        $endpointId = $providerId;
        $aorId = $providerId.'-aor';
        $credentialReferenceId = $endpoint['credential_reference_id'] ?? null;

        return [
            'canonical_external_trunk_id' => $trunkId,
            'endpoint_id' => $endpointId,
            'aor_id' => $aorId,
            'auth_id' => $credentialReferenceId === null ? null : $providerId.'-auth',
            'credential_reference_id' => $credentialReferenceId,
            'credential_version' => $endpoint['credential_version'] ?? null,
            'endpoint_source_id' => $endpoint['endpoint_id'],
            'pjsip' => [
                'endpoint' => [
                    'type' => 'endpoint',
                    'transport' => 'transport-udp-internal',
                    'context' => 'from-kamailio',
                    'disallow' => 'all',
                    'allow' => 'ulaw',
                    'aors' => $aorId,
                ],
                'aor' => [
                    'type' => 'aor',
                    'contact' => (string) $endpoint['uri'],
                    'qualify_frequency' => '0',
                ],
            ],
            'route_correlations' => $this->routeCorrelations($artifact),
        ];
    }

    /** @param array<string, mixed> $artifact @return list<array<string, mixed>> */
    private function routeCorrelations(array $artifact): array
    {
        return array_map(static fn (array $route): array => [
            'route_id' => $route['route_id'] ?? null,
            'direction' => $route['direction'] ?? null,
            'telephony_address_id' => $route['address_id'] ?? null,
            'normalized_address' => $route['address'] ?? null,
            'destination_ref' => $route['destination_ref'] ?? null,
            'caller_identity_id' => $route['caller_identity_id'] ?? null,
        ], array_values(array_filter($artifact['routes'] ?? [], 'is_array')));
    }
}
