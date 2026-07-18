<?php

namespace App\TelephonyDomain\Failover;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ConferenceFailoverCoordinator
{
    /**
     * @var list<string>
     */
    private const QUALIFYING_BOUND_STATES = ['unavailable', 'stale'];

    public function __construct(
        private readonly RuntimeFencingCoordinator $fencing,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
    ) {}

    /**
     * @return array<string, int>
     */
    public function sweepOnce(string $workerId, ?int $batchSize = null): array
    {
        $limit = max(1, min(100, $batchSize ?? (int) config('runtime_engine.batch_size', 10)));
        $graceSeconds = max(1, (int) config('runtime_engine.stale_observation_seconds', 300));
        $summary = $this->emptySummary();

        foreach ($this->claimCandidates($limit, $graceSeconds) as $candidate) {
            $summary['candidates']++;
            $summary['eligible']++;

            $context = ExecutionContext::system(
                reason: 'automatic conference failover coordinator sweep',
                tenantId: (string) $candidate->tenant_id,
                origin: 'telephony-domain:failover-coordinator',
            );

            try {
                $result = $this->fencing->evaluate(
                    $context,
                    $candidate,
                    $graceSeconds,
                );
                $classification = $this->classifyResult($result);
                $summary[$classification]++;
                if (! in_array($classification, ['verification_waiting', 'runtime_fence_waiting', 'runtime_fence_in_progress', 'external_runtime_unavailable'], true)) {
                    $this->recordOutcome($context, $candidate, $classification, (string) ($result['reason'] ?? $result['status'] ?? 'unknown'));
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->recordOutcome($context, $candidate, 'failed', mb_substr($exception->getMessage(), 0, 120));
                Log::warning('conference failover coordinator failed', [
                    'component' => 'telephony-domain-failover-coordinator',
                    'failure_class' => $exception::class,
                ]);

                throw $exception;
            }
        }

        unset($workerId);

        return $summary;
    }

    /**
     * @return list<object>
     */
    private function claimCandidates(int $batchSize, int $graceSeconds): array
    {
        return DB::transaction(function () use ($batchSize, $graceSeconds): array {
            $cutoff = now()->subSeconds($graceSeconds);
            $query = DB::table('conferences')
                ->select([
                    'conferences.id as conference_id',
                    'conferences.tenant_id',
                    'conference_runtime_bindings.id as binding_id',
                    'conference_runtime_bindings.runtime_node_id',
                    'runtime_nodes.observed_state as runtime_node_observed_state',
                ])
                ->join('conference_runtime_bindings', function ($join): void {
                    $join->on('conference_runtime_bindings.conference_id', '=', 'conferences.id')
                        ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                        ->where('conference_runtime_bindings.status', 'active');
                })
                ->join('runtime_nodes', function ($join): void {
                    $join->on('runtime_nodes.id', '=', 'conference_runtime_bindings.runtime_node_id')
                        ->whereColumn('runtime_nodes.tenant_id', 'conferences.tenant_id');
                })
                ->where('conferences.desired_state', 'open')
                ->whereIn('runtime_nodes.observed_state', self::QUALIFYING_BOUND_STATES)
                ->whereExists(function ($subquery) use ($cutoff): void {
                    $subquery->selectRaw('1')
                        ->from('runtime_observations')
                        ->whereColumn('runtime_observations.runtime_node_id', 'runtime_nodes.id')
                        ->where('runtime_observations.observed_state', 'ready')
                        ->where('runtime_observations.received_at', '<=', $cutoff);
                })
                ->whereNotExists(function ($subquery) use ($cutoff): void {
                    $subquery->selectRaw('1')
                        ->from('runtime_observations')
                        ->whereColumn('runtime_observations.runtime_node_id', 'runtime_nodes.id')
                        ->where('runtime_observations.observed_state', 'ready')
                        ->where('runtime_observations.received_at', '>', $cutoff);
                })
                ->orderBy('conferences.id')
                ->limit($batchSize);

            if (DB::getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            return $query->get()->all();
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function classifyResult(array $result): string
    {
        if (($result['status'] ?? null) === 'rebound') {
            return 'rebound';
        }

        return match ((string) ($result['status'] ?? 'unknown')) {
            'verification_requested',
            'verification_waiting',
            'former_runtime_present',
            'former_runtime_unavailable',
            'runtime_fence_requested',
            'runtime_fence_waiting',
            'runtime_fence_in_progress',
            'external_runtime_unavailable',
            'external_runtime_fenced',
            'target_recovered',
            'target_mismatch',
            'permission_denied',
            'runtime_fence_failed',
            'verification_failed',
            'fence_evidence_stale',
            'rebound',
            'recovered_before_cutoff',
            'no_replacement',
            'concurrent_conflict',
            'terminal_skip' => (string) $result['status'],
            default => 'concurrent_conflict',
        };
    }

    private function recordOutcome(ExecutionContext $context, object $candidate, string $outcome, ?string $reason = null): void
    {
        $payload = [
            'tenant_id' => (string) $candidate->tenant_id,
            'conference_id' => (string) $candidate->conference_id,
            'runtime_node_id' => (string) $candidate->runtime_node_id,
            'binding_id' => (string) $candidate->binding_id,
            'outcome' => $outcome,
        ];
        if ($reason !== null && $reason !== '') {
            $payload['reason'] = mb_substr($reason, 0, 120);
        }

        $eventType = 'conference.failover_coordinator.'.$outcome;
        $this->audit->append($context, $eventType, 'conference', (string) $candidate->conference_id, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($eventType, 1, 'conference', (string) $candidate->conference_id, $payload, $context));
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'candidates' => 0,
            'eligible' => 0,
            'verification_requested' => 0,
            'verification_waiting' => 0,
            'former_runtime_present' => 0,
            'former_runtime_unavailable' => 0,
            'runtime_fence_requested' => 0,
            'runtime_fence_waiting' => 0,
            'runtime_fence_in_progress' => 0,
            'external_runtime_unavailable' => 0,
            'external_runtime_fenced' => 0,
            'target_recovered' => 0,
            'target_mismatch' => 0,
            'permission_denied' => 0,
            'runtime_fence_failed' => 0,
            'verification_failed' => 0,
            'fence_evidence_stale' => 0,
            'rebound' => 0,
            'recovered_before_cutoff' => 0,
            'no_replacement' => 0,
            'concurrent_conflict' => 0,
            'terminal_skip' => 0,
            'failed' => 0,
        ];
    }
}
