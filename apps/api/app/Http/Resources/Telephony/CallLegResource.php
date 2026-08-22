<?php

namespace App\Http\Resources\Telephony;

use Illuminate\Http\Resources\Json\JsonResource;

final class CallLegResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'call_id' => $this->call_id, 'direction' => $this->direction, 'role' => $this->role, 'state' => $this->observed_state, 'runtime_node_id' => $this->runtime_node_id, 'runtime_channel_id' => $this->runtime_channel_id, 'remote_identity' => $this->remote_identity, 'bridged_to_leg_id' => $this->bridged_to_leg_id, 'bridged_at' => $this->bridged_at, 'termination_reason' => $this->termination_reason, 'terminated_at' => $this->terminated_at, 'telephony_session_id' => $this->telephony_session_id];
    }
}
