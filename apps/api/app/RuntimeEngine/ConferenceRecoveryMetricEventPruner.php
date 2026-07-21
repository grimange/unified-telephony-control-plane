<?php

namespace App\RuntimeEngine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ConferenceRecoveryMetricEventPruner
{
    /**
     * @return array{result:string,rows_deleted:int,batches_completed:int,eligible_backlog_remaining:bool,cutoff:string}
     */
    public function pruneExpired(int $retentionDays, int $batchSize, int $maxBatchesPerRun): array
    {
        if ($retentionDays < 1 || $batchSize < 1 || $maxBatchesPerRun < 1) {
            return [
                'result' => 'invalid_configuration',
                'rows_deleted' => 0,
                'batches_completed' => 0,
                'eligible_backlog_remaining' => false,
                'cutoff' => now()->toIso8601String(),
            ];
        }

        if (! Schema::hasTable('conference_recovery_metric_events')) {
            return [
                'result' => 'table_missing',
                'rows_deleted' => 0,
                'batches_completed' => 0,
                'eligible_backlog_remaining' => false,
                'cutoff' => now()->subDays($retentionDays)->toIso8601String(),
            ];
        }

        $cutoff = now()->subDays($retentionDays);
        $rowsDeleted = 0;
        $batchesCompleted = 0;
        $eligibleBacklogRemaining = false;

        for ($batch = 0; $batch < $maxBatchesPerRun; $batch++) {
            $deleted = DB::transaction(function () use ($cutoff, $batchSize): int {
                $ids = DB::table('conference_recovery_metric_events')
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                return DB::table('conference_recovery_metric_events')
                    ->whereIn('id', $ids)
                    ->delete();
            });

            if ($deleted === 0) {
                break;
            }

            $rowsDeleted += $deleted;
            $batchesCompleted++;

            if ($deleted < $batchSize) {
                break;
            }

            if ($batch === $maxBatchesPerRun - 1) {
                $eligibleBacklogRemaining = DB::table('conference_recovery_metric_events')
                    ->where('created_at', '<', $cutoff)
                    ->exists();
            }
        }

        return [
            'result' => $eligibleBacklogRemaining ? 'backlog_remaining' : ($rowsDeleted > 0 ? 'succeeded' : 'noop'),
            'rows_deleted' => $rowsDeleted,
            'batches_completed' => $batchesCompleted,
            'eligible_backlog_remaining' => $eligibleBacklogRemaining,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    public function eligibleBacklogCount(int $retentionDays): int
    {
        if ($retentionDays < 1 || ! Schema::hasTable('conference_recovery_metric_events')) {
            return 0;
        }

        return DB::table('conference_recovery_metric_events')
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->count();
    }

    public function oldestEligibleAgeSeconds(int $retentionDays): int
    {
        if ($retentionDays < 1 || ! Schema::hasTable('conference_recovery_metric_events')) {
            return 0;
        }

        $oldest = DB::table('conference_recovery_metric_events')
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->min('created_at');

        return $oldest === null ? 0 : max(0, (int) now()->diffInSeconds(Carbon::parse((string) $oldest), true));
    }
}
