<?php

namespace App\Http\Resources\Telephony;

use Illuminate\Http\Resources\Json\JsonResource;

final class CallOperationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'operation_type' => $this->operation_type, 'target' => ['type' => $this->aggregate_type, 'id' => $this->aggregate_id], 'status' => $this->status, 'attempts' => $this->attempt_count, 'failure_class' => isset($this->resource->failure_class) ? $this->resource->failure_class : null, 'failure_code' => isset($this->resource->failure_code) ? $this->resource->failure_code : null, 'correlation_id' => $this->correlation_id, 'request_id' => $this->request_id, 'created_at' => $this->created_at, 'started_at' => isset($this->resource->started_at) ? $this->resource->started_at : null, 'completed_at' => isset($this->resource->completed_at) ? $this->resource->completed_at : null];
    }
}
