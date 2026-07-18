<?php

namespace App\TelephonyDomain\Failover;

use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\TelephonyDomain\TelephonyDomainService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class RuntimeFencingCoordinator
{
    /**
     * @var list<string>
     */
    private const QUALIFYING_BOUND_STATES = ['unavailable', 'stale'];

    /**
     * @var list<string>
     */
    private const AUTOMATIC_REPLACEMENT_DESIRED_STATES = ['active'];

    public function __construct(
        private readonly RuntimeOperationRepository $operations,
        private readonly TelephonyDomainService $domain,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(ExecutionContext $context, object $candidate, int $graceSeconds): array
    {
        $gate = DB::transaction(function () use ($context, $candidate, $graceSeconds): array {
            $conference = DB::table('conferences')
                ->where('id', (string) $candidate->conference_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->lockForUpdate()
                ->first();
            if ($conference === null || (string) $conference->desired_state !== 'open') {
                return ['status' => 'terminal_skip', 'reason' => 'conference_not_open'];
            }

            $binding = DB::table('conference_runtime_bindings')
                ->where('id', (string) $candidate->binding_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->where('conference_id', (string) $candidate->conference_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($binding === null) {
                return ['status' => 'terminal_skip', 'reason' => 'active_binding_missing'];
            }
            if ((string) $binding->runtime_node_id !== (string) $candidate->runtime_node_id
                || (string) $conference->runtime_node_id !== (string) $binding->runtime_node_id) {
                return ['status' => 'concurrent_conflict', 'reason' => 'active_binding_changed'];
            }

            $node = DB::table('runtime_nodes')
                ->where('id', (string) $binding->runtime_node_id)
                ->where('tenant_id', (string) $candidate->tenant_id)
                ->lockForUpdate()
                ->first();
            if ($node === null) {
                return ['status' => 'terminal_skip', 'reason' => 'bound_runtime_node_missing'];
            }
            if (! in_array((string) $node->observed_state, self::QUALIFYING_BOUND_STATES, true)) {
                return [
                    'status' => 'recovered_before_cutoff',
                    'reason' => (string) $node->observed_state === 'ready'
                        ? 'bound_runtime_node_ready'
                        : 'bound_runtime_node_not_eligible',
                ];
            }
            if (! $this->runtimeReadyObservationOlderThan((string) $binding->runtime_node_id, $graceSeconds)) {
                return ['status' => 'recovered_before_cutoff', 'reason' => 'bound_runtime_node_recently_ready'];
            }

            $operationType = (string) config('telephony_domain.operation_types.verify_conference_absent', 'runtime.node.verify_conference_absent');
            $key = $this->verificationIdempotencyKey((string) $conference->id, (string) $binding->runtime_node_id, (string) $binding->id, (int) $conference->configuration_generation);
            $existingId = $this->operations->findIdempotent($operationType, $key);
            if ($existingId === null) {
                $operationId = $this->operations->create(
                    $operationType,
                    'conference',
                    (string) $conference->id,
                    [
                        'conference_id' => (string) $conference->id,
                        'former_runtime_binding_id' => (string) $binding->id,
                        'former_runtime_node_id' => (string) $binding->runtime_node_id,
                        'runtime_node_id' => (string) $binding->runtime_node_id,
                        'configuration_generation' => (int) $conference->configuration_generation,
                    ],
                    $context,
                    $key,
                    runtimeNodeId: (string) $binding->runtime_node_id,
                );

                return ['status' => 'verification_requested', 'operation_id' => $operationId];
            }

            $operation = DB::table('runtime_operations')->where('id', $existingId)->lockForUpdate()->first();
            if ($operation === null) {
                return ['status' => 'verification_requested'];
            }

            return $this->classifyExistingOperation($operation);
        });

        if (($gate['status'] ?? null) !== 'absent_verified') {
            return $gate;
        }

        try {
            $result = $this->domain->failoverRebindConferenceAfterFence(
                $context,
                (string) $candidate->tenant_id,
                (string) $candidate->conference_id,
                (string) $gate['operation_id'],
                'former_runtime_absent_verified',
                [
                    'expected_binding_id' => (string) $candidate->binding_id,
                    'expected_runtime_node_id' => (string) $candidate->runtime_node_id,
                    'qualifying_bound_states' => self::QUALIFYING_BOUND_STATES,
                    'replacement_desired_states' => self::AUTOMATIC_REPLACEMENT_DESIRED_STATES,
                    'ready_observation_grace_seconds' => $graceSeconds,
                ],
            );
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 422 && str_contains($exception->getMessage(), 'No eligible runtime node')) {
                return ['status' => 'no_replacement', 'reason' => 'no_eligible_runtime_node'];
            }
            if ($exception->getStatusCode() === 404) {
                return ['status' => 'terminal_skip', 'reason' => 'not_found'];
            }

            return ['status' => 'concurrent_conflict', 'reason' => 'rebind_http_conflict'];
        }

        if (($result['status'] ?? null) === 'rebound') {
            return ['status' => 'rebound', 'reason' => (string) ($result['reason'] ?? 'runtime_binding_replaced')];
        }

        return match ((string) ($result['reason'] ?? 'unknown')) {
            'bound_runtime_node_ready',
            'bound_runtime_node_not_eligible',
            'bound_runtime_node_recently_ready' => ['status' => 'recovered_before_cutoff', 'reason' => (string) $result['reason']],
            'replacement_runtime_node_not_distinct' => ['status' => 'no_replacement', 'reason' => (string) $result['reason']],
            'fence_evidence_stale',
            'fence_evidence_missing',
            'fence_evidence_not_absent' => ['status' => 'fence_evidence_stale', 'reason' => (string) $result['reason']],
            'active_binding_changed' => ['status' => 'concurrent_conflict', 'reason' => 'active_binding_changed'],
            'conference_not_open',
            'active_binding_missing',
            'bound_runtime_node_missing' => ['status' => 'terminal_skip', 'reason' => (string) $result['reason']],
            default => ['status' => 'concurrent_conflict', 'reason' => (string) ($result['reason'] ?? 'unknown')],
        };
    }

    private function verificationIdempotencyKey(string $conferenceId, string $runtimeNodeId, string $bindingId, int $generation): IdempotencyKey
    {
        $authorityDigest = hash('sha256', implode(':', [
            $conferenceId,
            $runtimeNodeId,
            $bindingId,
            (string) $generation,
        ]));

        return IdempotencyKey::fromString('runtime.node.verify_conference_absent:'.$authorityDigest);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyExistingOperation(object $operation): array
    {
        return match ((string) $operation->status) {
            OperationStatus::Pending->value,
            OperationStatus::Leased->value,
            OperationStatus::Running->value => ['status' => 'verification_waiting', 'operation_id' => (string) $operation->id],
            OperationStatus::RetryScheduled->value => ['status' => 'former_runtime_unavailable', 'operation_id' => (string) $operation->id],
            OperationStatus::TerminalFailed->value => ['status' => 'verification_failed', 'operation_id' => (string) $operation->id],
            OperationStatus::Succeeded->value => $this->classifySucceededOperation($operation),
            default => ['status' => 'verification_failed', 'operation_id' => (string) $operation->id],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function classifySucceededOperation(object $operation): array
    {
        $evidence = $this->completedFenceEvidencePayload((string) $operation->tenant_id, (string) $operation->aggregate_id, (string) $operation->id);
        if ($evidence === null) {
            return ['status' => 'verification_failed', 'operation_id' => (string) $operation->id, 'reason' => 'verification_evidence_missing'];
        }

        return match ((string) ($evidence['verification_result'] ?? '')) {
            'absent' => ['status' => 'absent_verified', 'operation_id' => (string) $operation->id],
            'present' => ['status' => 'former_runtime_present', 'operation_id' => (string) $operation->id],
            default => ['status' => 'verification_failed', 'operation_id' => (string) $operation->id, 'reason' => 'verification_result_invalid'],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completedFenceEvidencePayload(string $tenantId, string $conferenceId, string $operationId): ?array
    {
        $rows = DB::table('control_plane_outbox_messages')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'conference')
            ->where('aggregate_id', $conferenceId)
            ->where('event_type', 'conference.runtime_fence_verified')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        foreach ($rows as $row) {
            $payload = $this->decodeJsonObject($row->payload);
            if ((string) ($payload['operation_id'] ?? '') === $operationId) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function runtimeReadyObservationOlderThan(string $runtimeNodeId, int $graceSeconds): bool
    {
        $latestReady = DB::table('runtime_observations')
            ->where('runtime_node_id', $runtimeNodeId)
            ->where('observed_state', 'ready')
            ->max('received_at');

        return is_string($latestReady) && Carbon::parse($latestReady)->lessThanOrEqualTo(now()->subSeconds($graceSeconds));
    }
}
