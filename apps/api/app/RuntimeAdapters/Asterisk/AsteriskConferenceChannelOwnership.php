<?php

namespace App\RuntimeAdapters\Asterisk;

use Illuminate\Support\Facades\DB;

final class AsteriskConferenceChannelOwnership
{
    public static function owns(string $tenantId, string $runtimeNodeId, string $channelId): bool
    {
        return DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->where('conference_participants.tenant_id', $tenantId)
            ->where('conferences.tenant_id', $tenantId)
            ->where('conferences.runtime_node_id', $runtimeNodeId)
            ->where('conference_participants.runtime_channel_id', $channelId)
            ->exists();
    }
}
