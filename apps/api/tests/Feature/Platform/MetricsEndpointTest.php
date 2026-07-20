<?php

namespace Tests\Feature\Platform;

use App\Identity\IdentityIds;
use App\RuntimeEngine\EngineIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
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

    public function test_metrics_endpoint_reports_t5_resilience_observability_from_durable_state(): void
    {
        $now = Carbon::parse('2026-07-20 12:00:00');
        $this->travelTo($now);
        Config::set('utcp.build.version', '5.8.0-t5');
        Config::set('utcp.build.commit', '5b219f1');

        $tenantId = IdentityIds::new();
        $userId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        $conferenceId = IdentityIds::new();
        $pendingConferenceId = IdentityIds::new();
        $orphanConferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $activeBindingId = IdentityIds::new();
        $retiredBindingId = IdentityIds::new();
        $operationId = EngineIds::new();
        $rawErrorText = 'dial +15551234567 failed with secret-token-value';
        $channelId = 'Local/participant@utcp-conference-proof-00000001;1';

        $this->insertTenantUserAndNode($tenantId, $userId, $nodeId, $now);
        $this->insertConference($conferenceId, $tenantId, $nodeId, 'closed', 'closed', 3, $now);
        $this->insertConference($pendingConferenceId, $tenantId, $nodeId, 'open', 'unavailable', 2, $now, [
            'failover_state' => 'pending_no_capacity',
            'failover_binding_id' => $activeBindingId,
            'failover_generation' => 2,
            'failover_started_at' => $now->copy()->subMinutes(20),
        ]);
        $this->insertConference($orphanConferenceId, $tenantId, $nodeId, 'closed', 'closed', 4, $now);
        $this->insertBinding($activeBindingId, $tenantId, $conferenceId, $nodeId, 'active', $now->copy()->subMinutes(30));
        $this->insertBinding($retiredBindingId, $tenantId, $orphanConferenceId, $nodeId, 'retired', $now->copy()->subMinutes(25), $now->copy()->subMinutes(5));
        $this->insertSessionAndParticipant($sessionId, $participantId, $tenantId, $userId, $orphanConferenceId, $now);

        $this->insertOutbox('conference.failover_coordinator.no_replacement', 'conference', $conferenceId, $tenantId, ['reason' => 'no_replacement_available'], $now);
        $this->insertOutbox('conference.failover_coordinator.no_replacement', 'conference', $pendingConferenceId, $tenantId, ['reason' => 'no_replacement_available'], $now);
        $this->insertOutbox('conference.runtime_binding_replaced', 'conference', $conferenceId, $tenantId, ['reason' => 'runtime_binding_replaced'], $now);
        $this->insertOutbox('conference.opened', 'conference', $conferenceId, $tenantId, ['reason' => 'ignored'], $now);
        $this->insertOutbox('conference.runtime_binding_retired', 'conference', $conferenceId, $tenantId, [
            'runtime_binding_id' => $activeBindingId,
            'retirement_reason' => 'conference_closed',
        ], $now);
        $this->insertOutbox('conference.runtime_binding_retired', 'conference', $orphanConferenceId, $tenantId, [
            'runtime_binding_id' => $retiredBindingId,
            'retirement_reason' => 'historical_manual_string',
        ], $now);
        $this->insertOutbox('conference_participant.channel_reclaimed', 'conference_participant', $participantId, $tenantId, [
            'classification' => 'post_closure_orphan',
            'primary_channel_id' => $channelId,
            'operation_id' => $operationId,
        ], $now);
        $this->insertOutbox('conference_participant.channel_reclaimed', 'conference_participant', IdentityIds::new(), $tenantId, [
            'classification' => 'unexpected_historical_classification',
        ], $now);

        foreach ([
            ['conference', 'healthy_present'],
            ['conference', 'healthy_absent'],
            ['conference_participant', 'degraded_unavailable'],
            ['conference_participant', 'transport_unavailable'],
            ['conference_participant', 'legacy_reason'],
        ] as [$resourceType, $reason]) {
            DB::table('conference_recovery_metric_events')->insert([
                'id' => EngineIds::new(),
                'adapter_key' => 'asterisk-ari',
                'resource_type' => $resourceType,
                'result' => $reason === 'degraded_unavailable' || $reason === 'transport_unavailable' ? 'unavailable' : 'observed',
                'failure_class' => $reason === 'degraded_unavailable' ? 'runtime_unavailable' : 'none',
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->insertRuntimeOperation('runtime.node.verify_conference_absent', 'succeeded', null, $tenantId, $conferenceId, $nodeId, $now);
        $this->insertRuntimeOperation('runtime.node.runtime.fence', 'terminal_failed', 'runtime_unavailable', $tenantId, $conferenceId, $nodeId, $now);
        $this->insertRuntimeOperation('runtime.node.restore', 'retry_scheduled', 'timeout', $tenantId, $conferenceId, $nodeId, $now);
        $this->insertRuntimeOperation('runtime.node.restore', 'succeeded', null, $tenantId, $conferenceId, $nodeId, $now);
        $this->insertRuntimeOperation('conference.participant.remove', 'terminal_failed', 'surprise_failure_value', $tenantId, $participantId, $nodeId, $now, $operationId, 8, $rawErrorText);
        $this->insertRuntimeOperation('conference.ensure', 'succeeded', null, $tenantId, $conferenceId, $nodeId, $now);

        $first = (string) $this->get('/api/metrics')->assertOk()->getContent();
        $second = (string) $this->get('/api/metrics')->assertOk()->getContent();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('utcp_conference_failover_events_total{event_type="no_replacement"} 2', $first);
        $this->assertStringContainsString('utcp_conference_failover_events_total{event_type="runtime_binding_replaced"} 1', $first);
        $this->assertStringContainsString('utcp_conference_failover_pending{failover_state="pending_no_capacity"} 1', $first);
        $this->assertStringContainsString('utcp_conference_failover_pending_oldest_seconds{failover_state="pending_no_capacity"} 1200', $first);
        $this->assertStringContainsString('utcp_runtime_resilience_operations_total{operation_type="runtime.node.verify_conference_absent",result="succeeded",failure_class="none"} 1', $first);
        $this->assertStringContainsString('utcp_runtime_resilience_operations_total{operation_type="runtime.node.runtime.fence",result="terminal_failed",failure_class="runtime_unavailable"} 1', $first);
        $this->assertStringContainsString('utcp_runtime_resilience_operations_total{operation_type="runtime.node.restore",result="retry_scheduled",failure_class="timeout"} 1', $first);
        $this->assertStringContainsString('utcp_runtime_resilience_operations_total{operation_type="runtime.node.restore",result="succeeded",failure_class="none"} 1', $first);
        $this->assertStringContainsString('utcp_runtime_resilience_operations_total{operation_type="conference.participant.remove",result="terminal_failed",failure_class="other"} 1', $first);
        $this->assertStringNotContainsString('utcp_runtime_resilience_operations_total{operation_type="conference.ensure"', $first);
        $this->assertStringContainsString('utcp_conference_runtime_binding_retired_total{reason="conference_closed"} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_binding_retired_total{reason="other"} 1', $first);
        $this->assertStringContainsString('utcp_conference_stale_active_bindings{} 1', $first);
        $this->assertStringContainsString('utcp_conference_participant_channel_reclaimed_total{classification="post_closure_orphan"} 1', $first);
        $this->assertStringContainsString('utcp_conference_participant_channel_reclaimed_total{classification="other"} 1', $first);
        $this->assertStringContainsString('utcp_conference_orphan_participant_candidates{} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_reference_health_total{resource_type="conference",health="healthy_present"} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_reference_health_total{resource_type="conference",health="healthy_absent"} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_reference_health_total{resource_type="conference_participant",health="degraded_unavailable"} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_reference_health_total{resource_type="conference_participant",health="transport_unavailable"} 1', $first);
        $this->assertStringContainsString('utcp_conference_runtime_reference_health_total{resource_type="conference_participant",health="other"} 1', $first);
        $this->assertStringContainsString('utcp_build_info{version="5.8.0-t5",commit="5b219f1"} 1', $first);
        $this->assertStringNotContainsString('pod=', $first);
        $this->assertStringNotContainsString('digest=', $first);
        $this->assertStringNotContainsString('built_at=', $first);

        foreach ([
            'tenant_id=',
            'conference_id=',
            'participant_id=',
            'session_id=',
            'runtime_binding_id=',
            'runtime_node_id=',
            'operation_id=',
            'channel_id=',
            $tenantId,
            $conferenceId,
            $pendingConferenceId,
            $orphanConferenceId,
            $participantId,
            $sessionId,
            $activeBindingId,
            $retiredBindingId,
            $operationId,
            $channelId,
            $rawErrorText,
            '+15551234567',
            'secret-token-value',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $first);
        }
    }

    public function test_t5_resilience_alert_rules_are_valid_and_actionable(): void
    {
        $path = base_path('../../infrastructure/kubernetes/observability/alerts/utcp-alerts.yaml');
        $yaml = (string) file_get_contents($path);

        foreach ([
            'UTCPConferencePendingNoCapacity' => 'for: 15m',
            'UTCPRuntimeFenceTerminalFailure' => 'operation_type="runtime.node.runtime.fence"',
            'UTCPRuntimeRestoreTerminalFailure' => 'operation_type="runtime.node.restore"',
            'UTCPStaleActiveRuntimeBindings' => 'for: 10m',
            'UTCPOrphanParticipantCandidates' => 'database-derived upper bound',
            'UTCPAriReferenceFamilyDegraded' => 'health="degraded_unavailable"',
        ] as $alert => $requiredText) {
            $this->assertSame(1, substr_count($yaml, 'alert: '.$alert), $alert.' must be declared exactly once.');
            $this->assertStringContainsString($requiredText, $yaml);
        }

        foreach ([
            'utcp_conference_failover_pending',
            'utcp_runtime_resilience_operations_total',
            'utcp_conference_stale_active_bindings',
            'utcp_conference_orphan_participant_candidates',
            'utcp_conference_runtime_reference_health_total',
        ] as $metric) {
            $this->assertStringContainsString($metric, $yaml);
        }

        $this->assertStringNotContainsString('UTCPWorkerVersionSkew', $yaml);
        $this->assertStringNotContainsString('manual reconciliation', strtolower($yaml));
        $this->assertStringNotContainsString('artisan', strtolower($yaml));
        $this->assertStringNotContainsString('tenant_id', $yaml);
        $this->assertMatchesRegularExpression('/apiVersion: monitoring\.coreos\.com\/v1\s+kind: PrometheusRule/s', $yaml);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertConference(string $conferenceId, string $tenantId, string $nodeId, string $desired, string $observed, int $generation, Carbon $now, array $overrides = []): void
    {
        DB::table('conferences')->insert(array_merge([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'conf-'.substr($conferenceId, 0, 8),
            'display_name' => 'Conference '.substr($conferenceId, 0, 8),
            'runtime_node_id' => $nodeId,
            'desired_state' => $desired,
            'observed_state' => $observed,
            'configuration_generation' => $generation,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertTenantUserAndNode(string $tenantId, string $userId, string $nodeId, Carbon $now): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 't5-resilience-metrics',
            'display_name' => 'T5 Resilience Metrics',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'metrics@example.test',
            'normalized_email' => 'metrics@example.test',
            'display_name' => 'Metrics User',
            'password' => 'not-a-real-password',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Metrics Asterisk T5',
            'slug' => 'metrics-asterisk-t5',
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
    }

    private function insertBinding(string $bindingId, string $tenantId, string $conferenceId, string $nodeId, string $status, Carbon $boundAt, ?Carbon $unboundAt = null): void
    {
        DB::table('conference_runtime_bindings')->insert([
            'id' => $bindingId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => $status,
            'bound_at' => $boundAt,
            'unbound_at' => $unboundAt,
            'created_at' => $boundAt,
            'updated_at' => $unboundAt ?? $boundAt,
        ]);
    }

    private function insertSessionAndParticipant(string $sessionId, string $participantId, string $tenantId, string $userId, string $conferenceId, Carbon $now): void
    {
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'ended',
            'issued_at' => $now->copy()->subHour(),
            'expires_at' => $now->copy()->addHour(),
            'ended_at' => $now->copy()->subMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'desired_state' => 'removed',
            'observed_state' => 'leaving',
            'role' => 'participant',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertOutbox(string $eventType, string $aggregateType, string $aggregateId, string $tenantId, array $payload, Carbon $now): void
    {
        DB::table('control_plane_outbox_messages')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'event_version' => 1,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'correlation_id' => EngineIds::new(),
            'request_id' => EngineIds::new(),
            'occurred_at' => $now,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertRuntimeOperation(string $operationType, string $status, ?string $failureClass, string $tenantId, string $aggregateId, string $nodeId, Carbon $now, ?string $operationId = null, int $attemptCount = 0, ?string $failureMessage = null): void
    {
        DB::table('runtime_operations')->insert([
            'id' => $operationId ?? EngineIds::new(),
            'tenant_id' => $tenantId,
            'operation_type' => $operationType,
            'aggregate_type' => str_starts_with($operationType, 'conference.participant') ? 'conference_participant' : 'conference',
            'aggregate_id' => $aggregateId,
            'runtime_node_id' => $nodeId,
            'payload_version' => 1,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => $status,
            'idempotency_key' => 'metrics-'.EngineIds::new(),
            'correlation_id' => EngineIds::new(),
            'request_id' => EngineIds::new(),
            'attempt_count' => $attemptCount,
            'available_at' => $now,
            'last_failure_class' => $failureClass,
            'last_failure_code' => $failureClass,
            'last_failure_message' => $failureMessage,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
