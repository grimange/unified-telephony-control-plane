<?php

namespace App\Http\Resources;

use App\ControlPlane\Shared\PayloadSafety;
use Illuminate\Http\Request;

/**
 * @mixin object
 */
final class AuditRecordDetailResource extends AuditRecordListResource
{
    private const AUDIT_DISPLAY_REDACTION_PATTERN = '/password|passwd|secret|token|authorization|cookie|private[_-]?key|credential|sip[_-]?password|request[_-]?body|stack[_-]?trace|outbox[_-]?body|desired[_-]?state|observed[_-]?state|provider[_-]?response/i';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'reason' => $this->safeReason(),
            'metadata' => $this->safeMetadata(),
        ]);
    }

    protected function safeReason(): ?string
    {
        if ($this->resource->reason === null || $this->resource->reason === '') {
            return null;
        }

        $reason = (string) $this->resource->reason;

        if (preg_match('/password|passwd|secret|token|authorization|cookie|private[_-]?key|credential|stack|trace/i', $reason) === 1) {
            return '[redacted]';
        }

        return mb_substr($reason, 0, 512);
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeMetadata(): array
    {
        $metadata = json_decode((string) $this->resource->metadata, true);

        if (! is_array($metadata)) {
            return [];
        }

        $data = $metadata['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        return $this->redactAuditDisplayMetadata(PayloadSafety::redact($data));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redactAuditDisplayMetadata(array $metadata): array
    {
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (preg_match(self::AUDIT_DISPLAY_REDACTION_PATTERN, (string) $key) === 1) {
                $redacted[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactAuditDisplayMetadata($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
