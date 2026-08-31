<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\RecordingSessionId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class RecordingSessionService
{
    private const START_OPERATION = 'call.leg.start_recording';
    private const STOP_OPERATION = 'call.leg.stop_recording';

    public function __construct(
        private readonly CallDomainService $calls,
        private readonly RuntimeOperationRepository $operations,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
    ) {}

    public function requestStart(string $tenantId, ExecutionContext $context, string $callId, string $legId, ?string $conferenceId = null, ?IdempotencyKey $idempotencyKey = null): object
    {
        return DB::transaction(function () use ($tenantId, $context, $callId, $legId, $conferenceId, $idempotencyKey): object {
            $call = DB::table('calls')->where('tenant_id', $tenantId)->where('id', $callId)->first();
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $legId)->where('call_id', $callId)->first();
            if ($call === null || $leg === null) {
                throw new InvalidArgumentException('recording subject was not found for tenant');
            }
            if ($conferenceId !== null && DB::table('conferences')->where('tenant_id', $tenantId)->where('id', $conferenceId)->doesntExist()) {
                throw new InvalidArgumentException('recording conference was not found for tenant');
            }

            if ($idempotencyKey !== null) {
                $existing = DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey->value())->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $existing = DB::table('recording_sessions')
                ->where('tenant_id', $tenantId)
                ->where('call_leg_id', $legId)
                ->where('desired_state', 'recording')
                ->whereIn('observed_state', ['requested', 'recording'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $id = RecordingSessionId::new()->value();
            $now = now();
            DB::table('recording_sessions')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'call_id' => $callId,
                'call_leg_id' => $legId,
                'conference_id' => $conferenceId,
                'idempotency_key' => $idempotencyKey?->value(),
                'desired_state' => 'recording',
                'observed_state' => 'requested',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $operationId = $this->calls->requestSubordinateRecordingOperation($tenantId, $context, self::START_OPERATION, $legId, [
                'call_id' => $callId,
                'leg_id' => $legId,
                'recording_session_id' => $id,
            ], $leg->runtime_node_id);
            DB::table('recording_sessions')->where('id', $id)->update(['start_operation_id' => $operationId, 'updated_at' => now()]);
            $this->record($context, 'recording_session.requested', $id, ['operation_id' => $operationId, 'call_id' => $callId, 'call_leg_id' => $legId, 'conference_id' => $conferenceId]);

            return DB::table('recording_sessions')->where('id', $id)->first();
        });
    }

    public function requestStop(string $tenantId, ExecutionContext $context, string $sessionId, ?IdempotencyKey $idempotencyKey = null): object
    {
        return DB::transaction(function () use ($tenantId, $context, $sessionId): object {
            $session = DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw new InvalidArgumentException('recording session was not found for tenant');
            }
            if ($session->desired_state === 'stopped' || $session->observed_state === 'stopped') {
                return $session;
            }
            if ($session->observed_state === 'failed') {
                throw new LogicException('failed recording session cannot be stopped');
            }
            $leg = DB::table('call_legs')->where('tenant_id', $tenantId)->where('id', $session->call_leg_id)->first();
            if ($leg === null) {
                throw new InvalidArgumentException('recording session call leg is missing');
            }
            $operationId = $this->calls->requestSubordinateRecordingOperation($tenantId, $context, self::STOP_OPERATION, (string) $leg->id, [
                'call_id' => $session->call_id,
                'leg_id' => $leg->id,
                'recording_session_id' => $session->id,
            ], $leg->runtime_node_id);
            DB::table('recording_sessions')->where('id', $session->id)->update(['desired_state' => 'stopped', 'stop_operation_id' => $operationId, 'updated_at' => now()]);
            $this->record($context, 'recording_session.stop_requested', (string) $session->id, ['operation_id' => $operationId]);

            return DB::table('recording_sessions')->where('id', $session->id)->first();
        });
    }

    public function forTenant(string $tenantId, string $id): ?object
    {
        return DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('id', $id)->first();
    }

    /** @return list<object> */
    public function forCall(string $tenantId, string $callId): array
    {
        return DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('call_id', $callId)->orderByDesc('requested_at')->get()->all();
    }

    public function requestStopForLeg(string $tenantId, ExecutionContext $context, string $legId): object
    {
        $session = DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('call_leg_id', $legId)->whereIn('observed_state', ['requested', 'recording'])->orderByDesc('requested_at')->first();
        if ($session === null) {
            throw new InvalidArgumentException('active recording session was not found for call leg');
        }

        return $this->requestStop($tenantId, $context, (string) $session->id);
    }

    public function markOperationCompleted(string $operationId): void
    {
        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        if ($operation === null || ! in_array((string) $operation->operation_type, [self::START_OPERATION, self::STOP_OPERATION], true)) {
            return;
        }
        $payload = json_decode((string) $operation->payload, true);
        $sessionId = is_array($payload) && is_string($payload['recording_session_id'] ?? null) ? $payload['recording_session_id'] : null;
        if ($sessionId === null) {
            return;
        }
        $updates = (string) $operation->operation_type === self::START_OPERATION
            ? ['observed_state' => 'recording', 'started_at' => now(), 'updated_at' => now()]
            : ['observed_state' => 'stopped', 'stopped_at' => now(), 'updated_at' => now()];
        DB::table('recording_sessions')->where('tenant_id', $operation->tenant_id)->where('id', $sessionId)->update($updates);
    }

    public function markOperationFailed(string $operationId, FailureClass $failureClass, string $code, string $message, OperationStatus $status): void
    {
        if ($status !== OperationStatus::TerminalFailed) {
            return;
        }
        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        if ($operation === null || ! in_array((string) $operation->operation_type, [self::START_OPERATION, self::STOP_OPERATION], true)) {
            return;
        }
        $payload = json_decode((string) $operation->payload, true);
        $sessionId = is_array($payload) && is_string($payload['recording_session_id'] ?? null) ? $payload['recording_session_id'] : null;
        if ($sessionId === null) {
            return;
        }
        DB::table('recording_sessions')->where('tenant_id', $operation->tenant_id)->where('id', $sessionId)->update([
            'observed_state' => 'failed',
            'failure_class' => $failureClass->value,
            'failure_code' => $code,
            'failure_message' => mb_substr($message, 0, 512),
            'failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function applyObservation(string $tenantId, string $legId, string $state, ?string $observedAt = null): void
    {
        if (! in_array($state, ['recording', 'stopped'], true)) {
            throw new InvalidArgumentException('invalid recording observation state');
        }
        $session = DB::table('recording_sessions')->where('tenant_id', $tenantId)->where('call_leg_id', $legId)->whereIn('observed_state', ['requested', 'recording'])->orderByDesc('requested_at')->lockForUpdate()->first();
        if ($session === null) {
            return;
        }
        $at = $observedAt ?? now();
        DB::table('recording_sessions')->where('id', $session->id)->update($state === 'recording'
            ? ['observed_state' => 'recording', 'started_at' => $session->started_at ?? $at, 'updated_at' => now()]
            : ['observed_state' => 'stopped', 'stopped_at' => $at, 'updated_at' => now()]);
    }

    private function record(ExecutionContext $context, string $event, string $sessionId, array $payload): void
    {
        $this->audit->append($context, $event, 'recording_session', $sessionId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, 'recording_session', $sessionId, $payload, $context));
    }
}
