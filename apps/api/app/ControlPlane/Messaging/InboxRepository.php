<?php

namespace App\ControlPlane\Messaging;

use App\ControlPlane\Shared\InboxMessageId;
use App\ControlPlane\Shared\StableJson;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InboxRepository
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function receive(string $consumer, string $messageKey, string $messageType, array $payload): string
    {
        $hash = StableJson::fingerprint($payload);
        $id = InboxMessageId::new()->value();

        $inserted = DB::table('control_plane_inbox_messages')->insertOrIgnore([
            'id' => $id,
            'consumer' => $consumer,
            'message_key' => $messageKey,
            'message_type' => $messageType,
            'payload_hash' => $hash,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return 'accepted';
        }

        $existing = DB::table('control_plane_inbox_messages')
            ->where('consumer', $consumer)
            ->where('message_key', $messageKey)
            ->first();

        if ($existing === null) {
            throw new DomainException('inbox duplicate was detected but original message was not found');
        }
        if ($existing->payload_hash !== $hash) {
            throw new DomainException('inbox message key reused with different payload');
        }

        return $existing->processed_at === null ? 'duplicate_pending' : 'duplicate_processed';
    }

    public function markProcessed(string $consumer, string $messageKey): void
    {
        DB::table('control_plane_inbox_messages')
            ->where('consumer', $consumer)
            ->where('message_key', $messageKey)
            ->update(['processed_at' => now(), 'updated_at' => now()]);
    }

    public function markFailed(string $consumer, string $messageKey, string $failureCode): void
    {
        DB::table('control_plane_inbox_messages')
            ->where('consumer', $consumer)
            ->where('message_key', $messageKey)
            ->update([
                'failed_at' => now(),
                'failure_code' => mb_substr($failureCode, 0, 120),
                'updated_at' => now(),
            ]);
    }
}
