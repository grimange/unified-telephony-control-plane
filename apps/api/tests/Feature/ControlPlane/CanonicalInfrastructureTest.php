<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\InfrastructureAuthority\ActiveFingerprint;
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\InfrastructureAuthority\LifecycleFingerprint;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CanonicalInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private CanonicalInfrastructureService $service;

    private ExecutionContext $context;

    private string $node;

    private array $incarnation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CanonicalInfrastructureService;
        $tenant = (string) Str::uuid();
        $this->node = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'tenant-'.Str::random(12), 'display_name' => 'Canonical proof', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $this->node, 'tenant_id' => $tenant, 'name' => 'Proof', 'slug' => 'runtime-proof', 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'unobserved', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->context = ExecutionContext::system(tenantId: $tenant);
        $this->incarnation = $this->service->prepare($this->context, $this->node, 'proof-cluster', 'proof-runtime', 'asterisk.managed.v1', $this->configuration(), 'prepare-one');
    }

    public function test_idempotent_preparation_and_one_way_binding_retain_history(): void
    {
        $again = $this->service->prepare($this->context, $this->node, 'proof-cluster', 'proof-runtime', 'asterisk.managed.v1', $this->configuration(), 'prepare-one');
        $this->assertSame($this->incarnation['id'], $again['id']);
        $this->assertSame('utcp-'.$again['id'], $again['physical_name']);
        $bound = $this->service->bind($this->context, $again['id'], 'uid-proof');
        $this->assertSame('bound', $bound['state']);
        $this->assertSame($bound, $this->service->bind($this->context, $again['id'], 'uid-proof'));
        $this->expectException(InvalidArgumentException::class);
        $this->service->bind($this->context, $again['id'], 'replacement-uid');
    }

    public function test_terminal_retirement_and_new_incarnation_have_distinct_physical_names(): void
    {
        $id = $this->incarnation['id'];
        $retiring = $this->service->retire($this->context, $id);
        $this->assertSame('2', $retiring['generation']);
        $retired = $this->service->completeRetirement($this->context, $id, true);
        $this->assertSame('retired', $retired['state']);
        $new = $this->service->prepare($this->context, $this->node, 'proof-cluster', 'proof-runtime', 'asterisk.managed.v1', $this->configuration(), 'prepare-two');
        $this->assertNotSame($retired['id'], $new['id']);
        $this->assertNotSame($retired['physical_name'], $new['physical_name']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->bind($this->context, $id, 'late-uid');
    }

    public function test_delayed_creation_can_bind_only_for_cleanup_after_retirement(): void
    {
        $id = $this->incarnation['id'];
        $this->service->retire($this->context, $id);
        $cleanup = $this->service->bind($this->context, $id, 'delayed-create-uid');
        $this->assertSame('retiring', $cleanup['state']);
        $this->assertSame('delayed-create-uid', $cleanup['kubernetes_uid']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->nextGeneration($this->context, $id);
    }

    public function test_current_full_envelope_claim_and_negative_identity_cases(): void
    {
        $a = $this->action('create_suspended');
        $this->service->persistIntent($this->context, $this->incarnation['id'], $a);
        $this->assertTrue($this->service->verify($a)['authorized']);
        foreach (['request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'idempotency_key', 'correlation_id', 'audit_id'] as $field) {
            $wrong = $a;
            $wrong[$field] = (string) Str::uuid();
            $this->assertFalse($this->service->verify($wrong)['authorized'], $field);
        }
        foreach (['cluster_id', 'namespace', 'name', 'runtime_node_slug', 'target_id', 'incarnation_id'] as $field) {
            $wrong = $a;
            $wrong['target'][$field] = 'wrong';
            $this->assertFalse($this->service->verify($wrong)['authorized'], $field);
        }
        $wrong = $a;
        $wrong['claim']['attempt']++;
        $this->assertFalse($this->service->verify($wrong)['authorized']);
        $wrong = $a;
        $wrong['claim']['fencing_token'] = str_repeat('f', 32);
        $this->assertFalse($this->service->verify($wrong)['authorized']);
        $wrong = $a;
        $wrong['claim']['lease_expires_at'] = now()->addDays(1)->toISOString();
        $this->assertFalse($this->service->verify($wrong)['authorized']);
        $wrong = $a;
        $wrong['unknown'] = true;
        $this->assertFalse($this->service->verify($wrong)['authorized']);
    }

    public function test_expired_cancelled_and_superseded_claims_fail_closed_without_cache(): void
    {
        $a = $this->action('create_suspended');
        $this->service->persistIntent($this->context, $this->incarnation['id'], $a);
        $this->assertTrue($this->service->verify($a)['authorized']);
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertFalse($this->service->verify($a)['authorized']);
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['lease_expires_at' => $a['claim']['lease_expires_at'], 'status' => 'cancelled']);
        $this->assertFalse($this->service->verify($a)['authorized']);
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['status' => 'leased']);
        $this->service->nextGeneration($this->context, $this->incarnation['id']);
        $this->assertFalse($this->service->verify($a)['authorized']);
    }

    public function test_bound_active_profile_is_canonical_and_retirement_rejects_it(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $a = $this->action('converge_projection', true);
        $this->service->persistIntent($this->context, $id, $a);
        $this->assertTrue($this->service->verify($a)['authorized']);
        $wrong = $a;
        $wrong['intent']['projection']['configuration_sha256'] = str_repeat('b', 64);
        $wrong['intent']['projection_fingerprint'] = ActiveFingerprint::projection($wrong['intent']['projection']);
        $wrong['intent_fingerprint'] = ActiveFingerprint::calculate($wrong);
        $this->assertFalse($this->service->verify($wrong)['authorized']);
        $this->service->retire($this->context, $id);
        $this->assertFalse($this->service->verify($a)['authorized']);
    }

    public function test_v2_nonzero_inert_projection_cannot_bypass_active_profile_policy(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $a = $this->action('converge_projection');
        $a['intent']['replicas'] = 1;
        $a['intent_fingerprint'] = LifecycleFingerprint::calculate($a);
        $this->expectException(InvalidArgumentException::class);
        $this->service->persistIntent($this->context, $id, $a);
    }

    public function test_unknown_outcome_survives_new_service_and_blocks_reclaimed_dispatch(): void
    {
        $id = $this->incarnation['id'];
        $a = $this->action('create_suspended');
        $this->service->persistIntent($this->context, $id, $a);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'dispatch_attempted', 1);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'outcome_unknown', 1);
        $fresh = new CanonicalInfrastructureService;
        $this->assertCount(1, $fresh->unresolved($this->context->tenantId));
        $this->assertFalse($fresh->verify($a)['authorized']);
        $this->service->retire($this->context, $id);
        $this->assertCount(1, $fresh->unresolved($this->context->tenantId));
        $this->expectException(InvalidArgumentException::class);
        $fresh->completeRetirement($this->context, $id, true);
    }

    public function test_resolution_requires_observation_and_does_not_erase_unknown_history(): void
    {
        $id = $this->incarnation['id'];
        $a = $this->action('create_suspended');
        $this->service->persistIntent($this->context, $id, $a);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'dispatch_attempted', 1);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'outcome_unknown', 1);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'requires_observation', 1);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'confirmed_not_applied', 1);
        $this->assertSame([], $this->service->unresolved($this->context->tenantId));
        $this->assertTrue($this->service->verify($a)['authorized']);
    }

    public function test_audit_and_outbox_are_atomic_and_retries_do_not_duplicate_events(): void
    {
        $beforeAudit = DB::table('control_plane_audit_records')->count();
        $beforeOutbox = DB::table('control_plane_outbox_messages')->count();
        DB::beginTransaction();
        $this->service->bind($this->context, $this->incarnation['id'], 'uid-rollback');
        DB::rollBack();
        $this->assertSame('preparing', $this->service->read($this->incarnation['id'])['state']);
        $this->assertSame($beforeAudit, DB::table('control_plane_audit_records')->count());
        $this->assertSame($beforeOutbox, DB::table('control_plane_outbox_messages')->count());
        $this->service->bind($this->context, $this->incarnation['id'], 'uid-proof');
        $this->service->bind($this->context, $this->incarnation['id'], 'uid-proof');
        $this->assertSame($beforeAudit + 1, DB::table('control_plane_audit_records')->count());
        $this->assertSame($beforeOutbox + 1, DB::table('control_plane_outbox_messages')->count());
    }

    public function test_generation_decimal_strings_above_javascript_boundary_and_overflow(): void
    {
        $this->assertSame('9007199254740993', CanonicalInfrastructureService::increment('9007199254740992'));
        $this->assertSame('9223372036854775807', CanonicalInfrastructureService::increment('9223372036854775806'));
        $this->expectException(InvalidArgumentException::class);
        CanonicalInfrastructureService::increment('9223372036854775807');
    }

    public function test_cross_tenant_transition_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->bind(ExecutionContext::system(tenantId: (string) Str::uuid()), $this->incarnation['id'], 'uid-wrong-tenant');
    }

    public function test_arbitrary_operation_type_cannot_authorize_infrastructure_effect(): void
    {
        $a = $this->action('create_suspended');
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['operation_type' => 'test.arbitrary']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->persistIntent($this->context, $this->incarnation['id'], $a);
    }

    public function test_v1_scaling_requires_bound_active_profile_and_cannot_survive_retirement(): void
    {
        $id = $this->incarnation['id'];
        $unbound = $this->scale(1);
        $this->assertFalse($this->service->verify($unbound)['authorized']);
        $this->service->bind($this->context, $id, 'uid-proof');
        $inactive = $this->scale(1);
        try {
            $this->service->persistIntent($this->context, $id, $inactive);
            $this->fail('An inert bound incarnation cannot scale to active through v1.');
        } catch (InvalidArgumentException) {
            $this->assertFalse($this->service->verify($inactive)['authorized']);
        }
        $this->applyActiveProfile();
        $this->service->nextGeneration($this->context, $id);
        $restore = $this->scale(1, 'runtime.node.restore', $this->restorePayload());
        $this->service->persistIntent($this->context, $id, $restore);
        $this->assertTrue($this->service->verify($restore)['authorized']);
        $this->service->retire($this->context, $id);
        $this->assertFalse($this->service->verify($restore)['authorized']);
        $v2 = $this->action('converge_projection');
        $this->assertFalse($this->service->verify($v2)['authorized']);
    }

    public function test_fence_uses_actual_conference_replacement_and_recovered_node_guard(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $payload = $this->fencePayload();
        $fence = $this->scale(0, 'runtime.node.runtime.fence', $payload);
        $this->service->persistIntent($this->context, $id, $fence);
        $this->assertTrue($this->service->verify($fence)['authorized']);
        DB::table('runtime_nodes')->where('id', $this->node)->update(['observed_state' => 'ready']);
        $this->assertFalse($this->service->verify($fence)['authorized']);
        DB::table('runtime_nodes')->where('id', $this->node)->update(['observed_state' => 'unavailable']);
        DB::table('runtime_node_capabilities')->delete();
        $this->assertFalse($this->service->verify($fence)['authorized']);
    }

    public function test_restore_requires_canonical_source_provenance_and_fresh_eligible_state(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $this->applyActiveProfile();
        $this->service->nextGeneration($this->context, $id);
        $payload = $this->restorePayload();
        $restore = $this->scale(1, 'runtime.node.restore', $payload);
        $this->service->persistIntent($this->context, $id, $restore);
        $this->assertTrue($this->service->verify($restore)['authorized']);
        DB::table('runtime_nodes')->where('id', $this->node)->update(['configuration_version' => 2]);
        $this->assertFalse($this->service->verify($restore)['authorized']);
        DB::table('runtime_nodes')->where('id', $this->node)->update(['configuration_version' => 1]);
        DB::table('runtime_operations')->where('id', $payload['source_fence_operation_id'])->update(['status' => 'failed']);
        $this->assertFalse($this->service->verify($restore)['authorized']);
        DB::table('runtime_operations')->where('id', $payload['source_fence_operation_id'])->update(['status' => 'succeeded']);
        DB::table('runtime_operations')->where('id', $restore['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertFalse($this->service->verify($restore)['authorized']);
    }

    public function test_freeswitch_domain_fencing_and_restoration_remain_unsupported(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $this->applyActiveProfile();
        $this->service->nextGeneration($this->context, $id);
        $payload = $this->restorePayload();
        $restore = $this->scale(1, 'runtime.node.restore', $payload);
        $this->service->persistIntent($this->context, $id, $restore);
        $this->assertTrue($this->service->verify($restore)['authorized']);
        DB::table('runtime_nodes')->where('id', $this->node)->update(['runtime_family' => 'freeswitch', 'adapter_key' => 'freeswitch-esl']);
        $this->assertFalse($this->service->verify($restore)['authorized']);
        // Even valid-looking conference claims cannot grant the unsupported domain corridor.
        $fence = $this->scale(0, 'runtime.node.runtime.fence', $this->fencePayload());
        $this->assertFalse($this->service->verify($fence)['authorized']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->persistIntent($this->context, $id, $fence);
    }

    public function test_recomputed_fingerprint_cannot_substitute_profile_generation_uid_or_deadline(): void
    {
        $id = $this->incarnation['id'];
        $this->service->bind($this->context, $id, 'uid-proof');
        $a = $this->action('converge_projection', true);
        $this->service->persistIntent($this->context, $id, $a);
        $this->assertTrue($this->service->verify($a)['authorized']);
        $wrong = $a;
        $wrong['intent']['projection']['template_version'] = 'freeswitch.managed.v1';
        $wrong['intent']['projection_fingerprint'] = ActiveFingerprint::projection($wrong['intent']['projection']);
        $wrong['intent_fingerprint'] = ActiveFingerprint::versioned($wrong);
        $this->assertFalse($this->service->verify($wrong)['authorized']);
        foreach (['generation', 'uid', 'deadline'] as $case) {
            $wrong = $a;
            if ($case === 'generation') {
                $wrong['target_generation'] = '9007199254740993';
            } elseif ($case === 'uid') {
                $wrong['target']['expected_uid'] = 'replacement-uid';
            } else {
                $wrong['deadline'] = now()->subSecond()->toISOString();
            }
            $wrong['intent_fingerprint'] = ActiveFingerprint::versioned($wrong);
            $this->assertFalse($this->service->verify($wrong)['authorized'], $case);
        }
    }

    public function test_stale_executor_result_cannot_resolve_a_newer_in_flight_attempt(): void
    {
        $id = $this->incarnation['id'];
        $a = $this->action('create_suspended');
        $this->service->persistIntent($this->context, $id, $a);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'dispatch_attempted', 1);
        $this->assertFalse($this->service->verify($a)['authorized']);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'outcome_unknown', 1);
        $this->service->resolveObserved($this->context, $id, '1', 'create_suspended', 'confirmed_not_applied', 1);
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['attempt_count' => 2]);
        $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', 'dispatch_attempted', 2);
        foreach (['confirmed_not_applied', 'confirmed_applied', 'outcome_unknown'] as $outcome) {
            try {
                $this->service->recordOutcome($this->context, $id, '1', 'create_suspended', $outcome, 1);
                $this->fail('A stale executor result must not resolve another dispatch.');
            } catch (InvalidArgumentException) {
                $this->assertSame('dispatch_attempted', $this->service->unresolved($this->context->tenantId)[0]['outcome']);
            }
        }
    }

    private function applyActiveProfile(): void
    {
        $a = $this->action('converge_projection', true);
        $this->service->persistIntent($this->context, $this->incarnation['id'], $a);
        $this->service->recordOutcome($this->context, $this->incarnation['id'], $a['target_generation'], $a['action'], 'dispatch_attempted', 1);
        $this->service->recordOutcome($this->context, $this->incarnation['id'], $a['target_generation'], $a['action'], 'confirmed_applied', 1);
    }

    private function scale(int $replicas, string $operationType = 'runtime.node.restore', array $payload = []): array
    {
        $a = $this->action($this->service->read($this->incarnation['id'])['kubernetes_uid'] === null ? 'create_suspended' : 'converge_projection', false, $operationType, $payload);
        unset($a['intent']);
        $a['contract_version'] = 'utcp.infrastructure.v1';
        $a['action'] = 'set_replicas';
        $a['replicas'] = $replicas;
        // Valid wire UID is deliberately not authority to bind an unbound canonical incarnation.
        $a['target']['expected_uid'] ??= 'unbound-wire-uid';
        $a['target']['expected_resource_version'] ??= '10';
        $a['intent_fingerprint'] = ActiveFingerprint::versioned($a);

        return $a;
    }

    private function fencePayload(): array
    {
        $conference = (string) Str::uuid();
        $binding = (string) Str::uuid();
        DB::table('conferences')->insert(['id' => $conference, 'tenant_id' => $this->context->tenantId, 'slug' => 'proof-'.Str::random(12), 'display_name' => 'Canonical fence proof', 'runtime_node_id' => $this->node, 'desired_state' => 'open', 'configuration_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conference_runtime_bindings')->insert(['id' => $binding, 'tenant_id' => $this->context->tenantId, 'conference_id' => $conference, 'runtime_node_id' => $this->node, 'status' => 'active', 'bound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $replacement = (string) Str::uuid();
        DB::table('runtime_nodes')->insert(['id' => $replacement, 'tenant_id' => $this->context->tenantId, 'name' => 'Replacement', 'slug' => 'replacement-'.Str::lower(Str::random(8)), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert(['id' => (string) Str::uuid(), 'runtime_node_id' => $replacement, 'capability_key' => $capability, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['conference_id' => $conference, 'former_runtime_binding_id' => $binding, 'former_runtime_node_id' => $this->node, 'configuration_generation' => 1];
    }

    private function restorePayload(): array
    {
        DB::table('runtime_nodes')->where('id', $this->node)->update(['desired_state' => 'disabled']);
        $source = (new RuntimeOperationRepository)->create('runtime.node.runtime.fence', 'runtime_node', $this->node, [], $this->context, runtimeNodeId: $this->node);
        $r = $this->service->read($this->incarnation['id']);
        $provenance = ['configuration_generation' => 7, 'runtime_fence_provenance' => ['runtime_node_disabled' => ['by_operation' => true, 'operation_id' => $source, 'runtime_node_id' => $this->node], 'scale_to_zero_requested' => ['operation_id' => $source, 'namespace' => $r['namespace'], 'deployment' => $r['physical_name'], 'pre_scale_replicas' => 1]]];
        DB::table('runtime_operations')->where('id', $source)->update(['status' => 'succeeded', 'completed_at' => now(), 'payload' => json_encode($provenance, JSON_THROW_ON_ERROR)]);

        return ['tenant_id' => $this->context->tenantId, 'runtime_node_id' => $this->node, 'requested_desired_state' => 'active', 'source_fence_operation_id' => $source, 'source_fence_generation' => 7, 'workload_namespace' => $r['namespace'], 'deployment' => $r['physical_name'], 'target_replicas' => 1, 'expected_runtime_node_configuration_version' => 1];
    }

    private function configuration(): array
    {
        return ['image' => 'registry.example.test/utcp/asterisk@sha256:'.str_repeat('a', 64), 'configuration_id' => 'proof-config-v1', 'configuration_sha256' => str_repeat('a', 64), 'secret_name' => 'proof-config-v1'];
    }

    private function action(string $action, bool $active = false, ?string $operationType = null, array $payload = []): array
    {
        $r = $this->service->read($this->incarnation['id']);
        $repo = new RuntimeOperationRepository;
        $operationType ??= $action === 'retire_delete' ? 'runtime.node.deprovision' : 'runtime.node.provision';
        $opId = $repo->create($operationType, 'runtime_node', $this->node, $payload, $this->context, runtimeNodeId: $this->node);
        $claim = $repo->claimAvailable('canonical-test', 1, 300, includeOperationTypes: [$operationType])[0];
        $projection = $active ? ['template_version' => 'asterisk.managed.v1'] + $this->configuration() : ['template_version' => 'inert.pause.v1', 'image' => LifecycleFingerprint::IMAGE, 'configuration_id' => 'inert-config-v1', 'configuration_sha256' => str_repeat('a', 64)];
        $a = ['contract_version' => $active ? 'utcp.infrastructure.v3' : 'utcp.infrastructure.v2', 'request_id' => (string) Str::uuid(), 'operation_id' => $opId,
            'tenant_id' => $this->context->tenantId, 'runtime_node_id' => $this->node,
            'target' => ['cluster_id' => $r['cluster_id'], 'namespace' => $r['namespace'], 'kind' => 'Deployment', 'name' => $r['physical_name'], 'runtime_node_slug' => $r['runtime_node_slug'], 'target_id' => $r['target_id'], 'incarnation_id' => $r['id'], 'expected_uid' => $r['kubernetes_uid'], 'expected_resource_version' => $action === 'create_suspended' ? null : '10'],
            'target_generation' => $r['generation'], 'intent_fingerprint' => str_repeat('a', 64), 'action' => $action,
            'intent' => ['kind' => $action, 'projection' => $projection, 'projection_fingerprint' => $active ? ActiveFingerprint::projection($projection) : LifecycleFingerprint::projection($projection)],
            'idempotency_key' => (string) Str::uuid(), 'claim' => ['attempt' => $claim->attemptCount, 'fencing_token' => $claim->leaseToken, 'lease_expires_at' => now()->addSeconds(300)->startOfSecond()->toISOString()],
            'deadline' => now()->addSeconds(120)->toISOString(), 'correlation_id' => (string) Str::uuid(), 'audit_id' => (string) Str::uuid()];
        $a['claim']['lease_expires_at'] = CarbonImmutable::parse(DB::table('runtime_operations')->where('id', $opId)->value('lease_expires_at'))->toISOString();
        if ($action === 'converge_projection') {
            $a['intent']['replicas'] = $active ? 1 : 0;
        }
        if ($active) {
            $a['canonical_state'] = 'bound';
        }
        $a['intent_fingerprint'] = ActiveFingerprint::versioned($a);

        return $a;
    }
}
