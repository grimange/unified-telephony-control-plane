<?php

namespace App\ControlPlane\Audit;

use App\ControlPlane\Shared\AuditRecordId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AuditRepository
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function append(
        ExecutionContext $context,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
        ?string $reason = null,
    ): string {
        if ($context->actorType === '' || $action === '' || $subjectType === '' || $subjectId === '') {
            throw new InvalidArgumentException('audit actor, action, and subject are required');
        }

        $id = AuditRecordId::new()->value();
        DB::table('control_plane_audit_records')->insert([
            'id' => $id,
            'tenant_id' => $context->tenantId,
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason ?? $context->reason,
            'request_id' => $context->requestId->value(),
            'correlation_id' => $context->correlationId->value(),
            'metadata' => StableJson::encode([
                'version' => 1,
                'data' => PayloadSafety::redact($metadata),
            ]),
            'occurred_at' => $context->occurredAt,
            'created_at' => now(),
        ]);

        return $id;
    }
}
