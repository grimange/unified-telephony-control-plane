<?php

namespace Tests\Feature\Simulator;

use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Identity\IdentityIds;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\Simulator\Commands\SimulatorCallOperationHandler;
use App\TelephonyDomain\CallOperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SimulatorCallOperationHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_registered_runtime_adapter_implementation_is_accepted(): void
    {
        $adapter = new class implements RuntimeAdapter
        {
            public bool $called = false;

            public function adapterKey(): string
            {
                return 'test-third-adapter';
            }

            public function execute(array $operation): array
            {
                $this->called = true;

                return ['status' => 'completed'];
            }
        };
        $result = (new SimulatorCallOperationHandler('call.leg.hold'))->execute([
            'operation_type' => 'call.leg.hold', 'aggregate_type' => 'call_leg', 'aggregate_id' => 'leg-1',
            'payload' => ['call_id' => 'call-1', 'leg_id' => 'leg-1'],
        ], $adapter);
        $this->assertSame('completed', $result['status']);
        $this->assertTrue($adapter->called);
    }

    public function test_null_adapter_still_fails_as_unregistered(): void
    {
        $result = (new SimulatorCallOperationHandler('call.leg.hold'))->execute([
            'operation_type' => 'call.leg.hold', 'aggregate_type' => 'call_leg', 'aggregate_id' => 'leg-1',
            'payload' => ['call_id' => 'call-1', 'leg_id' => 'leg-1'],
        ], null);
        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame('call_adapter_not_registered', $result['failure_code']);
    }

    public function test_every_catalog_operation_has_one_registered_handler_and_executes_through_the_simulator(): void
    {
        [$tenantId, $nodeId, $callId, $legId, $secondLegId] = $this->fixture(array_keys(CallOperationCatalog::all()));
        $catalog = CallOperationCatalog::all();
        $registry = app(RuntimeOperationHandlerRegistry::class);

        foreach ($catalog as $type => $definition) {
            $handler = $registry->get($type, 1);
            $this->assertNotNull($handler, $type.' must be registered');
            $this->assertSame($type, $handler->operationType());
            $this->assertSame($definition['capability'], $handler->requiredRuntimeCapability());
        }

        $context = ExecutionContext::system(tenantId: $tenantId, reason: 'C6B catalog test');
        $operations = app(RuntimeOperationRepository::class);
        foreach ($catalog as $type => $definition) {
            $aggregateId = match ($definition['target']) {
                'call' => $callId,
                'call_leg' => $legId,
                'relationship' => $callId,
            };
            $operations->create($type, $definition['target'], $aggregateId, $this->payload($type, $callId, $legId, $secondLegId), $context, null, 1, 100, 3, $nodeId);
        }

        $processed = app(CommandWorker::class)->workOnce('c6b-catalog', 50);

        $this->assertSame(count($catalog), $processed);
        $this->assertSame(count($catalog), DB::table('runtime_operations')->where('status', 'succeeded')->count());
        $state = json_decode((string) DB::table('simulator_states')->where('runtime_node_id', $nodeId)->value('state_payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(count($catalog), $state['call_operations']);
        $this->assertSame([], $this->runtimeObservations($nodeId));
        $this->assertSame('requested', DB::table('calls')->where('id', $callId)->value('observed_state'));
        $this->assertSame('requested', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
    }

    public function test_command_worker_capability_gate_prevents_simulator_execution(): void
    {
        [$tenantId, $nodeId, $callId, $legId] = $this->fixture([], ['call.control']);
        $operationId = app(RuntimeOperationRepository::class)->create(
            'call.leg.hold',
            'call_leg',
            $legId,
            ['call_id' => $callId, 'leg_id' => $legId],
            ExecutionContext::system(tenantId: $tenantId),
            null,
            1,
            100,
            3,
            $nodeId,
        );

        $this->assertSame(0, app(CommandWorker::class)->workOnce('c6b-missing-capability', 10));
        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        $this->assertSame('terminal_failed', $operation->status);
        $this->assertSame('unsupported_capability', $operation->last_failure_class);
        $this->assertSame(0, DB::table('simulator_states')->where('runtime_node_id', $nodeId)->value('attempt_count'));
        $this->assertSame([], $this->runtimeOperationsInSimulator($nodeId));
    }

    public function test_successful_local_hold_and_resume_confirm_canonical_state_without_synthetic_observations(): void
    {
        [$tenantId, $nodeId, $callId, $legId] = $this->fixture([], ['call.hold']);
        DB::table('calls')->where('id', $callId)->update(['observed_state' => 'answered']);
        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        $repository = app(RuntimeOperationRepository::class);
        $context = ExecutionContext::system(tenantId: $tenantId);

        $holdId = $repository->create('call.leg.hold', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, runtimeNodeId: $nodeId);
        $this->assertSame(1, app(CommandWorker::class)->workOnce('c6-local-hold', 1, includeOperationTypes: ['call.leg.hold']));
        $this->assertDatabaseHas('runtime_operations', ['id' => $holdId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('call_legs', ['id' => $legId, 'observed_state' => 'held', 'held' => 1]);
        $this->assertSame(0, DB::table('runtime_observations')->count());

        $resumeId = $repository->create('call.leg.resume', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, runtimeNodeId: $nodeId);
        $this->assertSame(1, app(CommandWorker::class)->workOnce('c6-local-resume', 1, includeOperationTypes: ['call.leg.resume']));
        $this->assertDatabaseHas('runtime_operations', ['id' => $resumeId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('call_legs', ['id' => $legId, 'observed_state' => 'answered', 'held' => 0]);
        $this->assertSame(0, DB::table('runtime_observations')->count());
    }

    public function test_failed_local_hold_and_resume_do_not_change_canonical_state(): void
    {
        [$tenantId, $nodeId, $callId, $legId] = $this->fixture([], ['call.control']);
        DB::table('calls')->where('id', $callId)->update(['observed_state' => 'answered']);
        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        $repository = app(RuntimeOperationRepository::class);
        $context = ExecutionContext::system(tenantId: $tenantId);

        $holdId = $repository->create('call.leg.hold', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, runtimeNodeId: $nodeId);
        $this->assertSame(0, app(CommandWorker::class)->workOnce('c6-failed-hold', 1, includeOperationTypes: ['call.leg.hold']));
        $this->assertDatabaseHas('runtime_operations', ['id' => $holdId, 'status' => 'terminal_failed']);
        $this->assertDatabaseHas('call_legs', ['id' => $legId, 'observed_state' => 'answered']);

        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'held', 'held' => true]);
        $resumeId = $repository->create('call.leg.resume', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, runtimeNodeId: $nodeId);
        $this->assertSame(0, app(CommandWorker::class)->workOnce('c6-failed-resume', 1, includeOperationTypes: ['call.leg.resume']));
        $this->assertDatabaseHas('runtime_operations', ['id' => $resumeId, 'status' => 'terminal_failed']);
        $this->assertDatabaseHas('call_legs', ['id' => $legId, 'observed_state' => 'held', 'held' => 1]);
    }

    public function test_invalid_normalized_payload_fails_before_adapter_execution(): void
    {
        [$tenantId, $nodeId, $callId, $legId] = $this->fixture([], ['media.playback']);
        $operationId = app(RuntimeOperationRepository::class)->create(
            'call.leg.play_media',
            'call_leg',
            $legId,
            ['call_id' => $callId, 'leg_id' => $legId, 'media_ref' => '/host/file.wav'],
            ExecutionContext::system(tenantId: $tenantId),
            null,
            1,
            100,
            3,
            $nodeId,
        );

        app(CommandWorker::class)->workOnce('c6b-invalid-payload', 10);

        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        $this->assertSame('terminal_failed', $operation->status);
        $this->assertSame('invalid_request', $operation->last_failure_class);
        $this->assertSame(0, DB::table('simulator_states')->where('runtime_node_id', $nodeId)->value('attempt_count'));
    }

    public function test_runtime_operation_idempotency_prevents_duplicate_simulator_execution(): void
    {
        [$tenantId, $nodeId, $callId, $legId] = $this->fixture([], ['call.control']);
        $context = ExecutionContext::system(tenantId: $tenantId);
        $repository = app(RuntimeOperationRepository::class);
        $key = IdempotencyKey::fromString('c6b-idempotency-1');
        $first = $repository->create('call.leg.answer', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, $key, 1, 100, 3, $nodeId);
        $second = $repository->create('call.leg.answer', 'call_leg', $legId, ['call_id' => $callId, 'leg_id' => $legId], $context, $key, 1, 100, 3, $nodeId);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'call.leg.answer')->count());
        app(CommandWorker::class)->workOnce('c6b-idempotency', 10);
        $this->assertCount(1, $this->runtimeOperationsInSimulator($nodeId));
    }

    /** @param list<string> $extraOperations @param list<string> $capabilities */
    private function fixture(array $extraOperations, array $capabilities = []): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        $callId = IdentityIds::new();
        $legId = IdentityIds::new();
        $secondLegId = IdentityIds::new();
        $now = now();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'c6b-'.substr($tenantId, 0, 8), 'display_name' => 'C6B tenant', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'C6B simulator', 'slug' => 'c6b-'.substr($nodeId, 0, 8), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'placement_priority' => 100, 'capacity_weight' => 1, 'labels' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $allCapabilities = $capabilities === []
            ? array_values(array_unique(array_map(fn (array $definition): string => $definition['capability'], CallOperationCatalog::all())))
            : array_values(array_unique($capabilities));
        foreach ($allCapabilities as $capability) {
            DB::table('runtime_node_capabilities')->insert(['id' => IdentityIds::new(), 'runtime_node_id' => $nodeId, 'capability_key' => $capability, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('simulator_profiles')->insert(['runtime_node_id' => $nodeId, 'scenario_key' => 'steady-ready', 'scenario_version' => 1, 'seed' => 'c6b-seed', 'parameters' => json_encode([], JSON_THROW_ON_ERROR), 'configuration_generation' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('simulator_states')->insert(['runtime_node_id' => $nodeId, 'scenario_key' => 'steady-ready', 'scenario_version' => 1, 'seed' => 'c6b-seed', 'logical_sequence' => 0, 'current_phase' => 'uninitialized', 'attempt_count' => 0, 'active_connection_epoch' => null, 'applied_configuration_generation' => 0, 'next_event_sequence' => 1, 'state_payload' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'desired_state' => 'active', 'observed_state' => 'requested', 'runtime_node_id' => $nodeId, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([$legId, $secondLegId] as $id) {
            DB::table('call_legs')->insert(['id' => $id, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'direction' => 'outbound', 'role' => 'destination', 'desired_state' => 'active', 'observed_state' => 'requested', 'created_at' => $now, 'updated_at' => $now]);
        }

        return [$tenantId, $nodeId, $callId, $legId, $secondLegId];
    }

    /** @return array<string, mixed> */
    private function payload(string $type, string $callId, string $legId, string $secondLegId): array
    {
        return match ($type) {
            'call.legs.bridge', 'call.legs.unbridge', 'call.leg.attended_transfer' => ['call_id' => $callId, 'leg_ids' => [$legId, $secondLegId]],
            'call.leg.send_dtmf' => ['call_id' => $callId, 'leg_id' => $legId, 'digit' => '1'],
            'call.leg.play_media' => ['call_id' => $callId, 'leg_id' => $legId, 'media_ref' => 'utcp:media/c6b-test'],
            'call.hangup' => ['call_id' => $callId],
            default => ['call_id' => $callId, 'leg_id' => $legId],
        };
    }

    /** @return list<object> */
    private function runtimeObservations(string $nodeId): array
    {
        return DB::table('runtime_observations')->where('runtime_node_id', $nodeId)->get()->all();
    }

    /** @return list<mixed> */
    private function runtimeOperationsInSimulator(string $nodeId): array
    {
        $payload = json_decode((string) DB::table('simulator_states')->where('runtime_node_id', $nodeId)->value('state_payload'), true, 512, JSON_THROW_ON_ERROR);

        return $payload['call_operations'] ?? [];
    }
}
