<?php

namespace App\Http\Resources\Telephony;

use Illuminate\Http\Resources\Json\JsonResource;

final class CallTimelineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->resource['id'], 'type' => $this->resource['type'], 'source' => $this->resource['source'], 'occurred_at' => $this->resource['occurred_at'], 'recorded_at' => $this->resource['recorded_at'], 'call_id' => $this->resource['call_id'], 'leg_id' => $this->resource['leg_id'], 'summary' => $this->resource['summary'], 'metadata' => $this->resource['metadata']];
    }
}
