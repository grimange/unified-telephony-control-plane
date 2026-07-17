<?php

namespace Tests\Feature\Platform;

use App\Identity\IdentityIds;
use App\RuntimeEngine\EngineIds;
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

    public function test_metrics_endpoint_reports_safe_conference_recovery_telemetry(): void
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        $epochId = EngineIds::new();
        $receiptId = EngineIds::new();
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        $now = now();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'conference-recovery-metrics',
            'display_name' => 'Conference Recovery Metrics',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Metrics Asterisk',
            'slug' => 'metrics-asterisk',
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('conference_recovery_metric_events')->insert([
            [
                'id' => EngineIds::new(),
                'adapter_key' => 'asterisk-ari',
                'resource_type' => 'conference',
                'result' => 'observed',
                'failure_class' => 'none',
                'reason' => 'observed',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => EngineIds::new(),
                'adapter_key' => 'asterisk-ari',
                'resource_type' => 'conference_participant',
                'result' => 'failed',
                'failure_class' => 'runtime_unavailable',
                'reason' => 'runtime_unavailable',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('runtime_operations')->insert([
            [
                'id' => EngineIds::new(),
                'tenant_id' => $tenantId,
                'operation_type' => 'conference.close',
                'aggregate_type' => 'conference',
                'aggregate_id' => $conferenceId,
                'runtime_node_id' => $nodeId,
                'payload_version' => 1,
                'payload' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'retry_scheduled',
                'idempotency_key' => 'metrics-close',
                'correlation_id' => EngineIds::new(),
                'request_id' => EngineIds::new(),
                'available_at' => $now,
                'last_failure_class' => 'runtime_unavailable',
                'last_failure_code' => 'runtime_unavailable',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => EngineIds::new(),
                'tenant_id' => $tenantId,
                'operation_type' => 'conference.participant.ensure',
                'aggregate_type' => 'conference_participant',
                'aggregate_id' => $participantId,
                'runtime_node_id' => $nodeId,
                'payload_version' => 1,
                'payload' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'terminal_failed',
                'idempotency_key' => 'metrics-participant-ensure',
                'correlation_id' => EngineIds::new(),
                'request_id' => EngineIds::new(),
                'available_at' => $now,
                'last_failure_class' => 'timeout',
                'last_failure_code' => 'timeout',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('runtime_event_connection_epochs')->insert([
            'id' => $epochId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'adapter_key' => 'asterisk-ari',
            'status' => 'closed',
            'owner' => 'metrics-owner',
            'fencing_token' => EngineIds::new(),
            'opened_at' => $now->copy()->subMinutes(5),
            'closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_event_receipts')->insert([
            'id' => $receiptId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'adapter_key' => 'asterisk-ari',
            'connection_epoch_id' => $epochId,
            'external_event_key' => 'metrics-stale-event',
            'event_type' => 'asterisk.ari.bridge.created',
            'event_version' => 1,
            'payload_hash' => str_repeat('a', 64),
            'sanitized_payload' => json_encode(['safe' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'received_at' => $now,
            'status' => 'conflict',
            'available_at' => $now,
            'failure_code' => 'stale_epoch',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_reconciliation_states')->insert([
            [
                'id' => EngineIds::new(),
                'tenant_id' => $tenantId,
                'target_type' => 'conference',
                'target_id' => $conferenceId,
                'desired_generation' => 1,
                'status' => 'waiting',
                'next_check_at' => $now,
                'created_at' => $now->copy()->subMinutes(12),
                'updated_at' => $now->copy()->subMinutes(12),
            ],
            [
                'id' => EngineIds::new(),
                'tenant_id' => $tenantId,
                'target_type' => 'conference_participant',
                'target_id' => $participantId,
                'desired_generation' => 1,
                'status' => 'operation_required',
                'next_check_at' => $now,
                'created_at' => $now->copy()->subMinutes(2),
                'updated_at' => $now->copy()->subMinutes(2),
            ],
        ]);

        $response = $this->get('/api/metrics')->assertOk();
        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringContainsString('# HELP utcp_conference_runtime_inspections_total', $body);
        $this->assertStringContainsString('# TYPE utcp_conference_runtime_inspections_total counter', $body);
        $this->assertStringContainsString('utcp_conference_runtime_inspections_total{adapter_key="asterisk-ari",resource_type="conference",result="observed",failure_class="none"} 1', $body);
        $this->assertStringContainsString('utcp_conference_runtime_inspection_failures_total{adapter_key="asterisk-ari",resource_type="conference_participant",failure_class="runtime_unavailable",reason="runtime_unavailable"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_operations_total{operation="conference.close",result="retry_scheduled",failure_class="runtime_unavailable"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_operation_failures_total{operation="conference.participant.ensure",result="terminal_failed",failure_class="timeout"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_stale_events_rejected_total{result="conflict",reason="stale_epoch"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_backlog{resource_type="conference",result="waiting"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_backlog{resource_type="conference_participant",result="operation_required"} 1', $body);
        $this->assertStringContainsString('utcp_conference_recovery_lag_seconds{resource_type="conference"}', $body);

        foreach ([
            'tenant_id=',
            'user_id=',
            'conference_id=',
            'participant_id=',
            'runtime_node_id=',
            'runtime_binding_id=',
            'bridge_id=',
            'channel_id=',
            'operation_id=',
            'receipt_id=',
            'event_epoch_id=',
            'fencing_token=',
            'owner_id=',
            'request_id=',
            $tenantId,
            $nodeId,
            $conferenceId,
            $participantId,
            $epochId,
            $receiptId,
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }
}
