<?php

namespace App\Http\Resources\Telephony;

use Illuminate\Http\Resources\Json\JsonResource;

final class CallResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'tenant_id' => $this->tenant_id, 'direction' => $this->direction, 'state' => $this->observed_state, 'desired_state' => $this->desired_state, 'termination_reason' => $this->termination_reason, 'terminated_at' => $this->terminated_at, 'correlation_id' => $this->correlation_id, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at, 'destination_ref' => $this->destination_ref];
    }
}
