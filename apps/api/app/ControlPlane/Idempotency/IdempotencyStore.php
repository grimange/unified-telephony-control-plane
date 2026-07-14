<?php

namespace App\ControlPlane\Idempotency;

use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\StableJson;
use Illuminate\Support\Facades\DB;

final class IdempotencyStore
{
    /**
     * @param  array<string, mixed>  $request
     */
    public function begin(string $scope, IdempotencyKey $key, array $request, int $ttlSeconds = 86400): ?IdempotencyRecord
    {
        $fingerprint = StableJson::fingerprint($request);

        return DB::transaction(function () use ($scope, $key, $fingerprint, $ttlSeconds): ?IdempotencyRecord {
            $existing = DB::table('control_plane_idempotency_records')
                ->where('scope', $scope)
                ->where('idempotency_key', $key->value())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new IdempotencyConflict('idempotency key reused with different request fingerprint');
                }

                return new IdempotencyRecord(
                    status: $existing->status,
                    result: $existing->result === null ? null : json_decode($existing->result, true, 512, JSON_THROW_ON_ERROR),
                );
            }

            DB::table('control_plane_idempotency_records')->insert([
                'id' => bin2hex(random_bytes(16)),
                'scope' => $scope,
                'idempotency_key' => $key->value(),
                'request_fingerprint' => $fingerprint,
                'status' => 'in_progress',
                'expires_at' => now()->addSeconds($ttlSeconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return null;
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(string $scope, IdempotencyKey $key, array $result): void
    {
        DB::table('control_plane_idempotency_records')
            ->where('scope', $scope)
            ->where('idempotency_key', $key->value())
            ->update([
                'status' => 'completed',
                'result' => StableJson::encode($result),
                'updated_at' => now(),
            ]);
    }

    public function expireOld(): int
    {
        return DB::table('control_plane_idempotency_records')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
