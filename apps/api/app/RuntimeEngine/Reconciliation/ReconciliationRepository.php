<?php

namespace App\RuntimeEngine\Reconciliation;

use App\RuntimeEngine\EngineIds;
use Illuminate\Support\Facades\DB;

final class ReconciliationRepository
{
    public function ensureTarget(string $tenantId, string $targetType, string $targetId, int $desiredGeneration, int $nextCheckSeconds = 0): string
    {
        $existing = DB::table('runtime_reconciliation_states')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing !== null) {
            DB::table('runtime_reconciliation_states')->where('id', $existing->id)->update([
                'tenant_id' => $tenantId,
                'desired_generation' => max((int) $existing->desired_generation, $desiredGeneration),
                'next_check_at' => now()->addSeconds($nextCheckSeconds),
                'updated_at' => now(),
            ]);

            return $existing->id;
        }

        $id = EngineIds::new();
        DB::table('runtime_reconciliation_states')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'desired_generation' => $desiredGeneration,
            'status' => 'waiting',
            'attempt_count' => 0,
            'next_check_at' => now()->addSeconds($nextCheckSeconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return list<object>
     */
    public function claimDue(string $leaseOwner, int $batchSize = 10, int $leaseSeconds = 60): array
    {
        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
            $rows = DB::table('runtime_reconciliation_states')
                ->where('next_check_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', now());
                })
                ->whereNotIn('status', ['converged'])
                ->orderBy('next_check_at')
                ->orderBy('created_at')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->lease_token = EngineIds::token();
                DB::table('runtime_reconciliation_states')->where('id', $row->id)->update([
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

    public function markResult(string $id, string $leaseToken, ReconciliationResult $result, ?string $operationId = null): bool
    {
        return DB::table('runtime_reconciliation_states')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => $result->status,
                'last_checked_at' => now(),
                'next_check_at' => now()->addSeconds($result->nextCheckSeconds),
                'last_operation_id' => $operationId,
                'blocked_reason' => $result->status === 'blocked' ? mb_substr((string) $result->reasonCode, 0, 120) : null,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }
}
