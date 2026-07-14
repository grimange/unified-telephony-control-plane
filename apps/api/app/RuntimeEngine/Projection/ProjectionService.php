<?php

namespace App\RuntimeEngine\Projection;

use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\EngineIds;
use Illuminate\Support\Facades\DB;

final class ProjectionService
{
    /**
     * @param  list<array<string, mixed>>  $observations
     */
    public function apply(object $receipt, array $observations): void
    {
        DB::transaction(function () use ($receipt, $observations): void {
            foreach ($observations as $observation) {
                PayloadSafety::assertSafe($observation['payload'] ?? []);
                $observationId = EngineIds::new();
                DB::table('runtime_observations')->insertOrIgnore([
                    'id' => $observationId,
                    'tenant_id' => $receipt->tenant_id,
                    'runtime_node_id' => $receipt->runtime_node_id,
                    'observation_type' => $observation['observation_type'],
                    'observation_version' => $observation['observation_version'] ?? 1,
                    'subject_type' => $observation['subject_type'],
                    'subject_id' => $observation['subject_id'],
                    'observed_state' => $observation['observed_state'],
                    'source_event_id' => $receipt->id,
                    'source_connection_epoch' => $receipt->connection_epoch_id,
                    'configuration_version' => $observation['configuration_version'] ?? null,
                    'observed_at' => $observation['observed_at'] ?? now(),
                    'received_at' => now(),
                    'payload' => StableJson::encode($observation['payload'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($observation['subject_type'] === 'runtime_node' && $observation['subject_id'] === $receipt->runtime_node_id) {
                    DB::table('runtime_nodes')->where('id', $receipt->runtime_node_id)->update([
                        'observed_state' => $observation['observed_state'],
                        'observed_at' => $observation['observed_at'] ?? now(),
                        'last_evidence_source' => $receipt->id,
                        'last_observation_id' => $observationId,
                        'observed_configuration_version' => $observation['configuration_version'] ?? null,
                        'updated_at' => now(),
                    ]);
                }

                $this->advanceCheckpoint(
                    'runtime-event-normalizer',
                    $receipt->runtime_node_id.':'.$receipt->connection_epoch_id,
                    $receipt->runtime_node_id,
                    $receipt->id,
                    $observation['observed_at'] ?? now(),
                );
            }
        });
    }

    public function advanceCheckpoint(string $projector, string $partitionKey, string $runtimeNodeId, string $eventId, mixed $observedAt): void
    {
        $existing = DB::table('runtime_projection_checkpoints')
            ->where('projector', $projector)
            ->where('partition_key', $partitionKey)
            ->where('runtime_node_id', $runtimeNodeId)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            DB::table('runtime_projection_checkpoints')->insert([
                'id' => EngineIds::new(),
                'projector' => $projector,
                'partition_key' => $partitionKey,
                'runtime_node_id' => $runtimeNodeId,
                'last_source_event_id' => $eventId,
                'last_observed_at' => $observedAt,
                'sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($existing->last_source_event_id === $eventId) {
            return;
        }

        DB::table('runtime_projection_checkpoints')->where('id', $existing->id)->update([
            'last_source_event_id' => $eventId,
            'last_observed_at' => $observedAt,
            'sequence' => ((int) $existing->sequence) + 1,
            'updated_at' => now(),
        ]);
    }

    public function markStale(int $staleSeconds): int
    {
        return DB::table('runtime_nodes')
            ->whereIn('observed_state', ['ready', 'degraded', 'connecting'])
            ->whereNotNull('observed_at')
            ->where('observed_at', '<', now()->subSeconds($staleSeconds))
            ->update([
                'observed_state' => 'stale',
                'updated_at' => now(),
            ]);
    }
}
