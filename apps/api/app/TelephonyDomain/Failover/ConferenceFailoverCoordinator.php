<?php

namespace App\TelephonyDomain\Failover;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ConferenceFailoverCoordinator
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
        private readonly TelephonyDomainService $domain,
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
            $this->recordOutcome($context, $candidate, 'eligible');

            try {
                $result = $this->domain->failoverRebindConference(
                    $context,
                    (string) $candidate->tenant_id,
                    (string) $candidate->conference_id,
                    'automatic_runtime_node_unavailable',
                    [
                        'expected_binding_id' => (string) $candidate->binding_id,
                        'expected_runtime_node_id' => (string) $candidate->runtime_node_id,
                        'qualifying_bound_states' => self::QUALIFYING_BOUND_STATES,
                        'replacement_desired_states' => self::AUTOMATIC_REPLACEMENT_DESIRED_STATES,
                        'ready_observation_grace_seconds' => $graceSeconds,
                    ],
                );
                $classification = $this->classifyResult($result);
                $summary[$classification]++;
                $this->recordOutcome($context, $candidate, $classification, (string) ($result['reason'] ?? $result['status'] ?? 'unknown'));
            } catch (HttpExceptionInterface $exception) {
                $classification = $this->classifyHttpException($exception);
                $summary[$classification]++;
                $this->recordOutcome($context, $candidate, $classification, mb_substr($exception->getMessage(), 0, 120));
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

        return match ((string) ($result['reason'] ?? 'unknown')) {
            'bound_runtime_node_ready',
            'bound_runtime_node_not_eligible',
            'bound_runtime_node_recently_ready' => 'recovered_before_cutoff',
            'replacement_runtime_node_not_distinct' => 'no_replacement',
            'active_binding_changed' => 'concurrent_conflict',
            'conference_not_open',
            'active_binding_missing',
            'bound_runtime_node_missing' => 'terminal_skip',
            default => 'concurrent_conflict',
        };
    }

    private function classifyHttpException(HttpExceptionInterface $exception): string
    {
        if ($exception->getStatusCode() === 404) {
            return 'terminal_skip';
        }

        if ($exception->getStatusCode() === 422 && str_contains($exception->getMessage(), 'No eligible runtime node')) {
            return 'no_replacement';
        }

        return 'concurrent_conflict';
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
            'rebound' => 0,
            'recovered_before_cutoff' => 0,
            'no_replacement' => 0,
            'concurrent_conflict' => 0,
            'terminal_skip' => 0,
            'failed' => 0,
        ];
    }
}
