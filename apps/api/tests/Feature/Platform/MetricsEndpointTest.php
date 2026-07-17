<?php

namespace Tests\Feature\Platform;

use App\Identity\IdentityIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_endpoint_reports_bounded_simulator_aggregates_without_identifiers(): void
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'metrics-tenant',
            'display_name' => 'Metrics Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Metrics Simulator',
            'slug' => 'metrics-simulator',
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('simulator_profiles')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => 'steady-ready',
            'scenario_version' => 1,
            'seed' => 'hidden-seed',
            'parameters' => json_encode([], JSON_THROW_ON_ERROR),
            'configuration_generation' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('simulator_states')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => 'steady-ready',
            'scenario_version' => 1,
            'seed' => 'hidden-seed',
            'logical_sequence' => 1,
            'current_phase' => 'events_scheduled',
            'attempt_count' => 1,
            'active_connection_epoch' => null,
            'applied_configuration_generation' => 1,
            'next_event_sequence' => 2,
            'state_payload' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/api/metrics')->assertOk();
        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('simulator_operations_total', $body);
        $this->assertStringContainsString('simulator_scheduled_events', $body);
        $this->assertStringContainsString('simulator_event_publish_total', $body);
        $this->assertStringContainsString('simulator_scenario_transitions_total{scenario="steady-ready",result="events_scheduled"} 1', $body);
        $this->assertStringContainsString('simulator_connection_epochs_total', $body);
        $this->assertStringContainsString('simulator_reconciliation_total', $body);
        $this->assertStringNotContainsString($tenantId, $body);
        $this->assertStringNotContainsString($nodeId, $body);
        $this->assertStringNotContainsString('hidden-seed', $body);
    }
}
