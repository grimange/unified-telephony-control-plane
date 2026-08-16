<?php

namespace Tests\Feature\Simulator;

use App\Identity\IdentityIds;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\Simulator\SimulatorCatalog;
use App\Simulator\SimulatorEventSourceWorker;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SimulatorRuntimeProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_steady_ready_lifecycle_converges_through_c3_workers_and_projection(): void
    {
        [$tenantId, $nodeId] = $this->createSimulatorNode('steady-ready', configurationGeneration: 2);

        $this->reconcile($tenantId, $nodeId, 2);
        $this->assertSame(1, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->count());
        $this->assertSame('pending', DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->value('status'));

        $this->command();
        $this->assertSame('succeeded', DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->value('status'));
        $this->assertGreaterThan(0, DB::table('simulator_scheduled_events')->where('runtime_node_id', $nodeId)->count());

        $this->publishAndNormalize();

        $node = DB::table('runtime_nodes')->where('id', $nodeId)->first();
        $this->assertSame('ready', $node->observed_state);
        $this->assertSame(2, (int) $node->observed_configuration_version);
        $this->assertSame(4, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('status', 'processed')->count());
        $this->assertGreaterThan(0, DB::table('runtime_projection_checkpoints')->where('runtime_node_id', $nodeId)->count());

        $this->reconcile($tenantId, $nodeId, 2);
        $this->assertSame('converged', DB::table('runtime_reconciliation_states')->where('target_id', $nodeId)->value('status'));
    }

    public function test_simulator_inspect_emits_intrinsic_capability_snapshot_through_projection(): void
    {
        [$tenantId, $nodeId] = $this->createSimulatorNode(
            'steady-ready',
            declaredCapabilities: ['event.stream', 'runtime.observation'],
        );

        $this->reconcile($tenantId, $nodeId);
        $this->command();
        $this->publishAndNormalize();

        $expected = app(SimulatorCatalog::class)->supportedCapabilities();
        $this->assertSame($expected, DB::table('runtime_node_observed_capabilities')
            ->where('runtime_node_id', $nodeId)
            ->orderBy('capability_key')
            ->pluck('capability_key')
            ->all());
        $this->assertSame(['event.stream', 'runtime.observation'], DB::table('runtime_node_capabilities')
            ->where('runtime_node_id', $nodeId)
            ->orderBy('capability_key')
            ->pluck('capability_key')
            ->all());
        $this->assertSame(1, DB::table('runtime_node_observed_capability_snapshots')->where('runtime_node_id', $nodeId)->count());
        $this->assertSame(1, DB::table('runtime_observations')->where('runtime_node_id', $nodeId)->where('observation_type', 'runtime.capability.observed')->count());
        $this->assertSame(1, DB::table('runtime_event_receipts')->where('runtime_node_id', $nodeId)->where('event_type', 'simulator.capabilities.observed')->count());
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
    }

    public function test_retry_terminal_timeout_and_duplicate_scenarios_use_real_operation_and_event_paths(): void
    {
        [$tenantId, $transientNode] = $this->createSimulatorNode('transient-failure-then-ready', parameters: ['transient_attempts' => 1]);
        $this->reconcile($tenantId, $transientNode);
        $this->command();
        $this->assertSame('retry_scheduled', DB::table('runtime_operations')->where('runtime_node_id', $transientNode)->value('status'));
        DB::table('runtime_operations')->where('runtime_node_id', $transientNode)->update(['available_at' => now()->subSecond()]);
        $this->command();
        $this->publishAndNormalize();
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $transientNode)->value('observed_state'));

        [$tenantId, $timeoutNode] = $this->createSimulatorNode('timeout-then-ready');
        $this->reconcile($tenantId, $timeoutNode);
        $this->command();
        $this->assertSame('timeout', DB::table('runtime_operations')->where('runtime_node_id', $timeoutNode)->value('last_failure_class'));
        DB::table('runtime_operations')->where('runtime_node_id', $timeoutNode)->update(['available_at' => now()->subSecond()]);
        $this->command();
        $this->publishAndNormalize();
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $timeoutNode)->value('observed_state'));

        [$tenantId, $terminalNode] = $this->createSimulatorNode('terminal-failure');
        $this->reconcile($tenantId, $terminalNode);
        $this->command();
        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('runtime_node_id', $terminalNode)->value('status'));
        $this->reconcile($tenantId, $terminalNode);
        $this->assertSame('blocked', DB::table('runtime_reconciliation_states')->where('target_id', $terminalNode)->value('status'));
        $this->assertNotSame('ready', DB::table('runtime_nodes')->where('id', $terminalNode)->value('observed_state'));

        [$tenantId, $duplicateNode] = $this->createSimulatorNode('duplicate-observation');
        $this->reconcile($tenantId, $duplicateNode);
        $this->command();
        $this->publishAndNormalize();
        $this->assertSame(3, DB::table('simulator_scheduled_events')->where('runtime_node_id', $duplicateNode)->where('status', 'published')->count());
        $this->assertSame(2, DB::table('runtime_event_receipts')->where('runtime_node_id', $duplicateNode)->count());
        $this->assertSame(2, DB::table('runtime_observations')->where('runtime_node_id', $duplicateNode)->count());
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $duplicateNode)->value('observed_state'));
    }

    public function test_disconnect_reconnect_epochs_and_configuration_drift_converge_without_duplicate_repairs(): void
    {
        [$tenantId, $reconnectNode] = $this->createSimulatorNode('disconnect-reconnect');
        $this->reconcile($tenantId, $reconnectNode);
        $this->command();
        $this->publishAndNormalize();

        $epochs = DB::table('runtime_event_connection_epochs')->where('runtime_node_id', $reconnectNode)->orderBy('opened_at')->get();
        $this->assertGreaterThanOrEqual(2, $epochs->count());
        $this->assertTrue($epochs->contains(fn ($epoch): bool => $epoch->status === 'closed'));
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $reconnectNode)->value('observed_state'));

        $closedEpoch = $epochs->firstWhere('status', 'closed');
        $this->expectException(DomainException::class);
        app(RuntimeEventReceiptRepository::class)->ingest(
            $tenantId,
            $reconnectNode,
            'simulator-deterministic',
            $closedEpoch->id,
            'stale-epoch-event',
            'simulator.readiness.changed',
            1,
            ['observed_state' => 'ready', 'configuration_generation' => 1],
        );
    }

    public function test_configuration_drift_generates_one_repair_operation_and_converges(): void
    {
        [$tenantId, $nodeId] = $this->createSimulatorNode('configuration-drift-then-converge', configurationGeneration: 3);

        $this->reconcile($tenantId, $nodeId, 3);
        $this->command();
        $this->publishAndNormalize();
        $this->assertSame('degraded', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(2, (int) DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_configuration_version'));

        $this->reconcile($tenantId, $nodeId, 3);
        $this->assertSame(2, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->count());
        $this->assertSame(1, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->where('operation_type', 'runtime.node.apply_configuration')->count());

        $this->command();
        $this->publishAndNormalize();
        $this->assertSame('ready', DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_state'));
        $this->assertSame(3, (int) DB::table('runtime_nodes')->where('id', $nodeId)->value('observed_configuration_version'));

        $this->reconcile($tenantId, $nodeId, 3);
        $this->assertSame('converged', DB::table('runtime_reconciliation_states')->where('target_id', $nodeId)->value('status'));
        $this->reconcile($tenantId, $nodeId, 3);
        $this->assertSame(2, DB::table('runtime_operations')->where('runtime_node_id', $nodeId)->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0:string,1:string}
     */
    private function createSimulatorNode(string $scenario, int $configurationGeneration = 1, array $parameters = [], array $declaredCapabilities = ['event.stream', 'runtime.configuration', 'runtime.observation']): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'sim-'.strtolower(substr($nodeId, 0, 8)),
            'display_name' => 'Simulator Proof Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Proof Simulator',
            'slug' => 'proof-simulator-'.strtolower(substr($nodeId, 0, 8)),
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'unobserved',
            'configuration_version' => $configurationGeneration,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode(['purpose' => 'c4-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($declaredCapabilities as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('simulator_profiles')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => $scenario,
            'scenario_version' => 1,
            'seed' => 'c4-proof-seed',
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'configuration_generation' => $configurationGeneration,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('simulator_states')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => $scenario,
            'scenario_version' => 1,
            'seed' => 'c4-proof-seed',
            'logical_sequence' => 0,
            'current_phase' => 'uninitialized',
            'attempt_count' => 0,
            'active_connection_epoch' => null,
            'applied_configuration_generation' => 0,
            'next_event_sequence' => 1,
            'state_payload' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $nodeId];
    }

    private function reconcile(string $tenantId, string $nodeId, int $generation = 1): void
    {
        app(ReconciliationRepository::class)->ensureTarget($tenantId, 'runtime_node', $nodeId, $generation);
        DB::table('runtime_reconciliation_states')->where('target_id', $nodeId)->update([
            'status' => 'waiting',
            'next_check_at' => now()->subSecond(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);
        app(ReconciliationWorker::class)->workOnce('c4-proof-reconciler', 10);
    }

    private function command(): void
    {
        app(CommandWorker::class)->workOnce('c4-proof-command', 10);
    }

    private function publishAndNormalize(): void
    {
        for ($i = 0; $i < 6; $i++) {
            DB::table('simulator_scheduled_events')->where('status', 'pending')->update(['due_at' => now()->subSecond()]);
            app(SimulatorEventSourceWorker::class)->workOnce('c4-proof-event-source', 20);
            app(EventNormalizerWorker::class)->workOnce('c4-proof-normalizer', 20);
        }
    }
}
