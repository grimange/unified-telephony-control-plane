<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class RuntimeOperationDetailResource extends RuntimeOperationListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'payload_version' => (int) $this->resource->payload_version,
            'causation_id' => $this->nullableString($this->resource->causation_id),
            'request_id' => (string) $this->resource->request_id,
            'expires_at' => $this->timestamp($this->resource->expires_at),
            'reconciliation' => $this->reconciliation(),
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function reconciliation(): ?array
    {
        if (! property_exists($this->resource, 'reconciliation_id') || $this->resource->reconciliation_id === null) {
            return null;
        }

        return [
            'id' => (string) $this->resource->reconciliation_id,
            'target_type' => (string) $this->resource->reconciliation_target_type,
            'target_id' => (string) $this->resource->reconciliation_target_id,
            'status' => (string) $this->resource->reconciliation_status,
        ];
    }
}
