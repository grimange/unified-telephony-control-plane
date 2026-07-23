<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class RuntimeOperationListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'runtime_node_id' => $this->nullableString($this->resource->runtime_node_id),
            'runtime_node' => $this->runtimeNode(),
            'operation_type' => (string) $this->resource->operation_type,
            'aggregate' => [
                'type' => (string) $this->resource->aggregate_type,
                'id' => (string) $this->resource->aggregate_id,
            ],
            'status' => (string) $this->resource->status,
            'attempt' => [
                'count' => (int) $this->resource->attempt_count,
                'max' => (int) $this->resource->max_attempts,
            ],
            'priority' => (int) $this->resource->priority,
            'correlation_id' => (string) $this->resource->correlation_id,
            'failure' => $this->failure(),
            'available_at' => $this->timestamp($this->resource->available_at),
            'started_at' => $this->timestamp($this->resource->started_at),
            'completed_at' => $this->timestamp($this->resource->completed_at),
            'cancelled_at' => $this->timestamp($this->resource->cancelled_at),
            'created_at' => $this->timestamp($this->resource->created_at),
            'updated_at' => $this->timestamp($this->resource->updated_at),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    protected function runtimeNode(): ?array
    {
        if ($this->resource->runtime_node_id === null || $this->resource->runtime_node_name === null) {
            return null;
        }

        return [
            'id' => (string) $this->resource->runtime_node_id,
            'name' => (string) $this->resource->runtime_node_name,
            'slug' => (string) $this->resource->runtime_node_slug,
            'runtime_family' => (string) $this->resource->runtime_node_runtime_family,
            'adapter_key' => (string) $this->resource->runtime_node_adapter_key,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function failure(): ?array
    {
        if ($this->resource->last_failure_class === null && $this->resource->last_failure_code === null) {
            return null;
        }

        $failureClass = $this->nullableString($this->resource->last_failure_class);
        $failureCode = $this->nullableString($this->resource->last_failure_code);

        return [
            'class' => $failureClass,
            'code' => $failureCode,
            'summary' => $this->failureSummary($failureClass, $failureCode),
            'occurred_at' => $this->timestamp($this->resource->completed_at ?? $this->resource->updated_at),
        ];
    }

    protected function failureSummary(?string $failureClass, ?string $failureCode): string
    {
        return implode(':', array_values(array_filter([$failureClass, $failureCode], static fn (?string $value): bool => $value !== null && $value !== '')));
    }

    protected function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toJSON();
    }

    protected function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
