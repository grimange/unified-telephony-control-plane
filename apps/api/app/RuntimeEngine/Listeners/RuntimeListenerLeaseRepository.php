<?php

namespace App\RuntimeEngine\Listeners;

use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Sources\EventSourceRepository;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RuntimeListenerLeaseRepository
{
    public function __construct(private readonly ?EventSourceRepository $sources = null) {}

    public function claim(string $tenantId, string $runtimeNodeId, string $listenerKind, string $owner, int $leaseSeconds): ?object
    {
        $source = $this->sourceRepository()->ensureRuntimeNodeSource($tenantId, $runtimeNodeId);

        return $this->claimSource($source->id, $listenerKind, $owner, $leaseSeconds);
    }

    public function claimSource(string $eventSourceId, string $listenerKind, string $owner, int $leaseSeconds): ?object
    {
        return DB::transaction(function () use ($eventSourceId, $listenerKind, $owner, $leaseSeconds): ?object {
            $source = $this->sourceRepository()->find($eventSourceId);
            if ($source === null) {
                throw new DomainException('event source does not exist');
            }

            $existing = DB::table('runtime_listener_leases')
                ->where('event_source_id', $eventSourceId)
                ->where('listener_kind', $listenerKind)
                ->lockForUpdate()
                ->first();

            $now = now();
            if ($existing !== null && $existing->status === 'claimed' && $existing->lease_expires_at !== null && $existing->lease_expires_at > $now && $existing->owner !== $owner) {
                return null;
            }

            $token = EngineIds::token();
            if ($existing === null) {
                $id = EngineIds::new();
                DB::table('runtime_listener_leases')->insert([
                    'id' => $id,
                    'event_source_id' => $eventSourceId,
                    'tenant_id' => $this->tenantIdForSource($source),
                    'runtime_node_id' => $source->runtime_node_id,
                    'listener_kind' => $listenerKind,
                    'status' => 'claimed',
                    'owner' => $owner,
                    'fencing_token' => $token,
                    'claimed_at' => $now,
                    'heartbeat_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return DB::table('runtime_listener_leases')->where('id', $id)->first();
            }

            DB::table('runtime_listener_leases')->where('id', $existing->id)->update([
                'event_source_id' => $eventSourceId,
                'tenant_id' => $this->tenantIdForSource($source),
                'runtime_node_id' => $source->runtime_node_id,
                'status' => 'claimed',
                'owner' => $owner,
                'fencing_token' => $token,
                'claimed_at' => $now,
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'released_at' => null,
                'updated_at' => $now,
            ]);

            return DB::table('runtime_listener_leases')->where('id', $existing->id)->first();
        });
    }

    public function renew(string $leaseId, string $owner, string $fencingToken, int $leaseSeconds): bool
    {
        return DB::table('runtime_listener_leases')
            ->where('id', $leaseId)
            ->where('owner', $owner)
            ->where('fencing_token', $fencingToken)
            ->where('status', 'claimed')
            ->update([
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
                'updated_at' => now(),
            ]) === 1;
    }

    public function release(string $leaseId, string $owner, string $fencingToken): bool
    {
        return DB::table('runtime_listener_leases')
            ->where('id', $leaseId)
            ->where('owner', $owner)
            ->where('fencing_token', $fencingToken)
            ->where('status', 'claimed')
            ->update([
                'status' => 'released',
                'released_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function isCurrent(string $leaseId, string $owner, string $fencingToken): bool
    {
        return DB::table('runtime_listener_leases')
            ->where('id', $leaseId)
            ->where('owner', $owner)
            ->where('fencing_token', $fencingToken)
            ->where('status', 'claimed')
            ->where('lease_expires_at', '>', now())
            ->exists();
    }

    private function sourceRepository(): EventSourceRepository
    {
        return $this->sources ?? app(EventSourceRepository::class);
    }

    private function tenantIdForSource(object $source): ?string
    {
        if ($source->runtime_node_id === null) {
            return null;
        }

        return DB::table('runtime_nodes')->where('id', $source->runtime_node_id)->value('tenant_id');
    }
}
