<?php

namespace App\Http\Controllers\Platform;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\RuntimeEngine\Sources\EventSourceRepository;
use App\Support\Build\BuildInfo;
use App\TelephonyDomain\Signaling\KamailioRegistrationObserver;
use App\TelephonyDomain\Signaling\KamailioRegistrationPollHealthRepository;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MetricsController
{
    public function __construct(
        private readonly BuildInfo $build,
    ) {}

    public function __invoke(): Response
    {
        $lines = [
            '# HELP simulator_operations_total Deterministic simulator runtime operations by operation type, result, scenario, and failure class.',
            '# TYPE simulator_operations_total counter',
            ...$this->operationMetrics(),
            '# HELP simulator_scheduled_events Deterministic simulator scheduled events by scenario, event type, result, and due state.',
            '# TYPE simulator_scheduled_events gauge',
            ...$this->scheduledEventMetrics(),
            '# HELP simulator_event_publish_total Deterministic simulator event-source publish attempts by event type and result.',
            '# TYPE simulator_event_publish_total counter',
            ...$this->eventPublishMetrics(),
            '# HELP simulator_scenario_transitions_total Deterministic simulator scenario transitions by scenario and phase.',
            '# TYPE simulator_scenario_transitions_total counter',
            ...$this->scenarioTransitionMetrics(),
            '# HELP simulator_connection_epochs_total Deterministic simulator connection epochs by scenario and result.',
            '# TYPE simulator_connection_epochs_total counter',
            ...$this->connectionEpochMetrics(),
            '# HELP simulator_reconciliation_total Deterministic simulator reconciliation states by scenario and result.',
            '# TYPE simulator_reconciliation_total counter',
            ...$this->reconciliationMetrics(),
            '# HELP telephony_sessions_total Telephony-domain sessions by lifecycle status.',
            '# TYPE telephony_sessions_total gauge',
            ...$this->telephonySessionMetrics(),
            '# HELP telephony_sessions_active Active telephony-domain sessions.',
            '# TYPE telephony_sessions_active gauge',
            ...$this->telephonySessionActiveMetrics(),
            '# HELP telephony_sessions_expired_active Active telephony-domain sessions whose expiry timestamp is in the past.',
            '# TYPE telephony_sessions_expired_active gauge',
            ...$this->telephonySessionExpiredActiveMetrics(),
            '# HELP conferences_total Conferences by desired state.',
            '# TYPE conferences_total gauge',
            ...$this->conferenceDesiredMetrics(),
            '# HELP conferences_by_observed_state Conferences by observed state.',
            '# TYPE conferences_by_observed_state gauge',
            ...$this->conferenceObservedMetrics(),
            '# HELP conference_participants_total Conference participants by desired and observed state.',
            '# TYPE conference_participants_total gauge',
            ...$this->conferenceParticipantMetrics(),
            '# HELP conference_operations_total Conference runtime operations by operation type, result, and failure class.',
            '# TYPE conference_operations_total counter',
            ...$this->conferenceOperationMetrics(['conference.ensure', 'conference.close'], 'conference_operations_total'),
            '# HELP conference_participant_operations_total Conference participant runtime operations by operation type, result, and failure class.',
            '# TYPE conference_participant_operations_total counter',
            ...$this->conferenceOperationMetrics(['conference.participant.ensure', 'conference.participant.remove'], 'conference_participant_operations_total'),
            '# HELP conference_reconciliation_total Conference reconciliation states by result.',
            '# TYPE conference_reconciliation_total counter',
            ...$this->telephonyReconciliationMetrics('conference', 'conference_reconciliation_total'),
            '# HELP conference_participant_reconciliation_total Conference participant reconciliation states by result.',
            '# TYPE conference_participant_reconciliation_total counter',
            ...$this->telephonyReconciliationMetrics('conference_participant', 'conference_participant_reconciliation_total'),
            '# HELP utcp_conference_runtime_inspections_total Conference runtime inspections by adapter, resource type, result, and failure class.',
            '# TYPE utcp_conference_runtime_inspections_total counter',
            ...$this->conferenceRecoveryInspectionMetrics(),
            '# HELP utcp_conference_runtime_inspection_failures_total Conference runtime inspection failures by adapter, resource type, failure class, and reason.',
            '# TYPE utcp_conference_runtime_inspection_failures_total counter',
            ...$this->conferenceRecoveryInspectionFailureMetrics(),
            '# HELP utcp_conference_recovery_operations_total Conference recovery runtime operations by operation, result, and failure class.',
            '# TYPE utcp_conference_recovery_operations_total counter',
            ...$this->conferenceRecoveryOperationMetrics(false),
            '# HELP utcp_conference_recovery_operation_failures_total Conference recovery runtime operation failures by operation, result, and failure class.',
            '# TYPE utcp_conference_recovery_operation_failures_total counter',
            ...$this->conferenceRecoveryOperationMetrics(true),
            '# HELP utcp_conference_recovery_stale_events_rejected_total Conference recovery stale or superseded event receipts rejected by result and reason.',
            '# TYPE utcp_conference_recovery_stale_events_rejected_total counter',
            ...$this->conferenceRecoveryStaleEventMetrics(),
            '# HELP utcp_conference_recovery_backlog Conference recovery reconciliation backlog by resource type and result.',
            '# TYPE utcp_conference_recovery_backlog gauge',
            ...$this->conferenceRecoveryBacklogMetrics(),
            '# HELP utcp_conference_recovery_lag_seconds Oldest non-converged conference recovery reconciliation age by resource type.',
            '# TYPE utcp_conference_recovery_lag_seconds gauge',
            ...$this->conferenceRecoveryLagMetrics(),
            '# HELP utcp_conference_failover_events_total Durable conference failover transition events by bounded event type. Maximum series: 2.',
            '# TYPE utcp_conference_failover_events_total counter',
            ...$this->conferenceFailoverEventMetrics(),
            '# HELP utcp_conference_failover_pending Current conferences pending failover replacement capacity by failover state. Maximum series: 1.',
            '# TYPE utcp_conference_failover_pending gauge',
            ...$this->conferenceFailoverPendingMetrics(),
            '# HELP utcp_conference_failover_pending_oldest_seconds Age in seconds of the oldest conference currently pending failover replacement capacity. Maximum series: 1.',
            '# TYPE utcp_conference_failover_pending_oldest_seconds gauge',
            ...$this->conferenceFailoverPendingOldestMetrics(),
            '# HELP utcp_runtime_resilience_operations_total T5 resilience runtime operation objects by canonical operation type, result, and failure class. Attempts are not counted separately. Maximum series: 432.',
            '# TYPE utcp_runtime_resilience_operations_total counter',
            ...$this->runtimeResilienceOperationMetrics(),
            '# HELP utcp_conference_runtime_binding_retired_total Durable final RuntimeBinding retirements after canonical conference closure by bounded reason. Maximum series: 2.',
            '# TYPE utcp_conference_runtime_binding_retired_total counter',
            ...$this->runtimeBindingRetiredMetrics(),
            '# HELP utcp_conference_stale_active_bindings Closed and observed-closed conferences that still have an active RuntimeBinding.',
            '# TYPE utcp_conference_stale_active_bindings gauge',
            ...$this->staleActiveBindingMetrics(),
            '# HELP utcp_conference_participant_channel_reclaimed_total Durable orphan participant channel reclamation events by bounded classification. Maximum series: 2.',
            '# TYPE utcp_conference_participant_channel_reclaimed_total counter',
            ...$this->participantChannelReclaimedMetrics(),
            '# HELP utcp_conference_orphan_participant_candidates Database-derived upper bound of removed participants on closed conferences with retained final binding history. This does not prove a PBX channel is currently alive.',
            '# TYPE utcp_conference_orphan_participant_candidates gauge',
            ...$this->orphanParticipantCandidateMetrics(),
            '# HELP utcp_conference_orphan_reclamation_operations_total Durable orphan participant-channel reclamation runtime operation objects by result and failure class. Attempts are not counted separately. Maximum series: 104.',
            '# TYPE utcp_conference_orphan_reclamation_operations_total counter',
            ...$this->orphanReclamationOperationMetrics(),
            '# HELP utcp_conference_runtime_reference_health_total Conference runtime reference inspections by bounded resource type and ARI reference-health classification. Maximum series: 15.',
            '# TYPE utcp_conference_runtime_reference_health_total counter',
            ...$this->runtimeReferenceHealthMetrics(),
            '# HELP utcp_build_info Build information for the application serving this metrics endpoint.',
            '# TYPE utcp_build_info gauge',
            ...$this->buildInfoMetrics(),
            '# HELP asterisk_ari_nodes Asterisk ARI runtime nodes by desired and observed state.',
            '# TYPE asterisk_ari_nodes gauge',
            ...$this->asteriskAriNodeMetrics(),
            '# HELP asterisk_ari_http_requests_total Asterisk ARI HTTP inspection operations by result and failure class.',
            '# TYPE asterisk_ari_http_requests_total counter',
            ...$this->asteriskAriHttpRequestMetrics(),
            '# HELP asterisk_ari_websocket_connections Asterisk ARI WebSocket connection epochs by result.',
            '# TYPE asterisk_ari_websocket_connections gauge',
            ...$this->asteriskAriWebsocketMetrics(),
            '# HELP asterisk_ari_events_received_total Asterisk ARI event receipts by event type and result.',
            '# TYPE asterisk_ari_events_received_total counter',
            ...$this->asteriskAriEventReceiptMetrics(),
            '# HELP asterisk_ari_reconnects_total Asterisk ARI connection epochs by connection result.',
            '# TYPE asterisk_ari_reconnects_total counter',
            ...$this->asteriskAriReconnectMetrics(),
            '# HELP asterisk_ari_authentication_failures_total Asterisk ARI authentication failure evidence count.',
            '# TYPE asterisk_ari_authentication_failures_total counter',
            ...$this->asteriskAriAuthenticationFailureMetrics(),
            '# HELP asterisk_ari_listener_claims_total Asterisk ARI listener leases by result.',
            '# TYPE asterisk_ari_listener_claims_total counter',
            ...$this->asteriskAriListenerClaimMetrics(),
            '# HELP asterisk_ari_listener_nodes Asterisk ARI nodes currently claimed by listener ownership.',
            '# TYPE asterisk_ari_listener_nodes gauge',
            ...$this->asteriskAriListenerNodeMetrics(),
            '# HELP kamailio_registration_snapshot_polls_total Kamailio registration observer usrloc snapshot polls by result.',
            '# TYPE kamailio_registration_snapshot_polls_total counter',
            ...$this->kamailioRegistrationPollMetrics(),
            '# HELP kamailio_registration_snapshot_poll_failures_total Kamailio registration observer usrloc snapshot poll failures.',
            '# TYPE kamailio_registration_snapshot_poll_failures_total counter',
            ...$this->kamailioRegistrationPollFailureMetrics(),
            '# HELP kamailio_registration_observer_claims_total Kamailio registration observer listener leases by status.',
            '# TYPE kamailio_registration_observer_claims_total counter',
            ...$this->kamailioRegistrationObserverClaimMetrics(),
            '# HELP kamailio_registration_observer_active Whether an unexpired Kamailio registration observer lease is currently claimed.',
            '# TYPE kamailio_registration_observer_active gauge',
            ...$this->kamailioRegistrationObserverActiveMetrics(),
            '# HELP kamailio_registration_observer_lag_seconds Seconds since the Kamailio registration observer checkpoint last advanced.',
            '# TYPE kamailio_registration_observer_lag_seconds gauge',
            ...$this->kamailioRegistrationObserverLagMetrics(),
            '# HELP kamailio_registration_receipts_total Kamailio registration event receipts by event type and status.',
            '# TYPE kamailio_registration_receipts_total counter',
            ...$this->kamailioRegistrationReceiptMetrics(),
            '# HELP kamailio_registration_projection_backlog Kamailio registration receipts not yet processed by the normalizer.',
            '# TYPE kamailio_registration_projection_backlog gauge',
            ...$this->kamailioRegistrationProjectionBacklogMetrics(),
            '# HELP kamailio_registration_contacts Current unexpired Kamailio usrloc Contact rows.',
            '# TYPE kamailio_registration_contacts gauge',
            ...$this->kamailioRegistrationContactMetrics(),
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * @return list<string>
     */
    private function operationMetrics(): array
    {
        if (! $this->hasTables(['runtime_operations', 'runtime_nodes', 'simulator_profiles'])) {
            return [$this->sample('simulator_operations_total', ['operation_type' => 'none', 'result' => 'none', 'scenario' => 'none', 'failure_class' => 'none'], 0)];
        }

        $nodeScenarios = $this->simulatorNodeScenarios();

        if ($nodeScenarios === []) {
            return [$this->sample('simulator_operations_total', ['operation_type' => 'none', 'result' => 'none', 'scenario' => 'none', 'failure_class' => 'none'], 0)];
        }

        return DB::table('runtime_operations')
            ->whereIn('runtime_operations.runtime_node_id', array_keys($nodeScenarios))
            ->selectRaw("runtime_operations.runtime_node_id, runtime_operations.operation_type, runtime_operations.status as result, coalesce(runtime_operations.last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('runtime_operations.runtime_node_id', 'runtime_operations.operation_type', 'runtime_operations.status', 'runtime_operations.last_failure_class')
            ->orderBy('operation_type')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_operations_total', [
                'operation_type' => (string) $row->operation_type,
                'result' => (string) $row->result,
                'scenario' => (string) ($nodeScenarios[(string) $row->runtime_node_id] ?? 'unconfigured'),
                'failure_class' => (string) $row->failure_class,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function scheduledEventMetrics(): array
    {
        if (! $this->hasTables(['simulator_scheduled_events', 'simulator_profiles'])) {
            return [$this->sample('simulator_scheduled_events', ['scenario' => 'none', 'event_type' => 'none', 'result' => 'none', 'due' => 'none'], 0)];
        }

        return DB::table('simulator_scheduled_events')
            ->leftJoin('simulator_profiles', 'simulator_profiles.runtime_node_id', '=', 'simulator_scheduled_events.runtime_node_id')
            ->selectRaw("coalesce(simulator_profiles.scenario_key, 'unconfigured') as scenario, simulator_scheduled_events.event_type, simulator_scheduled_events.status as result, case when simulator_scheduled_events.due_at <= CURRENT_TIMESTAMP then 'due' else 'future' end as due, count(*) as count")
            ->groupBy('simulator_profiles.scenario_key', 'simulator_scheduled_events.event_type', 'simulator_scheduled_events.status', 'due')
            ->orderBy('scenario')
            ->orderBy('event_type')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_scheduled_events', [
                'scenario' => (string) $row->scenario,
                'event_type' => (string) $row->event_type,
                'result' => (string) $row->result,
                'due' => (string) $row->due,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function eventPublishMetrics(): array
    {
        if (! Schema::hasTable('simulator_scheduled_events')) {
            return [$this->sample('simulator_event_publish_total', ['event_type' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('simulator_scheduled_events')
            ->selectRaw('event_type, status as result, count(*) as count')
            ->whereIn('status', ['published', 'retry_scheduled', 'terminal_failed'])
            ->groupBy('event_type', 'status')
            ->orderBy('event_type')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_event_publish_total', [
                'event_type' => (string) $row->event_type,
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function scenarioTransitionMetrics(): array
    {
        if (! Schema::hasTable('simulator_states')) {
            return [$this->sample('simulator_scenario_transitions_total', ['scenario' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('simulator_states')
            ->selectRaw('scenario_key as scenario, current_phase as result, count(*) as count')
            ->groupBy('scenario_key', 'current_phase')
            ->orderBy('scenario')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_scenario_transitions_total', [
                'scenario' => (string) $row->scenario,
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function connectionEpochMetrics(): array
    {
        if (! $this->hasTables(['runtime_event_connection_epochs', 'simulator_profiles'])) {
            return [$this->sample('simulator_connection_epochs_total', ['scenario' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('runtime_event_connection_epochs')
            ->leftJoin('simulator_profiles', 'simulator_profiles.runtime_node_id', '=', 'runtime_event_connection_epochs.runtime_node_id')
            ->where('runtime_event_connection_epochs.adapter_key', config('simulator.adapter_key', 'simulator-deterministic'))
            ->selectRaw("coalesce(simulator_profiles.scenario_key, 'unconfigured') as scenario, runtime_event_connection_epochs.status as result, count(*) as count")
            ->groupBy('simulator_profiles.scenario_key', 'runtime_event_connection_epochs.status')
            ->orderBy('scenario')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_connection_epochs_total', [
                'scenario' => (string) $row->scenario,
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function reconciliationMetrics(): array
    {
        if (! $this->hasTables(['runtime_reconciliation_states', 'runtime_nodes', 'simulator_profiles'])) {
            return [$this->sample('simulator_reconciliation_total', ['scenario' => 'none', 'result' => 'none'], 0)];
        }

        $nodeScenarios = $this->simulatorNodeScenarios();

        if ($nodeScenarios === []) {
            return [$this->sample('simulator_reconciliation_total', ['scenario' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('runtime_reconciliation_states')
            ->where('runtime_reconciliation_states.target_type', 'runtime_node')
            ->whereIn('runtime_reconciliation_states.target_id', array_keys($nodeScenarios))
            ->selectRaw('runtime_reconciliation_states.target_id, runtime_reconciliation_states.status as result, count(*) as count')
            ->groupBy('runtime_reconciliation_states.target_id', 'runtime_reconciliation_states.status')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('simulator_reconciliation_total', [
                'scenario' => (string) ($nodeScenarios[(string) $row->target_id] ?? 'unconfigured'),
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function telephonySessionMetrics(): array
    {
        if (! Schema::hasTable('telephony_sessions')) {
            return [$this->sample('telephony_sessions_total', ['status' => 'none'], 0)];
        }

        return DB::table('telephony_sessions')
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): string => $this->sample('telephony_sessions_total', [
                'status' => (string) $row->status,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function telephonySessionActiveMetrics(): array
    {
        if (! Schema::hasTable('telephony_sessions')) {
            return [$this->sample('telephony_sessions_active', ['status' => 'active'], 0)];
        }

        return [$this->sample('telephony_sessions_active', ['status' => 'active'], DB::table('telephony_sessions')->where('status', 'active')->count())];
    }

    /**
     * @return list<string>
     */
    private function telephonySessionExpiredActiveMetrics(): array
    {
        if (! Schema::hasTable('telephony_sessions')) {
            return [$this->sample('telephony_sessions_expired_active', ['status' => 'active'], 0)];
        }

        return [$this->sample('telephony_sessions_expired_active', ['status' => 'active'], DB::table('telephony_sessions')->where('status', 'active')->where('expires_at', '<=', now())->count())];
    }

    /**
     * @return list<string>
     */
    private function conferenceDesiredMetrics(): array
    {
        if (! Schema::hasTable('conferences')) {
            return [$this->sample('conferences_total', ['desired_state' => 'none'], 0)];
        }

        return DB::table('conferences')
            ->selectRaw('desired_state, count(*) as count')
            ->groupBy('desired_state')
            ->orderBy('desired_state')
            ->get()
            ->map(fn (object $row): string => $this->sample('conferences_total', [
                'desired_state' => (string) $row->desired_state,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceObservedMetrics(): array
    {
        if (! Schema::hasTable('conferences')) {
            return [$this->sample('conferences_by_observed_state', ['observed_state' => 'none'], 0)];
        }

        return DB::table('conferences')
            ->selectRaw('observed_state, count(*) as count')
            ->groupBy('observed_state')
            ->orderBy('observed_state')
            ->get()
            ->map(fn (object $row): string => $this->sample('conferences_by_observed_state', [
                'observed_state' => (string) $row->observed_state,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceParticipantMetrics(): array
    {
        if (! Schema::hasTable('conference_participants')) {
            return [$this->sample('conference_participants_total', ['desired_state' => 'none', 'observed_state' => 'none'], 0)];
        }

        return DB::table('conference_participants')
            ->selectRaw('desired_state, observed_state, count(*) as count')
            ->groupBy('desired_state', 'observed_state')
            ->orderBy('desired_state')
            ->orderBy('observed_state')
            ->get()
            ->map(fn (object $row): string => $this->sample('conference_participants_total', [
                'desired_state' => (string) $row->desired_state,
                'observed_state' => (string) $row->observed_state,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @param  list<string>  $operationTypes
     * @return list<string>
     */
    private function conferenceOperationMetrics(array $operationTypes, string $metric): array
    {
        if (! Schema::hasTable('runtime_operations')) {
            return [$this->sample($metric, ['operation_type' => 'none', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        return DB::table('runtime_operations')
            ->whereIn('operation_type', $operationTypes)
            ->selectRaw("operation_type, status as result, coalesce(last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('operation_type', 'status', 'last_failure_class')
            ->orderBy('operation_type')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample($metric, [
                'operation_type' => (string) $row->operation_type,
                'result' => (string) $row->result,
                'failure_class' => (string) $row->failure_class,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function telephonyReconciliationMetrics(string $targetType, string $metric): array
    {
        if (! Schema::hasTable('runtime_reconciliation_states')) {
            return [$this->sample($metric, ['result' => 'none'], 0)];
        }

        return DB::table('runtime_reconciliation_states')
            ->where('target_type', $targetType)
            ->selectRaw('status as result, count(*) as count')
            ->groupBy('status')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample($metric, [
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryInspectionMetrics(): array
    {
        if (! Schema::hasTable('conference_recovery_metric_events')) {
            return [$this->sample('utcp_conference_runtime_inspections_total', ['adapter_key' => 'none', 'resource_type' => 'none', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        $rows = DB::table('conference_recovery_metric_events')
            ->selectRaw('adapter_key, resource_type, result, failure_class, count(*) as count')
            ->groupBy('adapter_key', 'resource_type', 'result', 'failure_class')
            ->orderBy('adapter_key')
            ->orderBy('resource_type')
            ->orderBy('result')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample('utcp_conference_runtime_inspections_total', ['adapter_key' => 'none', 'resource_type' => 'none', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        return $rows->map(fn (object $row): string => $this->sample('utcp_conference_runtime_inspections_total', [
            'adapter_key' => (string) $row->adapter_key,
            'resource_type' => (string) $row->resource_type,
            'result' => (string) $row->result,
            'failure_class' => (string) $row->failure_class,
        ], (int) $row->count))->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryInspectionFailureMetrics(): array
    {
        if (! Schema::hasTable('conference_recovery_metric_events')) {
            return [$this->sample('utcp_conference_runtime_inspection_failures_total', ['adapter_key' => 'none', 'resource_type' => 'none', 'failure_class' => 'none', 'reason' => 'none'], 0)];
        }

        $rows = DB::table('conference_recovery_metric_events')
            ->whereIn('result', ['unavailable', 'failed'])
            ->selectRaw('adapter_key, resource_type, failure_class, reason, count(*) as count')
            ->groupBy('adapter_key', 'resource_type', 'failure_class', 'reason')
            ->orderBy('adapter_key')
            ->orderBy('resource_type')
            ->orderBy('failure_class')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample('utcp_conference_runtime_inspection_failures_total', ['adapter_key' => 'none', 'resource_type' => 'none', 'failure_class' => 'none', 'reason' => 'none'], 0)];
        }

        return $rows->map(fn (object $row): string => $this->sample('utcp_conference_runtime_inspection_failures_total', [
            'adapter_key' => (string) $row->adapter_key,
            'resource_type' => (string) $row->resource_type,
            'failure_class' => (string) $row->failure_class,
            'reason' => (string) $row->reason,
        ], (int) $row->count))->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryOperationMetrics(bool $failuresOnly): array
    {
        $metric = $failuresOnly ? 'utcp_conference_recovery_operation_failures_total' : 'utcp_conference_recovery_operations_total';
        if (! Schema::hasTable('runtime_operations')) {
            return [$this->sample($metric, ['operation' => 'none', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        $query = DB::table('runtime_operations')
            ->whereIn('operation_type', $this->conferenceRecoveryOperationTypes());
        if ($failuresOnly) {
            $query->where(function ($failure): void {
                $failure->whereIn('status', ['retry_scheduled', 'terminal_failed', 'expired'])
                    ->orWhereNotNull('last_failure_class');
            });
        }

        $rows = $query
            ->selectRaw("operation_type as operation, status as result, coalesce(last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('operation_type', 'status', 'last_failure_class')
            ->orderBy('operation')
            ->orderBy('result')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample($metric, ['operation' => 'none', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        return $rows->map(fn (object $row): string => $this->sample($metric, [
            'operation' => (string) $row->operation,
            'result' => (string) $row->result,
            'failure_class' => (string) $row->failure_class,
        ], (int) $row->count))->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryStaleEventMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_receipts')) {
            return [$this->sample('utcp_conference_recovery_stale_events_rejected_total', ['result' => 'none', 'reason' => 'none'], 0)];
        }

        $rows = DB::table('runtime_event_receipts')
            ->whereIn('event_type', $this->conferenceRecoveryEventTypes())
            ->whereIn('status', ['conflict', 'unsupported'])
            ->selectRaw("status as result, coalesce(failure_code, 'none') as reason, count(*) as count")
            ->groupBy('status', 'failure_code')
            ->orderBy('result')
            ->orderBy('reason')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample('utcp_conference_recovery_stale_events_rejected_total', ['result' => 'none', 'reason' => 'none'], 0)];
        }

        return $rows->map(fn (object $row): string => $this->sample('utcp_conference_recovery_stale_events_rejected_total', [
            'result' => (string) $row->result,
            'reason' => (string) $row->reason,
        ], (int) $row->count))->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryBacklogMetrics(): array
    {
        if (! Schema::hasTable('runtime_reconciliation_states')) {
            return [$this->sample('utcp_conference_recovery_backlog', ['resource_type' => 'none', 'result' => 'none'], 0)];
        }

        $rows = DB::table('runtime_reconciliation_states')
            ->whereIn('target_type', ['conference', 'conference_participant'])
            ->whereIn('status', ['operation_required', 'retry_scheduled', 'waiting', 'blocked'])
            ->selectRaw('target_type as resource_type, status as result, count(*) as count')
            ->groupBy('target_type', 'status')
            ->orderBy('resource_type')
            ->orderBy('result')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample('utcp_conference_recovery_backlog', ['resource_type' => 'none', 'result' => 'none'], 0)];
        }

        return $rows->map(fn (object $row): string => $this->sample('utcp_conference_recovery_backlog', [
            'resource_type' => (string) $row->resource_type,
            'result' => (string) $row->result,
        ], (int) $row->count))->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryLagMetrics(): array
    {
        if (! Schema::hasTable('runtime_reconciliation_states')) {
            return [$this->sample('utcp_conference_recovery_lag_seconds', ['resource_type' => 'none'], 0)];
        }

        $rows = DB::table('runtime_reconciliation_states')
            ->whereIn('target_type', ['conference', 'conference_participant'])
            ->whereIn('status', ['operation_required', 'retry_scheduled', 'waiting', 'blocked'])
            ->selectRaw('target_type as resource_type, min(updated_at) as oldest_updated_at')
            ->groupBy('target_type')
            ->orderBy('resource_type')
            ->get();

        if ($rows->isEmpty()) {
            return [$this->sample('utcp_conference_recovery_lag_seconds', ['resource_type' => 'none'], 0)];
        }

        return $rows->map(function (object $row): string {
            $lag = $row->oldest_updated_at === null ? 0 : max(0, (int) now()->diffInSeconds(Carbon::parse((string) $row->oldest_updated_at), true));

            return $this->sample('utcp_conference_recovery_lag_seconds', [
                'resource_type' => (string) $row->resource_type,
            ], $lag);
        })->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceFailoverEventMetrics(): array
    {
        if (! Schema::hasTable('control_plane_outbox_messages')) {
            return [];
        }

        return DB::table('control_plane_outbox_messages')
            ->whereIn('event_type', array_keys($this->failoverEventTypeLabels()))
            ->selectRaw('event_type, count(*) as count')
            ->groupBy('event_type')
            ->orderBy('event_type')
            ->get()
            ->map(fn (object $row): string => $this->sample('utcp_conference_failover_events_total', [
                'event_type' => $this->failoverEventTypeLabels()[(string) $row->event_type],
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function conferenceFailoverPendingMetrics(): array
    {
        if (! Schema::hasTable('conferences')) {
            return [$this->sample('utcp_conference_failover_pending', ['failover_state' => 'pending_no_capacity'], 0)];
        }

        return [$this->sample('utcp_conference_failover_pending', [
            'failover_state' => 'pending_no_capacity',
        ], DB::table('conferences')->where('failover_state', 'pending_no_capacity')->count())];
    }

    /**
     * @return list<string>
     */
    private function conferenceFailoverPendingOldestMetrics(): array
    {
        if (! Schema::hasTable('conferences')) {
            return [$this->sample('utcp_conference_failover_pending_oldest_seconds', ['failover_state' => 'pending_no_capacity'], 0)];
        }

        $oldest = DB::table('conferences')
            ->where('failover_state', 'pending_no_capacity')
            ->whereNotNull('failover_started_at')
            ->min('failover_started_at');
        $age = $oldest === null ? 0 : max(0, (int) now()->diffInSeconds(Carbon::parse((string) $oldest), true));

        return [$this->sample('utcp_conference_failover_pending_oldest_seconds', [
            'failover_state' => 'pending_no_capacity',
        ], $age)];
    }

    /**
     * @return list<string>
     */
    private function runtimeResilienceOperationMetrics(): array
    {
        if (! Schema::hasTable('runtime_operations')) {
            return [];
        }

        return DB::table('runtime_operations')
            ->whereIn('operation_type', $this->runtimeResilienceOperationTypes())
            ->selectRaw("operation_type, status as result, coalesce(last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('operation_type', 'status', 'last_failure_class')
            ->orderBy('operation_type')
            ->orderBy('result')
            ->orderBy('failure_class')
            ->get()
            ->map(fn (object $row): string => $this->sample('utcp_runtime_resilience_operations_total', [
                'operation_type' => $this->boundedValue((string) $row->operation_type, $this->runtimeResilienceOperationTypes()),
                'result' => $this->boundedValue((string) $row->result, $this->operationStatusValues()),
                'failure_class' => $this->boundedValue((string) $row->failure_class, $this->failureClassValuesWithNone()),
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function runtimeBindingRetiredMetrics(): array
    {
        if (! Schema::hasTable('control_plane_outbox_messages')) {
            return [];
        }

        $counts = [];
        $rows = DB::table('control_plane_outbox_messages')
            ->where('event_type', 'conference.runtime_binding_retired')
            ->orderBy('created_at')
            ->get(['payload']);
        foreach ($rows as $row) {
            $payload = $this->decodeJsonObject($row->payload);
            $reason = $this->boundedValue((string) ($payload['retirement_reason'] ?? $payload['reason'] ?? 'other'), ['conference_closed']);
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        return $this->samplesFromCounts('utcp_conference_runtime_binding_retired_total', 'reason', $counts);
    }

    /**
     * @return list<string>
     */
    private function staleActiveBindingMetrics(): array
    {
        if (! $this->hasTables(['conferences', 'conference_runtime_bindings'])) {
            return [$this->sample('utcp_conference_stale_active_bindings', [], 0)];
        }

        $count = DB::table('conferences')
            ->join('conference_runtime_bindings', function ($join): void {
                $join->on('conference_runtime_bindings.conference_id', '=', 'conferences.id')
                    ->on('conference_runtime_bindings.tenant_id', '=', 'conferences.tenant_id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->where('conferences.desired_state', 'closed')
            ->where('conferences.observed_state', 'closed')
            ->count();

        return [$this->sample('utcp_conference_stale_active_bindings', [], $count)];
    }

    /**
     * @return list<string>
     */
    private function participantChannelReclaimedMetrics(): array
    {
        if (! Schema::hasTable('control_plane_outbox_messages')) {
            return [];
        }

        $counts = [];
        $rows = DB::table('control_plane_outbox_messages')
            ->where('event_type', 'conference_participant.channel_reclaimed')
            ->orderBy('created_at')
            ->get(['payload']);
        foreach ($rows as $row) {
            $payload = $this->decodeJsonObject($row->payload);
            $classification = $this->boundedValue((string) ($payload['classification'] ?? 'other'), ['post_closure_orphan']);
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }

        return $this->samplesFromCounts('utcp_conference_participant_channel_reclaimed_total', 'classification', $counts);
    }

    /**
     * @return list<string>
     */
    private function orphanParticipantCandidateMetrics(): array
    {
        if (! $this->hasTables(['conference_participants', 'conferences', 'conference_runtime_bindings'])) {
            return [$this->sample('utcp_conference_orphan_participant_candidates', [], 0)];
        }

        $count = DB::table('conference_participants')
            ->join('conferences', function ($join): void {
                $join->on('conferences.id', '=', 'conference_participants.conference_id')
                    ->on('conferences.tenant_id', '=', 'conference_participants.tenant_id');
            })
            ->where('conference_participants.desired_state', 'removed')
            ->where('conferences.desired_state', 'closed')
            ->where('conferences.observed_state', 'closed')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conference_runtime_bindings')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->whereColumn('conference_runtime_bindings.conference_id', 'conferences.id')
                    ->where('conference_runtime_bindings.status', 'active');
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('conference_runtime_bindings')
                    ->whereColumn('conference_runtime_bindings.tenant_id', 'conferences.tenant_id')
                    ->whereColumn('conference_runtime_bindings.conference_id', 'conferences.id')
                    ->where('conference_runtime_bindings.status', 'retired')
                    ->whereNotNull('conference_runtime_bindings.runtime_node_id');
            })
            ->count();

        return [$this->sample('utcp_conference_orphan_participant_candidates', [], $count)];
    }

    /**
     * @return list<string>
     */
    private function runtimeReferenceHealthMetrics(): array
    {
        if (! Schema::hasTable('conference_recovery_metric_events')) {
            return [];
        }

        $counts = [];
        $rows = DB::table('conference_recovery_metric_events')
            ->selectRaw('resource_type, reason, count(*) as count')
            ->groupBy('resource_type', 'reason')
            ->orderBy('resource_type')
            ->orderBy('reason')
            ->get();

        foreach ($rows as $row) {
            $resourceType = $this->boundedValue((string) $row->resource_type, $this->runtimeReferenceResourceTypes());
            $health = $this->boundedValue((string) $row->reason, $this->runtimeReferenceHealthValues());
            $key = $resourceType."\0".$health;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->count;
        }

        ksort($counts);

        return collect($counts)
            ->map(function (int $count, string $key): string {
                [$resourceType, $health] = explode("\0", $key, 2);

                return $this->sample('utcp_conference_runtime_reference_health_total', [
                    'resource_type' => $resourceType,
                    'health' => $health,
                ], $count);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function orphanReclamationOperationMetrics(): array
    {
        if (! Schema::hasTable('runtime_operations')) {
            return [];
        }

        $counts = [];
        $rows = DB::table('runtime_operations')
            ->where('operation_type', (string) config('telephony_domain.operation_types.participant_remove', 'conference.participant.remove'))
            ->where('payload->orphan_reclamation', true)
            ->selectRaw("status as result, coalesce(last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('status', 'last_failure_class')
            ->orderBy('result')
            ->orderBy('failure_class')
            ->get();

        foreach ($rows as $row) {
            $result = $this->boundedValue((string) $row->result, $this->operationStatusValues());
            $failureClass = $this->boundedValue((string) $row->failure_class, $this->failureClassValuesWithNone());
            $key = $result."\0".$failureClass;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->count;
        }

        ksort($counts);

        return collect($counts)
            ->map(function (int $count, string $key): string {
                [$result, $failureClass] = explode("\0", $key, 2);

                return $this->sample('utcp_conference_orphan_reclamation_operations_total', [
                    'result' => $result,
                    'failure_class' => $failureClass,
                ], $count);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function buildInfoMetrics(): array
    {
        $build = $this->build->toArray();

        return [$this->sample('utcp_build_info', [
            'version' => $build['version'],
            'commit' => $build['commit'],
        ], 1)];
    }

    /**
     * @return list<string>
     */
    private function asteriskAriNodeMetrics(): array
    {
        if (! Schema::hasTable('runtime_nodes')) {
            return [$this->sample('asterisk_ari_nodes', ['desired_state' => 'none', 'observed_state' => 'none'], 0)];
        }

        return DB::table('runtime_nodes')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->selectRaw('desired_state, observed_state, count(*) as count')
            ->groupBy('desired_state', 'observed_state')
            ->orderBy('desired_state')
            ->orderBy('observed_state')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_nodes', [
                'desired_state' => (string) $row->desired_state,
                'observed_state' => (string) $row->observed_state,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriHttpRequestMetrics(): array
    {
        if (! $this->hasTables(['runtime_operations', 'runtime_nodes'])) {
            return [$this->sample('asterisk_ari_http_requests_total', ['method_group' => 'info', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        $nodeIds = DB::table('runtime_nodes')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if ($nodeIds === []) {
            return [$this->sample('asterisk_ari_http_requests_total', ['method_group' => 'info', 'result' => 'none', 'failure_class' => 'none'], 0)];
        }

        return DB::table('runtime_operations')
            ->whereIn('runtime_operations.runtime_node_id', $nodeIds)
            ->where('runtime_operations.operation_type', 'runtime.node.inspect')
            ->selectRaw("runtime_operations.status as result, coalesce(runtime_operations.last_failure_class, 'none') as failure_class, count(*) as count")
            ->groupBy('runtime_operations.status', 'runtime_operations.last_failure_class')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_http_requests_total', [
                'method_group' => 'info',
                'result' => (string) $row->result,
                'failure_class' => (string) $row->failure_class,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriWebsocketMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_connection_epochs')) {
            return [$this->sample('asterisk_ari_websocket_connections', ['connection_result' => 'none'], 0)];
        }

        return DB::table('runtime_event_connection_epochs')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->selectRaw('status as connection_result, count(*) as count')
            ->groupBy('status')
            ->orderBy('connection_result')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_websocket_connections', [
                'connection_result' => (string) $row->connection_result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriEventReceiptMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_receipts')) {
            return [$this->sample('asterisk_ari_events_received_total', ['event_type' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('runtime_event_receipts')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->selectRaw('event_type, status as result, count(*) as count')
            ->groupBy('event_type', 'status')
            ->orderBy('event_type')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_events_received_total', [
                'event_type' => $this->boundedAsteriskEventType((string) $row->event_type),
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriReconnectMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_connection_epochs')) {
            return [$this->sample('asterisk_ari_reconnects_total', ['connection_result' => 'none'], 0)];
        }

        return DB::table('runtime_event_connection_epochs')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->selectRaw('status as connection_result, count(*) as count')
            ->groupBy('status')
            ->orderBy('connection_result')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_reconnects_total', [
                'connection_result' => (string) $row->connection_result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriAuthenticationFailureMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_receipts')) {
            return [$this->sample('asterisk_ari_authentication_failures_total', ['failure_class' => 'authentication_failed'], 0)];
        }

        return [$this->sample('asterisk_ari_authentication_failures_total', [
            'failure_class' => 'authentication_failed',
        ], DB::table('runtime_event_receipts')
            ->where('adapter_key', config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->where('event_type', config('asterisk_ari.event_types.authentication_failed', 'asterisk.ari.authentication_failed'))
            ->count())];
    }

    /**
     * @return list<string>
     */
    private function asteriskAriListenerClaimMetrics(): array
    {
        if (! Schema::hasTable('runtime_listener_leases')) {
            return [$this->sample('asterisk_ari_listener_claims_total', ['result' => 'none'], 0)];
        }

        return DB::table('runtime_listener_leases')
            ->where('listener_kind', config('asterisk_ari.listener_kind', 'asterisk-ari-events'))
            ->selectRaw('status as result, count(*) as count')
            ->groupBy('status')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('asterisk_ari_listener_claims_total', [
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function asteriskAriListenerNodeMetrics(): array
    {
        if (! Schema::hasTable('runtime_listener_leases')) {
            return [$this->sample('asterisk_ari_listener_nodes', ['result' => 'claimed'], 0)];
        }

        return [$this->sample('asterisk_ari_listener_nodes', ['result' => 'claimed'], DB::table('runtime_listener_leases')
            ->where('listener_kind', config('asterisk_ari.listener_kind', 'asterisk-ari-events'))
            ->where('status', 'claimed')
            ->where('lease_expires_at', '>', now())
            ->count())];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationPollMetrics(): array
    {
        if (! Schema::hasTable('kamailio_registration_poll_health')) {
            return [$this->sample('kamailio_registration_snapshot_polls_total', ['result' => 'success'], 0)];
        }

        $health = (new KamailioRegistrationPollHealthRepository)->current();
        if ($health === null) {
            return [$this->sample('kamailio_registration_snapshot_polls_total', ['result' => 'success'], 0)];
        }

        return [
            $this->sample('kamailio_registration_snapshot_polls_total', ['result' => 'success'], $health['poll_success_count']),
            $this->sample('kamailio_registration_snapshot_polls_total', ['result' => 'failure'], $health['poll_failure_count']),
        ];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationPollFailureMetrics(): array
    {
        if (! Schema::hasTable('kamailio_registration_poll_health')) {
            return [$this->sample('kamailio_registration_snapshot_poll_failures_total', [], 0)];
        }

        $health = (new KamailioRegistrationPollHealthRepository)->current();

        return [$this->sample('kamailio_registration_snapshot_poll_failures_total', [], $health['poll_failure_count'] ?? 0)];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationObserverClaimMetrics(): array
    {
        if (! Schema::hasTable('runtime_listener_leases')) {
            return [$this->sample('kamailio_registration_observer_claims_total', ['result' => 'none'], 0)];
        }

        return DB::table('runtime_listener_leases')
            ->where('listener_kind', KamailioRegistrationObserver::LISTENER_KIND)
            ->selectRaw('status as result, count(*) as count')
            ->groupBy('status')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('kamailio_registration_observer_claims_total', [
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationObserverActiveMetrics(): array
    {
        if (! Schema::hasTable('runtime_listener_leases')) {
            return [$this->sample('kamailio_registration_observer_active', [], 0)];
        }

        $active = DB::table('runtime_listener_leases')
            ->where('listener_kind', KamailioRegistrationObserver::LISTENER_KIND)
            ->where('status', 'claimed')
            ->where('lease_expires_at', '>', now())
            ->count();

        return [$this->sample('kamailio_registration_observer_active', [], $active > 0 ? 1 : 0)];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationObserverLagMetrics(): array
    {
        if (! Schema::hasTable('runtime_projection_checkpoints')) {
            return [$this->sample('kamailio_registration_observer_lag_seconds', [], 0)];
        }

        $checkpoint = DB::table('runtime_projection_checkpoints')
            ->where('projector', KamailioRegistrationObserver::PROJECTOR)
            ->where('partition_key', EventSourceRepository::KAMAILIO_REGISTRATION_KEY)
            ->orderByDesc('last_observed_at')
            ->first();

        if ($checkpoint === null || $checkpoint->last_observed_at === null) {
            return [$this->sample('kamailio_registration_observer_lag_seconds', [], 0)];
        }

        $lag = max(0, (int) now()->diffInSeconds(Carbon::parse((string) $checkpoint->last_observed_at), true));

        return [$this->sample('kamailio_registration_observer_lag_seconds', [], $lag)];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationReceiptMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_receipts')) {
            return [$this->sample('kamailio_registration_receipts_total', ['event_type' => 'none', 'result' => 'none'], 0)];
        }

        return DB::table('runtime_event_receipts')
            ->where('adapter_key', KamailioRegistrationObserver::ADAPTER_KEY)
            ->selectRaw('event_type, status as result, count(*) as count')
            ->groupBy('event_type', 'status')
            ->orderBy('event_type')
            ->orderBy('result')
            ->get()
            ->map(fn (object $row): string => $this->sample('kamailio_registration_receipts_total', [
                'event_type' => (string) $row->event_type,
                'result' => (string) $row->result,
            ], (int) $row->count))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationProjectionBacklogMetrics(): array
    {
        if (! Schema::hasTable('runtime_event_receipts')) {
            return [$this->sample('kamailio_registration_projection_backlog', [], 0)];
        }

        $backlog = DB::table('runtime_event_receipts')
            ->where('adapter_key', KamailioRegistrationObserver::ADAPTER_KEY)
            ->whereIn('status', ['pending', 'leased', 'retry_scheduled'])
            ->count();

        return [$this->sample('kamailio_registration_projection_backlog', [], $backlog)];
    }

    /**
     * @return list<string>
     */
    private function kamailioRegistrationContactMetrics(): array
    {
        if (! Schema::hasTable('location')) {
            return [$this->sample('kamailio_registration_contacts', [], 0)];
        }

        $contacts = DB::table('location')->where('expires', '>', now())->count();

        return [$this->sample('kamailio_registration_contacts', [], $contacts)];
    }

    /**
     * @param  list<string>  $tables
     */
    private function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function simulatorNodeScenarios(): array
    {
        return DB::table('runtime_nodes')
            ->leftJoin('simulator_profiles', 'simulator_profiles.runtime_node_id', '=', 'runtime_nodes.id')
            ->where('runtime_nodes.adapter_key', config('simulator.adapter_key', 'simulator-deterministic'))
            ->pluck('simulator_profiles.scenario_key', 'runtime_nodes.id')
            ->mapWithKeys(fn (?string $scenario, string $nodeId): array => [(string) $nodeId => $scenario ?? 'unconfigured'])
            ->all();
    }

    private function boundedAsteriskEventType(string $eventType): string
    {
        return match ($eventType) {
            config('asterisk_ari.event_types.connection_opened', 'asterisk.ari.connection.opened') => 'connection_opened',
            config('asterisk_ari.event_types.connection_closed', 'asterisk.ari.connection.closed') => 'connection_closed',
            config('asterisk_ari.event_types.runtime_info_observed', 'asterisk.ari.runtime.info_observed') => 'runtime_info_observed',
            config('asterisk_ari.event_types.authentication_failed', 'asterisk.ari.authentication_failed') => 'authentication_failed',
            default => 'unknown',
        };
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryOperationTypes(): array
    {
        return [
            (string) config('telephony_domain.operation_types.conference_ensure', 'conference.ensure'),
            (string) config('telephony_domain.operation_types.conference_close', 'conference.close'),
            (string) config('telephony_domain.operation_types.participant_ensure', 'conference.participant.ensure'),
            (string) config('telephony_domain.operation_types.participant_remove', 'conference.participant.remove'),
        ];
    }

    /**
     * @return list<string>
     */
    private function conferenceRecoveryEventTypes(): array
    {
        return [
            (string) config('asterisk_ari.event_types.bridge_created', 'asterisk.ari.bridge.created'),
            (string) config('asterisk_ari.event_types.bridge_destroyed', 'asterisk.ari.bridge.destroyed'),
            (string) config('asterisk_ari.event_types.channel_entered_bridge', 'asterisk.ari.channel.entered_bridge'),
            (string) config('asterisk_ari.event_types.channel_left_bridge', 'asterisk.ari.channel.left_bridge'),
            (string) config('asterisk_ari.event_types.channel_destroyed', 'asterisk.ari.channel.destroyed'),
            (string) config('asterisk_ari.event_types.stasis_start', 'asterisk.ari.stasis_start'),
            (string) config('asterisk_ari.event_types.stasis_end', 'asterisk.ari.stasis_end'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function failoverEventTypeLabels(): array
    {
        return [
            'conference.failover_coordinator.no_replacement' => 'no_replacement',
            'conference.runtime_binding_replaced' => 'runtime_binding_replaced',
        ];
    }

    /**
     * @return list<string>
     */
    private function runtimeResilienceOperationTypes(): array
    {
        return [
            (string) config('telephony_domain.operation_types.verify_conference_absent', 'runtime.node.verify_conference_absent'),
            (string) config('telephony_domain.operation_types.runtime_fence', 'runtime.node.runtime.fence'),
            (string) config('telephony_domain.operation_types.runtime_node_restore', 'runtime.node.restore'),
            (string) config('telephony_domain.operation_types.participant_remove', 'conference.participant.remove'),
        ];
    }

    /**
     * @return list<string>
     */
    private function runtimeReferenceResourceTypes(): array
    {
        return ['conference', 'conference_participant'];
    }

    /**
     * @return list<string>
     */
    private function runtimeReferenceHealthValues(): array
    {
        return ['healthy_present', 'healthy_absent', 'degraded_unavailable', 'transport_unavailable'];
    }

    /**
     * @return list<string>
     */
    private function operationStatusValues(): array
    {
        return array_map(static fn (OperationStatus $status): string => $status->value, OperationStatus::cases());
    }

    /**
     * @return list<string>
     */
    private function failureClassValuesWithNone(): array
    {
        return [
            'none',
            ...array_map(static fn (FailureClass $failureClass): string => $failureClass->value, FailureClass::cases()),
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function boundedValue(string $value, array $allowed, string $fallback = 'other'): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function samplesFromCounts(string $metric, string $label, array $counts): array
    {
        ksort($counts);

        return collect($counts)
            ->map(fn (int $count, string $value): string => $this->sample($metric, [$label => $value], $count))
            ->values()
            ->all();
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

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels, int $value): string
    {
        $pairs = [];
        foreach ($labels as $key => $label) {
            $safeLabel = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $label) ?? 'unknown';
            $safeLabel = addcslashes($safeLabel, "\\\"\n");
            $pairs[] = $key.'="'.$safeLabel.'"';
        }

        return $name.'{'.implode(',', $pairs).'} '.$value;
    }
}
