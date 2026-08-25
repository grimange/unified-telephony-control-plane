<?php

namespace App\TelephonyDomain\Signaling;

use App\TelephonyDomain\ExternalTrunkObservedHealthReconciler;
use Illuminate\Support\Facades\DB;

final class KamailioExternalTrunkRegistrationObserver
{
    /**
     * Kamailio uac_reg record flags. `uac.reg_dump` reports registration
     * progress through this bitmask; it does not publish a textual state.
     */
    private const FLAG_DISABLED = 1;

    private const FLAG_ONGOING = 2;

    private const FLAG_ONLINE = 4;

    private const FLAG_AUTHSENT = 8;

    public function __construct(
        private readonly KamailioRegistrationControlClient $control = new KamailioRegistrationControlClient,
        private readonly ExternalTrunkObservedHealthReconciler $health = new ExternalTrunkObservedHealthReconciler,
    ) {}

    public function pollTenant(string $tenantId): int
    {
        $this->ensureObservationRows($tenantId);
        $rows = $this->control->snapshot($tenantId);
        $count = 0;
        $trunkIds = [];
        foreach ($rows as $row) {
            $endpointId = (string) ($row['l_uuid'] ?? '');
            if ($endpointId === '') {
                continue;
            }
            $endpoint = DB::table('trunk_endpoints')->where('tenant_id', $tenantId)->where('id', $endpointId)->first();
            if ($endpoint === null) {
                continue;
            }
            $observation = DB::table('external_trunk_registration_observations')->where('tenant_id', $tenantId)->where('trunk_endpoint_id', $endpointId)->first();
            if ($observation === null) {
                DB::table('external_trunk_registration_observations')->insert([
                    'trunk_endpoint_id' => $endpointId,
                    'tenant_id' => $tenantId,
                    'external_trunk_id' => $endpoint->external_trunk_id,
                    'state' => 'not_configured',
                    'desired_generation' => (int) (DB::table('external_trunks')->where('id', $endpoint->external_trunk_id)->value('configuration_version') ?? 0),
                    'observation_version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $flags = (int) ($row['flags'] ?? 0);
            $expiresAt = $this->expiresAt($row);
            $state = $this->state($flags, $expiresAt);
            DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpointId)->update([
                'state' => $state,
                'failure_category' => $state === 'failed' ? 'unreachable' : null,
                'last_attempt_at' => now(),
                'last_success_at' => $state === 'registered' ? now() : DB::raw('last_success_at'),
                'expires_at' => $state === 'registered' ? $expiresAt : null,
                'contact_fingerprint' => $state === 'registered' && ($row['contact_addr'] ?? '') !== '' ? hash('sha256', (string) $row['contact_addr']) : null,
                'observation_version' => DB::raw('observation_version + 1'),
                'updated_at' => now(),
            ]);
            $trunkIds[] = (string) $endpoint->external_trunk_id;
            $count++;
        }

        foreach (array_values(array_unique(array_filter($trunkIds))) as $trunkId) {
            $this->health->reconcile($tenantId, $trunkId);
        }

        return $count;
    }

    /**
     * Establishes the baseline row for registration endpoints before the
     * first runtime poll. This belongs to the observation authority: T6
     * projects desired provider state and must not create or reset runtime
     * observations.
     */
    public function ensureObservationRows(string $tenantId, ?string $externalTrunkId = null): int
    {
        $endpoints = DB::table('trunk_endpoints')
            ->where('tenant_id', $tenantId)
            ->where('signaling_mode', 'outbound_registration')
            ->when($externalTrunkId !== null, fn ($query) => $query->where('external_trunk_id', $externalTrunkId))
            ->get(['id', 'external_trunk_id']);
        $created = 0;

        foreach ($endpoints as $endpoint) {
            $exists = DB::table('external_trunk_registration_observations')
                ->where('tenant_id', $tenantId)
                ->where('trunk_endpoint_id', $endpoint->id)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('external_trunk_registration_observations')->insert([
                'trunk_endpoint_id' => $endpoint->id,
                'tenant_id' => $tenantId,
                'external_trunk_id' => $endpoint->external_trunk_id,
                'state' => 'not_configured',
                'desired_generation' => (int) (DB::table('external_trunks')->where('id', $endpoint->external_trunk_id)->value('configuration_version') ?? 0),
                'observation_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /** @param array<string, mixed> $row */
    private function expiresAt(array $row): ?string
    {
        $timer = (int) ($row['timer_expires'] ?? 0);

        return $timer > 0 ? date('Y-m-d H:i:sP', $timer) : null;
    }

    private function state(int $flags, ?string $expiresAt): string
    {
        if (($flags & self::FLAG_DISABLED) !== 0) {
            return 'disabled';
        }
        if (($flags & self::FLAG_ONLINE) !== 0) {
            return $expiresAt !== null && strtotime($expiresAt) <= time() ? 'expired' : 'registered';
        }
        if (($flags & (self::FLAG_ONGOING | self::FLAG_AUTHSENT)) !== 0) {
            return 'registering';
        }

        return 'failed';
    }
}
