<?php

namespace App\Http\Resources\Telephony;

use Illuminate\Http\Resources\Json\JsonResource;

final class RecordingSessionResource extends JsonResource
{
    public function toArray($request): array
    {
        $artifact = $this->resource->artifact ?? null;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'call_id' => $this->call_id,
            'call_leg_id' => $this->call_leg_id,
            'conference_id' => $this->conference_id,
            'desired_state' => $this->desired_state,
            'observed_state' => $this->observed_state,
            'failure_class' => $this->failure_class,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'start_operation_id' => $this->start_operation_id,
            'stop_operation_id' => $this->stop_operation_id,
            'requested_at' => $this->requested_at,
            'started_at' => $this->started_at,
            'stopped_at' => $this->stopped_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'artifact' => $artifact === null ? null : [
                'id' => $artifact->id,
                'state' => $artifact->state,
                'media_format' => $artifact->media_format,
                'duration_ms' => $artifact->duration_ms,
                'available_at' => $artifact->available_at,
            ],
        ];
    }
}
