<?php

namespace App\ControlPlane\InfrastructureAuthority;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\StableJson;
use App\TelephonyDomain\TelephonyDomainService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Additive canonical authority. Deliberately has no dispatcher, route or Kubernetes client. */
final class CanonicalInfrastructureService
{
    public const INCARNATIONS = 'infrastructure_incarnations';

    public const INTENTS = 'infrastructure_intents';

    public const MAX_GENERATION = '9223372036854775807';

    public function __construct(
        private readonly AuditRepository $audit = new AuditRepository,
        private readonly OutboxRepository $outbox = new OutboxRepository,
    ) {}

    public function prepare(ExecutionContext $context, string $nodeId, string $cluster, string $namespace, string $profile, array $configuration, string $idempotencyKey): array
    {
        self::identifier($cluster);
        self::dns($namespace);
        self::identifier($idempotencyKey);

        return DB::transaction(function () use ($context, $nodeId, $cluster, $namespace, $profile, $configuration, $idempotencyKey): array {
            $node = $this->node($context, $nodeId);
            if (! in_array($profile, [$node->runtime_family.'.managed.v1'], true)) {
                self::deny();
            }
            self::configuration($configuration);
            self::dns($node->slug);
            $existing = DB::table(self::INCARNATIONS)->where('tenant_id', $context->tenantId)->where('runtime_node_id', $nodeId)->where('prepare_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->cluster_id !== $cluster || $existing->namespace !== $namespace || $existing->profile !== $profile || StableJson::encode(json_decode($existing->configuration, true)) !== StableJson::encode($configuration)) {
                    self::deny();
                }

                return $this->present($existing);
            }
            if (DB::table(self::INCARNATIONS)->where('runtime_node_id', $nodeId)->where('state', '<>', 'retired')->exists()) {
                self::deny();
            }
            $id = bin2hex(random_bytes(16));
            DB::table(self::INCARNATIONS)->insert([
                'id' => $id, 'tenant_id' => $context->tenantId, 'runtime_node_id' => $nodeId,
                'cluster_id' => $cluster, 'namespace' => $namespace, 'physical_name' => 'utcp-'.$id,
                'runtime_node_slug' => $node->slug, 'target_id' => $nodeId, 'state' => 'preparing',
                'generation' => '1', 'profile' => $profile, 'configuration' => StableJson::encode($configuration),
                'prepare_key' => $idempotencyKey, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($context, $id, 'prepared');

            return $this->read($id);
        });
    }

    public function read(string $id): array
    {
        $row = DB::table(self::INCARNATIONS)->where('id', $id)->first();
        if ($row === null) {
            self::deny();
        }

        return $this->present($row);
    }

    /** UID evidence must be from exact-identity Kubernetes observation by the authorized caller. */
    public function bind(ExecutionContext $context, string $id, string $uid): array
    {
        if ($uid === '' || strlen($uid) > 128 || strpbrk($uid, "\r\n") !== false) {
            self::deny();
        }

        return DB::transaction(function () use ($context, $id, $uid): array {
            $r = $this->locked($context, $id);
            if ($r->kubernetes_uid === $uid && in_array($r->state, ['bound', 'retiring'], true)) {
                return $this->present($r);
            }
            if (! in_array($r->state, ['preparing', 'retiring'], true) || $r->kubernetes_uid !== null) {
                self::deny();
            }
            DB::table(self::INCARNATIONS)->where('id', $id)->update(['kubernetes_uid' => $uid, 'state' => $r->state === 'preparing' ? 'bound' : 'retiring', 'updated_at' => now()]);
            $this->supersede($id);
            $this->event($context, $id, 'uid_bound');

            return $this->read($id);
        });
    }

    public function nextGeneration(ExecutionContext $context, string $id): string
    {
        return DB::transaction(function () use ($context, $id): string {
            $r = $this->locked($context, $id);
            if (! in_array($r->state, ['preparing', 'bound'], true)) {
                self::deny();
            }
            $next = self::increment((string) $r->generation);
            DB::table(self::INCARNATIONS)->where('id', $id)->update(['generation' => $next, 'updated_at' => now()]);
            $this->supersede($id);
            $this->event($context, $id, 'generation_allocated');

            return $next;
        });
    }

    public function retire(ExecutionContext $context, string $id): array
    {
        return DB::transaction(function () use ($context, $id): array {
            $r = $this->locked($context, $id);
            if (in_array($r->state, ['retiring', 'retired'], true)) {
                return $this->present($r);
            }
            DB::table(self::INCARNATIONS)->where('id', $id)->update(['state' => 'retiring', 'generation' => self::increment((string) $r->generation), 'updated_at' => now()]);
            $this->supersede($id);
            $this->event($context, $id, 'retiring');

            return $this->read($id);
        });
    }

    /** Caller supplies fresh exact-UID absence evidence, never a mutation HTTP acknowledgement. */
    public function completeRetirement(ExecutionContext $context, string $id, bool $exactAbsent): array
    {
        return DB::transaction(function () use ($context, $id, $exactAbsent): array {
            $r = $this->locked($context, $id);
            if ($r->state === 'retired') {
                return $this->present($r);
            }
            if ($r->state !== 'retiring' || ! $exactAbsent) {
                self::deny();
            }
            if (DB::table(self::INTENTS)->where('incarnation_id', $id)->whereIn('outcome', ['dispatch_attempted', 'outcome_unknown', 'requires_observation'])->exists()) {
                self::deny();
            }
            DB::table(self::INCARNATIONS)->where('id', $id)->update(['state' => 'retired', 'updated_at' => now()]);
            $this->supersede($id);
            $this->event($context, $id, 'retired');

            return $this->read($id);
        });
    }

    /** Immutable authoring seam invoked by trusted domain services; never a public request handler. */
    public function persistIntent(ExecutionContext $context, string $id, array $a): array
    {
        self::validate($a);

        return DB::transaction(function () use ($context, $id, $a): array {
            $r = $this->locked($context, $id);
            $this->assertTarget($r, $a);
            $this->assertPolicy($r, $a);
            $operation = DB::table('runtime_operations')->where('id', $a['operation_id'])->lockForUpdate()->first();
            if ($operation === null || $operation->tenant_id !== $r->tenant_id || $operation->runtime_node_id !== $r->runtime_node_id || ! in_array($operation->status, ['pending', 'leased', 'running', 'retry_scheduled'], true)) {
                self::deny();
            }
            $this->assertDomainPolicy($r, $operation, $a);
            $stored = self::durableEnvelope($a);
            $existing = DB::table(self::INTENTS)->where('incarnation_id', $id)->where('generation', $a['target_generation'])->where('action', $a['action'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (StableJson::encode(json_decode($existing->envelope, true)) !== StableJson::encode($stored) || $existing->superseded) {
                    self::deny();
                }

                return $this->intent($existing);
            }
            if (DB::table(self::INTENTS)->where('incarnation_id', $id)->where('generation', $a['target_generation'])->where('fingerprint', '<>', $a['intent_fingerprint'])->exists()) {
                self::deny();
            }
            $intentId = (string) Str::uuid();
            DB::table(self::INTENTS)->insert([
                'id' => $intentId, 'incarnation_id' => $id, 'operation_id' => $a['operation_id'],
                'generation' => $a['target_generation'], 'action' => $a['action'], 'fingerprint' => $a['intent_fingerprint'],
                'envelope' => StableJson::encode($stored), 'outcome' => 'not_dispatched', 'superseded' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($context, $id, 'intent_persisted', $a['operation_id']);

            return $this->intent(DB::table(self::INTENTS)->where('id', $intentId)->first());
        });
    }

    /** Fresh DB authority per callback. No credentials, errors or caller policy are returned. */
    public function verify(array $a): array
    {
        $result = ['contract_version' => $a['contract_version'] ?? 'utcp.infrastructure.v1', 'authorized' => false];
        try {
            self::validate($a);
            $result['authorized'] = DB::transaction(function () use ($a): bool {
                $context = ExecutionContext::system(tenantId: $a['tenant_id']);
                $r = $this->locked($context, $a['target']['incarnation_id']);
                $this->assertTarget($r, $a);
                $this->assertPolicy($r, $a);
                $intent = DB::table(self::INTENTS)->where('incarnation_id', $r->id)->where('generation', $a['target_generation'])->where('action', $a['action'])->lockForUpdate()->first();
                if ($intent === null || $intent->superseded || StableJson::encode(json_decode($intent->envelope, true)) !== StableJson::encode(self::durableEnvelope($a))) {
                    return false;
                }
                // Uncertain or already-dispatched work must be observed, never newly authorized for blind replay.
                if (! in_array($intent->outcome, ['not_dispatched', 'dispatch_rejected', 'confirmed_not_applied', 'confirmed_applied'], true)) {
                    return false;
                }
                $op = DB::table('runtime_operations')->where('id', $a['operation_id'])->lockForUpdate()->first();
                if ($op === null || ! in_array($op->status, ['leased', 'running'], true) || $op->tenant_id !== $r->tenant_id || $op->runtime_node_id !== $r->runtime_node_id
                    || ! is_string($op->lease_token) || ! hash_equals($op->lease_token, $a['claim']['fencing_token'])
                    || (int) $op->attempt_count !== $a['claim']['attempt'] || $op->lease_expires_at === null
                    || CarbonImmutable::parse($op->lease_expires_at)->lessThanOrEqualTo(now())
                    || ! CarbonImmutable::parse($op->lease_expires_at)->equalTo(CarbonImmutable::parse($a['claim']['lease_expires_at']))
                    || CarbonImmutable::parse($a['deadline'])->lessThanOrEqualTo(now())) {
                    return false;
                }
                if ($intent->dispatch_attempt !== null && (int) $intent->dispatch_attempt !== (int) $op->attempt_count && $intent->outcome !== 'confirmed_not_applied') {
                    return false;
                }
                $this->assertDomainPolicy($r, $op, $a);

                return true;
            });
        } catch (\Throwable) {
            // A malformed, unavailable or inconsistent authority is denied without leaking its envelope.
        }

        return $result;
    }

    /**
     * Durable dispatch/result evidence; never dispatches. Executor results must carry the exact dispatch attempt.
     * Production orchestration still needs a reservation/receipt seam after Go claim verification and before dispatch.
     * Recording attempted before that callback deliberately causes verification to deny blind replay.
     */
    public function recordOutcome(ExecutionContext $context, string $id, string $generation, string $action, string $outcome, ?int $attempt = null): array
    {
        $allowed = ['not_dispatched', 'dispatch_rejected', 'dispatch_attempted', 'outcome_unknown', 'confirmed_applied', 'confirmed_not_applied', 'requires_observation', 'terminally_resolved'];
        if (! in_array($outcome, $allowed, true)) {
            self::deny();
        }

        return DB::transaction(function () use ($context, $id, $generation, $action, $outcome, $attempt): array {
            $this->locked($context, $id);
            $intent = DB::table(self::INTENTS)->where('incarnation_id', $id)->where('generation', $generation)->where('action', $action)->lockForUpdate()->first();
            if ($intent === null) {
                self::deny();
            }
            if ($intent->dispatch_attempt !== null && $outcome !== 'dispatch_attempted'
                && ($attempt === null || $attempt !== (int) $intent->dispatch_attempt)) {
                self::deny();
            }
            if ($intent->outcome === $outcome) {
                if ($outcome === 'dispatch_attempted' && ($attempt === null || $attempt !== (int) $intent->dispatch_attempt)) {
                    self::deny();
                }

                return $this->intent($intent);
            }
            $transitions = [
                'not_dispatched' => ['dispatch_rejected', 'dispatch_attempted'],
                'dispatch_rejected' => ['dispatch_attempted', 'terminally_resolved'],
                'dispatch_attempted' => ['outcome_unknown', 'confirmed_applied', 'confirmed_not_applied', 'requires_observation'],
                'outcome_unknown' => ['requires_observation', 'confirmed_applied', 'confirmed_not_applied'],
                'requires_observation' => ['confirmed_applied', 'confirmed_not_applied'],
                'confirmed_not_applied' => ['dispatch_attempted', 'terminally_resolved'],
                'confirmed_applied' => ['terminally_resolved'],
                'terminally_resolved' => [],
            ];
            if (! in_array($outcome, $transitions[$intent->outcome], true)) {
                self::deny();
            }
            $fields = ['outcome' => $outcome, 'updated_at' => now()];
            if ($outcome === 'dispatch_attempted') {
                $op = DB::table('runtime_operations')->where('id', $intent->operation_id)->lockForUpdate()->first();
                if ($intent->superseded || $attempt === null || $op === null || ! in_array($op->status, ['leased', 'running'], true) || (int) $op->attempt_count !== $attempt || $op->lease_expires_at === null || CarbonImmutable::parse($op->lease_expires_at)->lessThanOrEqualTo(now())) {
                    self::deny();
                }
                $fields['dispatch_attempt'] = $attempt;
            }
            DB::table(self::INTENTS)->where('id', $intent->id)->update($fields);
            $this->event($context, $id, 'effect_'.$outcome, $intent->operation_id);

            return $this->intent(DB::table(self::INTENTS)->where('id', $intent->id)->first());
        });
    }

    /** Trusted exact-UID observation evidence may resolve an expired attempt, never a different dispatch. */
    public function resolveObserved(ExecutionContext $context, string $id, string $generation, string $action, string $outcome, int $attempt): array
    {
        if (! in_array($outcome, ['confirmed_applied', 'confirmed_not_applied'], true)) {
            self::deny();
        }

        return $this->recordOutcome($context, $id, $generation, $action, $outcome, $attempt);
    }

    /** Durable recovery queue, including superseded uncertain effects, no automatic dispatch. */
    public function unresolved(string $tenantId): array
    {
        return DB::table(self::INTENTS)->join(self::INCARNATIONS, self::INCARNATIONS.'.id', '=', self::INTENTS.'.incarnation_id')
            ->where(self::INCARNATIONS.'.tenant_id', $tenantId)->whereIn('outcome', ['dispatch_attempted', 'outcome_unknown', 'requires_observation'])
            ->select(self::INTENTS.'.*')->orderBy(self::INTENTS.'.created_at')->get()->map(fn ($r) => $this->intent($r))->all();
    }

    public static function validate(array $a): void
    {
        if (! hash_equals(ActiveFingerprint::versioned($a), $a['intent_fingerprint'] ?? '')) {
            self::deny();
        }
    }

    private function node(ExecutionContext $context, string $nodeId): object
    {
        if ($context->tenantId === null) {
            self::deny();
        }
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $context->tenantId)->lockForUpdate()->first();
        if ($node === null) {
            self::deny();
        }

        return $node;
    }

    private function locked(ExecutionContext $context, string $id): object
    {
        $hint = DB::table(self::INCARNATIONS)->where('id', $id)->first();
        if ($hint === null) {
            self::deny();
        }
        $this->node($context, $hint->runtime_node_id);
        $r = DB::table(self::INCARNATIONS)->where('id', $id)->where('tenant_id', $context->tenantId)->lockForUpdate()->first();
        if ($r === null) {
            self::deny();
        }

        return $r;
    }

    private function assertTarget(object $r, array $a): void
    {
        $t = $a['target'];
        foreach (['cluster_id' => 'cluster_id', 'namespace' => 'namespace', 'name' => 'physical_name', 'incarnation_id' => 'id', 'target_id' => 'target_id', 'runtime_node_slug' => 'runtime_node_slug', 'expected_uid' => 'kubernetes_uid'] as $key => $field) {
            if ($t[$key] !== $r->$field) {
                self::deny();
            }
        }
        if ($a['tenant_id'] !== $r->tenant_id || $a['runtime_node_id'] !== $r->runtime_node_id || $a['target_generation'] !== (string) $r->generation) {
            self::deny();
        }
    }

    private function assertPolicy(object $r, array $a): void
    {
        $required = match ($a['action']) {
            'create_suspended' => 'preparing', 'retire_delete' => 'retiring', default => 'bound'
        };
        if ($r->state !== $required) {
            self::deny();
        }
        if ($a['contract_version'] === 'utcp.infrastructure.v3') {
            if ($a['canonical_state'] !== 'bound' || $a['intent']['projection']['template_version'] !== $r->profile) {
                self::deny();
            }
            $config = json_decode($r->configuration, true, 512, JSON_THROW_ON_ERROR);
            foreach ($config as $key => $value) {
                if (($a['intent']['projection'][$key] ?? null) !== $value) {
                    self::deny();
                }
            }
        }
        // Earlier versions may never introduce a provider runtime template or activate an inert incarnation.
        if ($a['contract_version'] === 'utcp.infrastructure.v2' && $a['action'] === 'converge_projection' && $a['intent']['replicas'] !== 0) {
            self::deny();
        }
        if ($a['contract_version'] === 'utcp.infrastructure.v1' && $a['replicas'] > 0) {
            $active = DB::table(self::INTENTS)->where('incarnation_id', $r->id)->where('outcome', 'confirmed_applied')->where('action', 'converge_projection')->get();
            $hasProfile = false;
            foreach ($active as $intent) {
                $envelope = json_decode($intent->envelope, true, 512, JSON_THROW_ON_ERROR);
                if (($envelope['contract_version'] ?? '') === 'utcp.infrastructure.v3' && ($envelope['intent']['projection']['template_version'] ?? '') === $r->profile) {
                    $hasProfile = true;
                }
            }
            if (! $hasProfile) {
                self::deny();
            }
        }
    }

    private function assertDomainPolicy(object $r, object $op, array $a): void
    {
        $node = DB::table('runtime_nodes')->where('id', $r->runtime_node_id)->first();
        if ($node === null || $r->profile !== $node->runtime_family.'.managed.v1') {
            self::deny();
        }
        $payload = json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR);
        $fenceType = config('telephony_domain.operation_types.runtime_fence', 'runtime.node.runtime.fence');
        $restoreType = config('telephony_domain.operation_types.runtime_node_restore', 'runtime.node.restore');
        $replicas = $a['replicas'] ?? $a['intent']['replicas'] ?? 0;
        $corridors = [
            config('telephony_domain.operation_types.runtime_node_provision', 'runtime.node.provision') => ['create_suspended', 'converge_projection', 'admit_generation'],
            config('telephony_domain.operation_types.runtime_node_workload_converge', 'runtime.node.workload.converge') => ['converge_projection', 'admit_generation'],
            config('telephony_domain.operation_types.runtime_node_deprovision', 'runtime.node.deprovision') => ['retire_delete'],
            $fenceType => ['admit_generation', 'set_replicas'],
            $restoreType => ['admit_generation', 'set_replicas', 'converge_projection'],
        ];
        if (! in_array($a['action'], $corridors[$op->operation_type] ?? [], true)) {
            self::deny();
        }
        if ($op->operation_type === $fenceType) {
            if ($node->runtime_family !== 'asterisk' || $node->observed_state === 'ready' || $replicas !== 0 || ! isset($payload['conference_id'], $payload['former_runtime_binding_id'], $payload['former_runtime_node_id'], $payload['configuration_generation']) || $payload['former_runtime_node_id'] !== $r->runtime_node_id) {
                self::deny();
            }
            $conference = DB::table('conferences')->where('id', $payload['conference_id'])->where('tenant_id', $r->tenant_id)->where('desired_state', 'open')->where('runtime_node_id', $r->runtime_node_id)->where('configuration_generation', $payload['configuration_generation'])->first();
            $binding = DB::table('conference_runtime_bindings')->where('id', $payload['former_runtime_binding_id'])->where('tenant_id', $r->tenant_id)->where('conference_id', $payload['conference_id'])->where('runtime_node_id', $r->runtime_node_id)->where('status', 'active')->exists();
            if ($conference === null || ! $binding || ! app(TelephonyDomainService::class)->hasDistinctEligibleReplacement($r->tenant_id, $payload['conference_id'], $r->runtime_node_id)) {
                self::deny();
            }
        } elseif ($op->operation_type === $restoreType) {
            if ($node->runtime_family !== 'asterisk' || $node->desired_state !== 'disabled' || $replicas < 1 || ($payload['tenant_id'] ?? '') !== $r->tenant_id || ($payload['runtime_node_id'] ?? '') !== $r->runtime_node_id || ($payload['requested_desired_state'] ?? '') !== 'active' || ($payload['workload_namespace'] ?? '') !== $r->namespace || ($payload['deployment'] ?? '') !== $r->physical_name || ($payload['target_replicas'] ?? null) !== $replicas || (string) ($payload['expected_runtime_node_configuration_version'] ?? '') !== (string) $node->configuration_version) {
                self::deny();
            }
            $source = DB::table('runtime_operations')->where('id', $payload['source_fence_operation_id'] ?? '')->where('tenant_id', $r->tenant_id)->where('runtime_node_id', $r->runtime_node_id)->where('operation_type', $fenceType)->where('status', 'succeeded')->whereNotNull('completed_at')->first();
            if ($source === null) {
                self::deny();
            }
            $sourcePayload = json_decode($source->payload, true, 512, JSON_THROW_ON_ERROR);
            $scale = data_get($sourcePayload, 'runtime_fence_provenance.scale_to_zero_requested');
            $disabled = data_get($sourcePayload, 'runtime_fence_provenance.runtime_node_disabled');
            if (! is_array($scale) || ! is_array($disabled) || ($disabled['by_operation'] ?? null) !== true || ($disabled['runtime_node_id'] ?? '') !== $r->runtime_node_id || ($disabled['operation_id'] ?? '') !== $source->id || ($scale['operation_id'] ?? '') !== $source->id || ($scale['namespace'] ?? '') !== $r->namespace || ($scale['deployment'] ?? '') !== $r->physical_name || ($scale['pre_scale_replicas'] ?? null) !== $replicas || (string) ($sourcePayload['configuration_generation'] ?? '') !== (string) ($payload['source_fence_generation'] ?? '')) {
                self::deny();
            }
            if (DB::table('runtime_operations')->where('tenant_id', $r->tenant_id)->where('runtime_node_id', $r->runtime_node_id)->where('operation_type', $fenceType)->where('id', '<>', $source->id)->whereIn('status', ['pending', 'leased', 'running', 'retry_scheduled'])->exists()) {
                self::deny();
            }
            if (DB::table('conference_runtime_bindings')->join('conferences', 'conferences.id', '=', 'conference_runtime_bindings.conference_id')->where('conference_runtime_bindings.tenant_id', $r->tenant_id)->where('conference_runtime_bindings.runtime_node_id', $r->runtime_node_id)->where('conference_runtime_bindings.status', 'active')->where('conferences.desired_state', 'open')->exists()) {
                self::deny();
            }
        } elseif ($replicas > 0 && $node->desired_state !== 'active') {
            self::deny();
        }
    }

    private static function durableEnvelope(array $a): array
    {
        unset($a['claim']);

        return $a;
    }

    private function supersede(string $id): void
    {
        DB::table(self::INTENTS)->where('incarnation_id', $id)->where('superseded', false)->update(['superseded' => true, 'updated_at' => now()]);
    }

    private function event(ExecutionContext $context, string $id, string $event, ?string $operationId = null): void
    {
        $metadata = ['incarnation_id' => $id];
        if ($operationId !== null) {
            $metadata['runtime_operation_id'] = $operationId;
        }
        $this->audit->append($context, 'infrastructure.'.$event, 'infrastructure_incarnation', $id, $metadata);
        $this->outbox->append(EventEnvelope::forAggregate('infrastructure.'.$event, 1, 'infrastructure_incarnation', $id, $metadata, $context));
    }

    private function present(object $r): array
    {
        $a = (array) $r;
        $a['generation'] = (string) $r->generation;
        $a['configuration'] = json_decode($r->configuration, true, 512, JSON_THROW_ON_ERROR);

        return $a;
    }

    private function intent(object $r): array
    {
        $a = (array) $r;
        $a['generation'] = (string) $r->generation;
        $a['envelope'] = json_decode($r->envelope, true, 512, JSON_THROW_ON_ERROR);

        return $a;
    }

    private static function configuration(array $c): void
    {
        if (array_diff(array_keys($c), ['image', 'configuration_id', 'configuration_sha256', 'secret_name']) || count($c) !== 4) {
            self::deny();
        }
        // Declared immutable/versioned reference identity, never Secret payloads. Publication/content verification is a separate boundary.
        ActiveFingerprint::projection(['template_version' => 'asterisk.managed.v1'] + $c);
    }

    public static function increment(string $n): string
    {
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', $n) || strlen($n) === 19 && strcmp($n, self::MAX_GENERATION) >= 0) {
            self::deny();
        }
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            if ($n[$i] !== '9') {
                $n[$i] = chr(ord($n[$i]) + 1);

                return $n;
            }
            $n[$i] = '0';
        }

        return '1'.$n;
    }

    private static function identifier(string $s): void
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/D', $s)) {
            self::deny();
        }
    }

    private static function dns(string $s): void
    {
        if (strlen($s) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $s)) {
            self::deny();
        }
    }

    private static function deny(): never
    {
        throw new InvalidArgumentException('Canonical infrastructure authority denied.');
    }
}
