<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RecordingArtifactId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecordingArtifactService
{
    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
    ) {}

    public function applyCaptureObservation(
        string $tenantId,
        string $captureRef,
        string $state,
        string $runtimeNodeId,
        ?string $mediaFormat,
        ?int $durationMs,
        ?string $observedAt,
    ): void {
        if (! in_array($state, ['started', 'finished'], true)) {
            throw new InvalidArgumentException('invalid recording artifact observation state');
        }

        $reference = CaptureReference::parse($captureRef);
        $sessionId = substr($reference->canonical(), strlen('utcp:capture/'));

        DB::transaction(function () use ($tenantId, $sessionId, $captureRef, $state, $runtimeNodeId, $mediaFormat, $durationMs, $observedAt): void {
            $session = DB::table('recording_sessions')
                ->where('tenant_id', $tenantId)
                ->where('id', $sessionId)
                ->first();
            if ($session === null) {
                return;
            }

            $leg = DB::table('call_legs')
                ->where('tenant_id', $tenantId)
                ->where('id', $session->call_leg_id)
                ->where('call_id', $session->call_id)
                ->where('runtime_node_id', $runtimeNodeId)
                ->first();
            if ($leg === null) {
                return;
            }

            $at = $observedAt ?? now()->toISOString();
            $now = now();
            DB::table('recording_artifacts')->insertOrIgnore([
                'id' => RecordingArtifactId::new()->value(),
                'tenant_id' => $tenantId,
                'recording_session_id' => $session->id,
                'call_id' => $session->call_id,
                'call_leg_id' => $session->call_leg_id,
                'runtime_node_id' => $runtimeNodeId,
                'capture_ref' => $captureRef,
                'state' => 'pending',
                'observed_started_at' => $at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($state !== 'finished') {
                return;
            }

            $format = $this->mediaFormat($mediaFormat);
            if ($format === null) {
                return;
            }
            $updated = DB::table('recording_artifacts')
                ->where('tenant_id', $tenantId)
                ->where('recording_session_id', $session->id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'available',
                    'media_format' => $format,
                    'duration_ms' => $durationMs,
                    'available_at' => $at,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                return;
            }

            $artifact = DB::table('recording_artifacts')->where('tenant_id', $tenantId)->where('recording_session_id', $session->id)->first();
            if ($artifact === null) {
                return;
            }
            $payload = [
                'recording_session_id' => (string) $artifact->recording_session_id,
                'call_id' => (string) $artifact->call_id,
                'call_leg_id' => (string) $artifact->call_leg_id,
                'runtime_node_id' => (string) $artifact->runtime_node_id,
                'capture_ref' => (string) $artifact->capture_ref,
                'media_format' => $artifact->media_format,
                'duration_ms' => $artifact->duration_ms === null ? null : (int) $artifact->duration_ms,
            ];
            $context = ExecutionContext::system(reason: 'recording artifact finalization', tenantId: $tenantId, origin: 'telephony-observation');
            $this->audit->append($context, 'recording_artifact.available', 'recording_artifact', (string) $artifact->id, $payload);
            $this->outbox->append(EventEnvelope::forAggregate('recording_artifact.available', 1, 'recording_artifact', (string) $artifact->id, $payload, $context));
        });
    }

    public function forRecordingSession(string $tenantId, string $sessionId): ?object
    {
        return DB::table('recording_artifacts')->where('tenant_id', $tenantId)->where('recording_session_id', $sessionId)->first();
    }

    private function mediaFormat(?string $mediaFormat): ?string
    {
        if ($mediaFormat === null) {
            return null;
        }
        $format = strtolower(trim($mediaFormat));

        return preg_match('/\A[a-z0-9]{1,32}\z/', $format) === 1 ? $format : null;
    }
}
