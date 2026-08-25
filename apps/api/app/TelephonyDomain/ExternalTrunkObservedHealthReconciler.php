<?php

namespace App\TelephonyDomain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C7A observed-health authority. This service derives trunk health only from
 * runtime observations; desired state remains owned by C7A management flows.
 */
final class ExternalTrunkObservedHealthReconciler
{
    /** @return array{health:string,reason:?string} */
    public function reconcile(string $tenantId, string $trunkId): array
    {
        return DB::transaction(function () use ($tenantId, $trunkId): array {
            $trunk = DB::table('external_trunks')
                ->where('tenant_id', $tenantId)
                ->where('id', $trunkId)
                ->lockForUpdate()
                ->first();

            if ($trunk === null) {
                return ['health' => 'unknown', 'reason' => 'external_trunk_missing'];
            }

            [$health, $reason] = $this->derive($trunk);
            $changed = $trunk->observed_health !== $health || $trunk->observed_health_reason !== $reason;
            if ($changed) {
                DB::table('external_trunks')->where('id', $trunkId)->update([
                    'observed_health' => $health,
                    'observed_health_reason' => $reason,
                    'updated_at' => now(),
                ]);
                Log::info('external trunk observed health reconciled', [
                    'component' => 'c7a-external-trunk-health-reconciler',
                    'tenant_id' => $tenantId,
                    'external_trunk_id' => $trunkId,
                    'from' => $trunk->observed_health,
                    'to' => $health,
                    'reason' => $reason,
                ]);
            }

            return ['health' => $health, 'reason' => $reason];
        });
    }

    /** @return array{0:string,1:?string} */
    private function derive(object $trunk): array
    {
        if (in_array($trunk->desired_state, ['disabled', 'retired'], true)) {
            return ['unavailable', 'external_trunk_'.$trunk->desired_state];
        }

        $endpoints = DB::table('trunk_endpoints')
            ->where('tenant_id', $trunk->tenant_id)
            ->where('external_trunk_id', $trunk->id)
            ->where('desired_state', 'active')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($endpoints->isEmpty()) {
            return ['unknown', 'no_applicable_endpoint'];
        }

        $registrationIds = $endpoints->where('signaling_mode', 'outbound_registration')->pluck('id')->all();
        // Static endpoints have no established authoritative runtime-health
        // source in V1-A. Preserve their existing observed value until one
        // exists; never infer readiness from configuration alone.
        if ($registrationIds === []) {
            return [(string) $trunk->observed_health, $trunk->observed_health_reason];
        }
        $observations = DB::table('external_trunk_registration_observations')
            ->where('tenant_id', $trunk->tenant_id)
            ->whereIn('trunk_endpoint_id', $registrationIds)
            ->get()
            ->keyBy('trunk_endpoint_id');

        $hasReady = false;
        $hasDegraded = false;
        $hasMissing = false;
        $allUnavailable = true;

        foreach ($endpoints as $endpoint) {
            if ($endpoint->signaling_mode !== 'outbound_registration') {
                continue;
            }

            $observation = $observations->get($endpoint->id);
            if ($observation === null || $observation->state === 'not_configured') {
                $hasMissing = true;
                $allUnavailable = false;
                continue;
            }

            if ($observation->state === 'registered') {
                $hasReady = true;
                $allUnavailable = false;
            } elseif ($observation->state === 'registering') {
                $hasDegraded = true;
                $allUnavailable = false;
            } elseif (! in_array($observation->state, ['failed', 'expired', 'disabled'], true)) {
                $hasMissing = true;
                $allUnavailable = false;
            }
        }

        if ($hasReady) {
            return ['ready', 'registration_endpoint_registered'];
        }
        if ($hasDegraded) {
            return ['degraded', 'registration_endpoint_registering'];
        }
        if ($hasMissing) {
            return ['unknown', 'insufficient_runtime_observation'];
        }
        if ($allUnavailable) {
            return ['unavailable', 'registration_endpoints_unavailable'];
        }

        return ['unknown', 'insufficient_runtime_observation'];
    }
}
