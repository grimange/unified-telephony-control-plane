<?php

namespace App\ControlPlane\Messaging;

use App\ControlPlane\Shared\PayloadSafety;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Outbox\OutboxClaim;
use Illuminate\Support\Facades\DB;

final class OutboxRepository
{
    public function append(EventEnvelope $event): string
    {
        DB::table('control_plane_outbox_messages')->insert([
            'id' => $event->eventId->value(),
            'tenant_id' => $event->tenantId,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'event_type' => $event->eventType,
            'event_version' => $event->eventVersion,
            'payload' => $event->stablePayload(),
            'correlation_id' => $event->correlationId,
            'causation_id' => $event->causationId,
            'request_id' => $event->requestId,
            'occurred_at' => $event->occurredAt,
            'available_at' => $event->occurredAt,
            'attempt_count' => 0,
            'dispatch_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $event->eventId->value();
    }

    /**
     * @return list<OutboxClaim>
     */
    public function claimAvailable(string $leaseOwner, int $batchSize = 10, int $leaseSeconds = 60): array
    {
        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
            $rows = DB::table('control_plane_outbox_messages')
                ->whereNull('dispatched_at')
                ->whereIn('dispatch_status', ['pending', 'retry_scheduled', 'leased'])
                ->where('available_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', now())
                        ->orWhere('dispatch_status', '!=', 'leased');
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            $claims = [];
            foreach ($rows as $row) {
                $token = EngineIds::token();
                DB::table('control_plane_outbox_messages')->where('id', $row->id)->update([
                    'dispatch_status' => 'leased',
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $token,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'updated_at' => now(),
                ]);
                $claims[] = new OutboxClaim(
                    id: $row->id,
                    leaseToken: $token,
                    attemptCount: ((int) $row->attempt_count) + 1,
                    eventType: $row->event_type,
                    aggregateType: $row->aggregate_type,
                    aggregateId: $row->aggregate_id,
                );
            }

            return $claims;
        });
    }

    public function markDispatched(string $id): bool
    {
        return DB::table('control_plane_outbox_messages')
            ->where('id', $id)
            ->whereNull('dispatched_at')
            ->update(['dispatch_status' => 'dispatched', 'dispatched_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function markDispatchedWithFence(string $id, string $leaseToken): bool
    {
        return DB::table('control_plane_outbox_messages')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->where('lease_expires_at', '>', now())
            ->whereNull('dispatched_at')
            ->update([
                'dispatch_status' => 'dispatched',
                'dispatched_at' => now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function markFailed(string $id, string $failure): void
    {
        DB::table('control_plane_outbox_messages')->where('id', $id)->update([
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_failure' => mb_substr($failure, 0, 512),
            'updated_at' => now(),
        ]);
    }

    public function markFailedWithFence(string $id, string $leaseToken, string $failureClass, string $failureCode, string $message, bool $retryable): void
    {
        DB::table('control_plane_outbox_messages')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->update([
                'dispatch_status' => $retryable ? 'retry_scheduled' : 'terminal_failed',
                'available_at' => $retryable ? now()->addSeconds(30) : now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_failure' => mb_substr($message, 0, 512),
                'last_failure_class' => mb_substr($failureClass, 0, 80),
                'last_failure_code' => mb_substr($failureCode, 0, 120),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertSafePayload(array $payload): void
    {
        PayloadSafety::assertSafe($payload);
    }
}
