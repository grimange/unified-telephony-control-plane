<?php

namespace App\TelephonyDomain\Projection;

use App\TelephonyDomain\ExternalTrunkObservedHealthReconciler;
use App\TelephonyDomain\Signaling\KamailioRegistrationControlClient;
use Illuminate\Support\Facades\DB;

final readonly class T6ProjectionDispatcher
{
    public function __construct(
        private ExternalTrunkProjectionService $projections = new ExternalTrunkProjectionService,
        private KamailioRegistrationControlClient $registration = new KamailioRegistrationControlClient,
        private ExternalTrunkObservedHealthReconciler $health = new ExternalTrunkObservedHealthReconciler,
    ) {}

    public function dispatch(string $aggregateType, ?string $tenantId): void
    {
        if ($tenantId === null || ! in_array($aggregateType, ['c7a_authority', 'c7b_route'], true)) {
            return;
        }

        $this->projections->projectTenant($tenantId);
        $this->registration->reconcile($tenantId);
        foreach (DB::table('external_trunks')->where('tenant_id', $tenantId)->pluck('id') as $trunkId) {
            $this->health->reconcile($tenantId, (string) $trunkId);
        }
    }
}
