<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin object
 */
class AuditRecordListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'action' => $this->resource->action,
            'actor' => [
                'type' => $this->resource->actor_type,
                'id' => $this->resource->actor_id,
            ],
            'subject' => [
                'type' => $this->resource->subject_type,
                'id' => $this->resource->subject_id,
            ],
            'outcome' => $this->outcome(),
            'correlation_id' => $this->resource->correlation_id,
            'request_id' => $this->resource->request_id,
            'occurred_at' => $this->timestamp($this->resource->occurred_at),
            'created_at' => $this->timestamp($this->resource->created_at),
        ];
    }

    protected function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return CarbonImmutable::parse((string) $value)->toJSON();
    }

    /**
     * @return array{status: string|null, code: string|null, summary: string|null}|null
     */
    protected function outcome(): ?array
    {
        $data = $this->metadataData();
        $status = $this->safeScalar($data['result'] ?? $data['status'] ?? null);
        $code = $this->safeScalar($data['failure_code'] ?? $data['failure_class'] ?? $data['code'] ?? null);

        if ($status === null && $code === null) {
            return null;
        }

        return [
            'status' => $status,
            'code' => $code,
            'summary' => $code !== null ? trim(($status ?? 'outcome').':'.$code) : $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataData(): array
    {
        $metadata = json_decode((string) $this->resource->metadata, true);

        if (! is_array($metadata)) {
            return [];
        }

        $data = $metadata['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    protected function safeScalar(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $scalar = trim((string) $value);

            return $scalar === '' ? null : mb_substr($scalar, 0, 160);
        }

        return null;
    }
}
