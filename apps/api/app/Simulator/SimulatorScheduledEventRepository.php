<?php

namespace App\Simulator;

use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\EngineIds;
use Illuminate\Support\Facades\DB;

final class SimulatorScheduledEventRepository
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(string $tenantId, string $runtimeNodeId, string $epochId, string $eventType, int $eventVersion, array $payload, int $delaySeconds = 0, ?string $externalEventKey = null): string
    {
        $basePayload = PayloadSafety::assertSafe(array_merge($payload, array_filter([
            'external_event_key' => $externalEventKey,
        ], fn (mixed $value): bool => $value !== null)));

        return DB::transaction(function () use ($tenantId, $runtimeNodeId, $epochId, $eventType, $eventVersion, $basePayload, $delaySeconds): string {
            $state = DB::table('simulator_states')->where('runtime_node_id', $runtimeNodeId)->lockForUpdate()->first();
            if ($state === null) {
                throw new \RuntimeException('simulator state is missing');
            }
            $sequence = (int) $state->next_event_sequence;
            $sequencePayload = array_key_exists('external_event_key', $basePayload) ? [] : [
                'event_sequence' => $sequence,
                'occurred_at' => now()->addSeconds($sequence)->toISOString(),
            ];
            $payload = PayloadSafety::assertSafe(array_merge($basePayload, $sequencePayload));
            $id = EngineIds::new();
            DB::table('simulator_scheduled_events')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'runtime_node_id' => $runtimeNodeId,
                'connection_epoch_id' => $epochId,
                'event_sequence' => $sequence,
                'event_type' => $eventType,
                'event_version' => $eventVersion,
                'payload' => StableJson::encode($payload),
                'due_at' => now()->addSeconds($delaySeconds),
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('simulator_states')->where('runtime_node_id', $runtimeNodeId)->update([
                'next_event_sequence' => $sequence + 1,
                'logical_sequence' => ((int) $state->logical_sequence) + 1,
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /**
     * @return list<object>
     */
    public function claimDue(string $leaseOwner, int $batchSize = 10, int $leaseSeconds = 60): array
    {
        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
            $rows = DB::table('simulator_scheduled_events')
                ->whereIn('status', ['pending', 'retry_scheduled', 'leased'])
                ->where('due_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', now())
                        ->orWhere('status', '!=', 'leased');
                })
                ->orderBy('runtime_node_id')
                ->orderBy('event_sequence')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->lease_token = EngineIds::token();
                DB::table('simulator_scheduled_events')->where('id', $row->id)->update([
                    'status' => 'leased',
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $row->lease_token,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'updated_at' => now(),
                ]);
            }

            return $rows->all();
        });
    }

    public function markPublished(string $id, string $leaseToken): bool
    {
        return DB::table('simulator_scheduled_events')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => 'published',
                'published_at' => now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function markFailed(string $id, string $leaseToken, bool $retryable): bool
    {
        return DB::table('simulator_scheduled_events')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => $retryable ? 'retry_scheduled' : 'failed',
                'due_at' => $retryable ? now()->addSeconds(15) : now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }
}
