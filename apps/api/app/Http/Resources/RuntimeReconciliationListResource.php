<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class RuntimeReconciliationListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'target' => [
                'type' => (string) $this->resource->target_type,
                'id' => (string) $this->resource->target_id,
            ],
            'runtime_node' => $this->runtimeNode(),
            'status' => (string) $this->resource->status,
            'desired_generation' => (int) $this->resource->desired_generation,
            'observed_generation' => $this->nullableInt($this->resource->observed_generation),
            'has_drift' => $this->hasDrift(),
            'attempt_count' => (int) $this->resource->attempt_count,
            'last_checked_at' => $this->timestamp($this->resource->last_checked_at),
            'next_check_at' => $this->timestamp($this->resource->next_check_at),
            'last_operation_id' => $this->nullableString($this->resource->last_operation_id),
            'runtime_operation' => $this->runtimeOperation(),
            'failure' => $this->failure(),
            'created_at' => $this->timestamp($this->resource->created_at),
            'updated_at' => $this->timestamp($this->resource->updated_at),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    protected function runtimeNode(): ?array
    {
        if ($this->resource->target_type !== 'runtime_node' || $this->resource->runtime_node_name === null) {
            return null;
        }

        return [
            'id' => (string) $this->resource->target_id,
            'name' => (string) $this->resource->runtime_node_name,
            'slug' => (string) $this->resource->runtime_node_slug,
            'runtime_family' => (string) $this->resource->runtime_node_runtime_family,
            'adapter_key' => (string) $this->resource->runtime_node_adapter_key,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function runtimeOperation(): ?array
    {
        if ($this->resource->last_operation_id === null || $this->resource->runtime_operation_type === null) {
            return null;
        }

        return [
            'id' => (string) $this->resource->last_operation_id,
            'operation_type' => (string) $this->resource->runtime_operation_type,
            'status' => (string) $this->resource->runtime_operation_status,
            'created_at' => $this->timestamp($this->resource->runtime_operation_created_at),
            'completed_at' => $this->timestamp($this->resource->runtime_operation_completed_at),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function failure(): ?array
    {
        $status = (string) $this->resource->status;
        $safeCode = $this->safe($this->resource->blocked_reason);

        if ($safeCode === null && ! in_array($status, ['blocked', 'unsupported'], true)) {
            return null;
        }

        return [
            'category' => $status,
            'code' => $safeCode,
            'summary' => $safeCode === null ? $status : $status.':'.$safeCode,
            'occurred_at' => $this->timestamp($this->resource->last_checked_at ?? $this->resource->updated_at),
        ];
    }

    protected function hasDrift(): ?bool
    {
        if ($this->resource->observed_generation === null) {
            return null;
        }

        return (int) $this->resource->desired_generation !== (int) $this->resource->observed_generation;
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

    protected function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    protected function safe(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/password|secret|credential|token|stack|\/|\\\\|\s/i', $value) === 1) {
            return 'redacted';
        }

        return mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, 120);
    }
}
