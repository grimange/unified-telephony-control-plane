<?php

namespace Tests\Feature\Asterisk;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventListener;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskAriException;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionAdapter;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionResult;
use App\RuntimeEngine\Commands\RuntimeConferenceInspectionService;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\TelephonyDomain\Reconciliation\ConferenceParticipantReconciler;
use App\TelephonyDomain\Reconciliation\ConferenceReconciler;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class AsteriskConferenceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_conference_reconciler_repairs_missing_bridge_after_authoritative_inspection(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready');
        $reconciler = new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.ensure', $result->operationType);
        $this->assertSame('conference_bridge_missing', $result->reasonCode);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_participant_reconciler_repairs_missing_channel_after_authoritative_inspection(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(true, false, false)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.participant.ensure', $result->operationType);
        $this->assertSame('conference_participant_channel_missing', $result->reasonCode);
        $this->assertSame($conferenceId, $result->operationPayload['conference_id']);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_admitted_participant_waits_without_operation_when_parent_conference_is_closed(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [, $participantId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', participantDesiredState: 'admitted', observedConferenceState: 'closed', observedParticipantState: 'left');
        $inspectionCalls = (object) ['count' => 0];
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceCounting($inspectionCalls));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $result->status);
        $this->assertSame('conference_not_open_for_participant_ensure', $result->reasonCode);
        $this->assertNull($result->operationType);
        $this->assertSame(0, $inspectionCalls->count);

        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'conference_participant', $participantId, 2);
        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([$reconciler]),
            new RuntimeOperationRepository,
        );

        $this->assertSame(1, $worker->workOnce('participant-closed-parent', batchSize: 1));

        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('waiting', $state->status);
        $this->assertSame(0, $inspectionCalls->count);
        $this->assertDatabaseCount('runtime_operations', 0);
    }

    public function test_admitted_participant_waits_without_operation_when_parent_conference_is_draining(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [, $participantId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'draining', participantDesiredState: 'admitted', observedConferenceState: 'ready', observedParticipantState: 'joined');
        $inspectionCalls = (object) ['count' => 0];
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceCounting($inspectionCalls));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $result->status);
        $this->assertSame('conference_not_open_for_participant_ensure', $result->reasonCode);
        $this->assertNull($result->operationType);
        $this->assertSame(0, $inspectionCalls->count);

        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'conference_participant', $participantId, 2);
        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([$reconciler]),
            new RuntimeOperationRepository,
        );

        $this->assertSame(1, $worker->workOnce('participant-draining-parent', batchSize: 1));

        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('waiting', $state->status);
        $this->assertSame(0, $inspectionCalls->count);
        $this->assertDatabaseCount('runtime_operations', 0);
    }

    public function test_closed_conference_with_runtime_bridge_present_requires_close_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', observedConferenceState: 'ready');
        $reconciler = new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(true)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.close', $result->operationType);
        $this->assertSame('conference_runtime_drift', $result->reasonCode);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_closed_conference_with_projected_closed_state_still_inspects_runtime_bridge(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', observedConferenceState: 'closed');
        $reconciler = new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(true)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.close', $result->operationType);
        $this->assertSame('conference_runtime_drift', $result->reasonCode);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_closed_conference_with_runtime_bridge_absent_records_absence_without_close_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', observedConferenceState: 'ready');
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $reconciler = new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false),
            $recorded,
        ));

        $first = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);
        $second = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $first->status);
        $this->assertSame('conference_runtime_absence_recorded', $first->reasonCode);
        $this->assertNull($first->operationType);
        $this->assertSame('waiting', $second->status);
        $this->assertNull($second->operationType);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => null],
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => null],
        ], $recorded->items);
        $this->assertSame('ready', DB::table('conferences')->where('id', $conferenceId)->value('observed_state'));
    }

    public function test_closed_conference_with_projected_closed_state_records_absence_and_converges(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', observedConferenceState: 'closed');
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $reconciler = new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false),
            $recorded,
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('converged', $result->status);
        $this->assertNull($result->operationType);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => null],
        ], $recorded->items);
    }

    public function test_closed_conference_waits_when_runtime_inspection_unavailable_or_failed(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', observedConferenceState: 'ready');
        $unavailable = (new ConferenceReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::unavailable(FailureClass::RuntimeUnavailable->value, 'runtime_unavailable')
        )))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $throwingService = new RuntimeConferenceInspectionService(new RuntimeAdapterRegistry([
            new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->clientThrowing(FailureClass::RuntimeUnavailable)),
        ]));
        $failed = (new ConferenceReconciler($throwingService))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $unavailable->status);
        $this->assertSame('conference_runtime_inspection_unavailable', $unavailable->reasonCode);
        $this->assertNull($unavailable->operationType);
        $this->assertSame('waiting', $failed->status);
        $this->assertSame('conference_runtime_inspection_unavailable', $failed->reasonCode);
        $this->assertNull($failed->operationType);
        $this->assertSame('ready', DB::table('conferences')->where('id', $conferenceId)->value('observed_state'));
    }

    public function test_removed_participant_with_runtime_channel_present_requires_remove_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'ready', observedParticipantState: 'joined');
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(true, true, true)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.participant.remove', $result->operationType);
        $this->assertSame('conference_participant_runtime_drift', $result->reasonCode);
        $this->assertSame($conferenceId, $result->operationPayload['conference_id']);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_removed_participant_with_projected_left_state_still_inspects_runtime_channel(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'closed', observedParticipantState: 'left');
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false, true, false)
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.participant.remove', $result->operationType);
        $this->assertSame('conference_participant_runtime_drift', $result->reasonCode);
        $this->assertSame($conferenceId, $result->operationPayload['conference_id']);
        $this->assertSame($nodeId, $result->runtimeNodeId);
    }

    public function test_removed_participant_already_absent_records_absence_without_remove_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'ready', observedParticipantState: 'joined');
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(true, false, false),
            $recorded,
        ));

        $first = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);
        $second = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $first->status);
        $this->assertSame('conference_participant_runtime_absence_recorded', $first->reasonCode);
        $this->assertNull($first->operationType);
        $this->assertSame('waiting', $second->status);
        $this->assertNull($second->operationType);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => $participantId],
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => $participantId],
        ], $recorded->items);
        $this->assertSame('joined', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
    }

    public function test_removed_participant_with_projected_left_state_records_absence_and_converges(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'closed', observedParticipantState: 'left');
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $reconciler = new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false, false, false),
            $recorded,
        ));

        $result = $reconciler->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('converged', $result->status);
        $this->assertNull($result->operationType);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => $participantId],
        ], $recorded->items);
    }

    public function test_removed_participant_waits_when_runtime_inspection_unavailable_or_failed(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'ready', observedParticipantState: 'joined');
        $unavailable = (new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::unavailable(FailureClass::RuntimeUnavailable->value, 'runtime_unavailable')
        )))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $throwingService = new RuntimeConferenceInspectionService(new RuntimeAdapterRegistry([
            new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->clientThrowing(FailureClass::RuntimeUnavailable)),
        ]));
        $failed = (new ConferenceParticipantReconciler($throwingService))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $unavailable->status);
        $this->assertSame('conference_participant_runtime_inspection_unavailable', $unavailable->reasonCode);
        $this->assertNull($unavailable->operationType);
        $this->assertSame('waiting', $failed->status);
        $this->assertSame('conference_participant_runtime_inspection_unavailable', $failed->reasonCode);
        $this->assertNull($failed->operationType);
        $this->assertSame('joined', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
    }

    public function test_removed_participant_with_projected_left_state_waits_when_runtime_inspection_unavailable_or_failed(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'closed', observedParticipantState: 'left');
        $unavailable = (new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::unavailable(FailureClass::RuntimeUnavailable->value, 'runtime_unavailable')
        )))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $failed = (new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::failed(FailureClass::RuntimeUnavailable->value, 'runtime_unavailable')
        )))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $unavailable->status);
        $this->assertSame('conference_participant_runtime_inspection_unavailable', $unavailable->reasonCode);
        $this->assertNull($unavailable->operationType);
        $this->assertSame('waiting', $failed->status);
        $this->assertSame('conference_participant_runtime_inspection_unavailable', $failed->reasonCode);
        $this->assertNull($failed->operationType);
        $this->assertSame('left', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
    }

    public function test_close_before_remove_projected_left_does_not_bypass_participant_runtime_cleanup(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        DB::table('conferences')->where('id', $conferenceId)->update(['desired_state' => 'closed', 'observed_state' => 'closed', 'updated_at' => now()]);
        $participant = DB::table('conference_participants')->where('id', $participantId)->first();
        $this->assertNotNull($participant);

        $catalog = new AsteriskCatalog;
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'listener-close-before-remove');
        $ingested = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'close-before-remove:left:'.$participantId, $catalog->eventType('channel_left_bridge'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 1,
            'occurred_at' => now()->toISOString(),
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $ingested['id'])->first();

        (new ProjectionService)->apply($receipt, [[
            'tenant_id' => $tenantId,
            'observation_type' => 'conference_participant.membership',
            'observation_version' => 1,
            'subject_type' => 'conference_participant',
            'subject_id' => $participantId,
            'observed_state' => 'left',
            'configuration_version' => 1,
            'observed_at' => now(),
            'payload' => [
                'conference_id' => $conferenceId,
                'telephony_session_id' => (string) $participant->telephony_session_id,
            ],
        ]]);
        DB::table('conference_participants')->where('id', $participantId)->update(['desired_state' => 'removed', 'updated_at' => now()]);

        $result = (new ConferenceParticipantReconciler($this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::observed(false, true, false)
        )))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('left', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
        $this->assertSame('operation_required', $result->status);
        $this->assertSame('conference.participant.remove', $result->operationType);
        $this->assertSame('conference_participant_runtime_drift', $result->reasonCode);
    }

    public function test_pending_participant_remove_operation_prevents_duplicate_cleanup_operations(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, participantDesiredState: 'removed', observedConferenceState: 'closed', observedParticipantState: 'left');
        $repository = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'conference_participant', $participantId, 3);
        $operationId = $operations->create(
            'conference.participant.remove',
            'conference_participant',
            $participantId,
            [
                'conference_id' => $conferenceId,
                'participant_id' => $participantId,
                'telephony_session_id' => (string) DB::table('conference_participants')->where('id', $participantId)->value('telephony_session_id'),
                'runtime_node_id' => $nodeId,
                'configuration_generation' => 1,
            ],
            ExecutionContext::system(tenantId: $tenantId),
            runtimeNodeId: $nodeId,
        );
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'last_operation_id' => $operationId,
            'next_check_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);

        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new ConferenceParticipantReconciler($this->inspectionServiceReturning(
                RuntimeConferenceInspectionResult::observed(false, true, false)
            ))]),
            $operations,
        );

        $this->assertSame(1, $worker->workOnce('reconciler-participant-pending', batchSize: 1));
        $this->assertDatabaseCount('runtime_operations', 1);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'status' => 'waiting',
            'last_operation_id' => $operationId,
        ]);
    }

    public function test_runtime_unavailability_waits_without_projecting_false_absence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        $inspections = $this->inspectionServiceReturning(
            RuntimeConferenceInspectionResult::unavailable(FailureClass::RuntimeUnavailable->value, 'runtime_unavailable')
        );

        $conference = (new ConferenceReconciler($inspections))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);
        $participant = (new ConferenceParticipantReconciler($inspections))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $conference->status);
        $this->assertSame('conference_runtime_inspection_unavailable', $conference->reasonCode);
        $this->assertSame('waiting', $participant->status);
        $this->assertSame('conference_participant_runtime_inspection_unavailable', $participant->reasonCode);
        $this->assertSame('ready', DB::table('conferences')->where('id', $conferenceId)->value('observed_state'));
        $this->assertSame('joined', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
    }

    public function test_conference_reconciler_records_adapter_neutral_evidence_when_runtime_bridge_exists_but_projection_is_stale(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId);
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $service = $this->inspectionServiceReturning(RuntimeConferenceInspectionResult::observed(true), $recorded);

        $result = (new ConferenceReconciler($service))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $result->status);
        $this->assertSame('conference_runtime_evidence_recorded', $result->reasonCode);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => null],
        ], $recorded->items);
    }

    public function test_participant_reconciler_records_adapter_neutral_evidence_when_runtime_channel_exists_but_projection_is_stale(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready');
        $recorded = new class
        {
            /** @var list<array{tenant_id:string,runtime_node_id:string,conference_id:string,participant_id:?string}> */
            public array $items = [];
        };
        $service = $this->inspectionServiceReturning(RuntimeConferenceInspectionResult::observed(true, true, true), $recorded);

        $result = (new ConferenceParticipantReconciler($service))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $result->status);
        $this->assertSame('conference_participant_runtime_evidence_recorded', $result->reasonCode);
        $this->assertSame([
            ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'conference_id' => $conferenceId, 'participant_id' => $participantId],
        ], $recorded->items);
    }

    public function test_generic_reconcilers_use_adapter_neutral_inspection_dispatch(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        $service = $this->inspectionServiceReturning(RuntimeConferenceInspectionResult::observed(true, true, true));

        $conference = (new ConferenceReconciler($service))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);
        $participant = (new ConferenceParticipantReconciler($service))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('converged', $conference->status);
        $this->assertSame(30, $conference->nextCheckSeconds);
        $this->assertSame('converged', $participant->status);
        $this->assertSame(30, $participant->nextCheckSeconds);
    }

    public function test_reconcilers_converge_when_runtime_absence_is_verified_after_completed_terminal_operation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture(
            $tenantId,
            $nodeId,
            conferenceDesiredState: 'closed',
            participantDesiredState: 'removed',
            observedConferenceState: 'closed',
            observedParticipantState: 'left',
        );
        $conferenceOperationId = EngineIds::new();
        $participantOperationId = EngineIds::new();
        DB::table('runtime_operations')->insert([
            [
                'id' => $conferenceOperationId,
                'tenant_id' => $tenantId,
                'operation_type' => 'conference.close',
                'aggregate_type' => 'conference',
                'aggregate_id' => $conferenceId,
                'runtime_node_id' => $nodeId,
                'payload_version' => 1,
                'payload' => json_encode(['conference_id' => $conferenceId]),
                'status' => 'succeeded',
                'priority' => 100,
                'idempotency_key' => 'terminal-conference-close',
                'correlation_id' => str_repeat('a', 32),
                'request_id' => str_repeat('b', 32),
                'available_at' => now()->subMinute(),
                'last_failure_class' => null,
                'last_failure_code' => null,
                'completed_at' => now()->subSecond(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subSecond(),
            ],
            [
                'id' => $participantOperationId,
                'tenant_id' => $tenantId,
                'operation_type' => 'conference.participant.remove',
                'aggregate_type' => 'conference_participant',
                'aggregate_id' => $participantId,
                'runtime_node_id' => $nodeId,
                'payload_version' => 1,
                'payload' => json_encode(['conference_id' => $conferenceId, 'participant_id' => $participantId]),
                'status' => 'succeeded',
                'priority' => 100,
                'idempotency_key' => 'terminal-participant-remove',
                'correlation_id' => str_repeat('c', 32),
                'request_id' => str_repeat('d', 32),
                'available_at' => now()->subMinute(),
                'last_failure_class' => null,
                'last_failure_code' => null,
                'completed_at' => now()->subSecond(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subSecond(),
            ],
        ]);

        $conference = (new ConferenceReconciler($this->inspectionServiceReturning(RuntimeConferenceInspectionResult::observed(false))))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => $conferenceOperationId,
        ]);
        $participant = (new ConferenceParticipantReconciler($this->inspectionServiceReturning(RuntimeConferenceInspectionResult::observed(false, false, false))))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $participantId,
            'last_operation_id' => $participantOperationId,
        ]);

        $this->assertSame('converged', $conference->status);
        $this->assertSame('converged', $participant->status);
    }

    public function test_adapter_without_conference_inspection_waits_safely(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready');
        $service = new RuntimeConferenceInspectionService(new RuntimeAdapterRegistry([
            new class implements RuntimeAdapter
            {
                public function adapterKey(): string
                {
                    return 'asterisk-ari';
                }

                public function execute(array $operation): array
                {
                    return ['status' => 'unsupported'];
                }
            },
        ]));

        $result = (new ConferenceReconciler($service))->evaluate((object) [
            'tenant_id' => $tenantId,
            'target_id' => $conferenceId,
            'last_operation_id' => null,
        ]);

        $this->assertSame('waiting', $result->status);
        $this->assertSame('conference_runtime_inspection_unsupported', $result->reasonCode);
    }

    public function test_generic_recovery_sources_do_not_reference_asterisk_implementations(): void
    {
        $genericFiles = [
            app_path('RuntimeEngine/Projection/ProjectionService.php'),
            app_path('TelephonyDomain/Reconciliation/ConferenceReconciler.php'),
            app_path('TelephonyDomain/Reconciliation/ConferenceParticipantReconciler.php'),
        ];

        foreach ($genericFiles as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('AsteriskAriClient', $source);
            $this->assertStringNotContainsString('AsteriskAriException', $source);
            $this->assertStringNotContainsString('inspectAsterisk', $source);
            $this->assertStringNotContainsString("'asterisk-ari'", $source);
            $this->assertStringNotContainsString('"asterisk-ari"', $source);
        }
    }

    public function test_runtime_observation_reopens_converged_asterisk_conference_reconciliation_targets(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        $conferenceStateId = EngineIds::new();
        $participantStateId = EngineIds::new();
        DB::table('runtime_reconciliation_states')->insert([
            [
                'id' => $conferenceStateId,
                'tenant_id' => $tenantId,
                'target_type' => 'conference',
                'target_id' => $conferenceId,
                'desired_generation' => 1,
                'observed_generation' => 1,
                'status' => 'converged',
                'next_check_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $participantStateId,
                'tenant_id' => $tenantId,
                'target_type' => 'conference_participant',
                'target_id' => $participantId,
                'desired_generation' => 1,
                'observed_generation' => 1,
                'status' => 'converged',
                'next_check_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $catalog = new AsteriskCatalog;
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'listener-a');
        $ingested = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'runtime-info:test', $catalog->eventType('runtime_info_observed'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 1,
            'occurred_at' => now()->toISOString(),
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $ingested['id'])->first();
        $observations = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('runtime_info_observed')))->normalize($receipt, [
            'configuration_generation' => 1,
            'occurred_at' => now()->toISOString(),
        ]);

        (new ProjectionService)->apply($receipt, $observations);

        $this->assertSame('waiting', DB::table('runtime_reconciliation_states')->where('id', $conferenceStateId)->value('status'));
        $this->assertSame('waiting', DB::table('runtime_reconciliation_states')->where('id', $participantStateId)->value('status'));
    }

    public function test_runtime_observation_does_not_reopen_terminal_conference_reconciliation_targets(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture(
            $tenantId,
            $nodeId,
            conferenceDesiredState: 'closed',
            participantDesiredState: 'removed',
            observedConferenceState: 'closed',
            observedParticipantState: 'left',
        );
        $conferenceStateId = EngineIds::new();
        $participantStateId = EngineIds::new();
        DB::table('runtime_reconciliation_states')->insert([
            [
                'id' => $conferenceStateId,
                'tenant_id' => $tenantId,
                'target_type' => 'conference',
                'target_id' => $conferenceId,
                'desired_generation' => 1,
                'observed_generation' => 1,
                'status' => 'converged',
                'next_check_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $participantStateId,
                'tenant_id' => $tenantId,
                'target_type' => 'conference_participant',
                'target_id' => $participantId,
                'desired_generation' => 1,
                'observed_generation' => 1,
                'status' => 'converged',
                'next_check_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $catalog = new AsteriskCatalog;
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'listener-a');
        $ingested = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epochId, 'runtime-info:test', $catalog->eventType('runtime_info_observed'), 1, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 1,
            'occurred_at' => now()->toISOString(),
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $ingested['id'])->first();
        $observations = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('runtime_info_observed')))->normalize($receipt, [
            'configuration_generation' => 1,
            'occurred_at' => now()->toISOString(),
        ]);

        (new ProjectionService)->apply($receipt, $observations);

        $this->assertSame('converged', DB::table('runtime_reconciliation_states')->where('id', $conferenceStateId)->value('status'));
        $this->assertSame('converged', DB::table('runtime_reconciliation_states')->where('id', $participantStateId)->value('status'));
    }

    public function test_reconnect_inspection_reopens_active_participant_even_when_initial_snapshot_is_present(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, observedConferenceState: 'ready', observedParticipantState: 'joined');
        $stateId = EngineIds::new();
        DB::table('runtime_reconciliation_states')->insert([
            'id' => $stateId,
            'tenant_id' => $tenantId,
            'target_type' => 'conference_participant',
            'target_id' => $participantId,
            'desired_generation' => 2,
            'observed_generation' => 2,
            'status' => 'converged',
            'next_check_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalog = new AsteriskCatalog;
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'listener-b');
        $listener = new AsteriskAriEventListener(
            $catalog,
            $this->clientReturning([
                'bridge_exists' => true,
                'participant_channel_exists' => true,
                'participant_channel_in_bridge' => true,
            ]),
            app(AsteriskAriProfileService::class),
            new RuntimeListenerLeaseRepository,
            $receipts,
            new ReconciliationRepository,
        );

        $method = new ReflectionMethod($listener, 'ingestConferenceRuntimeInspection');
        $method->setAccessible(true);
        $method->invoke($listener, (object) ['tenant_id' => $tenantId, 'id' => $nodeId], $epochId);

        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('waiting', $state->status);
        $this->assertNull($state->last_operation_id);
        $this->assertLessThanOrEqual(now()->addSecond()->timestamp, strtotime((string) $state->next_check_at));

        $presentReceiptCount = DB::table('runtime_event_receipts')
            ->where('external_event_key', 'like', 'inspection:channel-present:'.$participantId.':%')
            ->count();
        $this->assertSame(1, $presentReceiptCount);
        $this->assertSame($conferenceId, DB::table('conference_participants')->where('id', $participantId)->value('conference_id'));
    }

    public function test_stale_conference_and_participant_operations_do_not_mutate_asterisk(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeId, conferenceDesiredState: 'closed', participantDesiredState: 'removed');
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $this->clientReturning([]));

        $conference = $adapter->execute([
            'operation_type' => 'conference.ensure',
            'runtime_node_id' => $nodeId,
            'payload' => [
                'conference_id' => $conferenceId,
                'configuration_generation' => 1,
            ],
        ]);
        $participant = $adapter->execute([
            'operation_type' => 'conference.participant.ensure',
            'runtime_node_id' => $nodeId,
            'payload' => [
                'conference_id' => $conferenceId,
                'participant_id' => $participantId,
                'configuration_generation' => 1,
            ],
        ]);
        $participantRemove = $adapter->execute([
            'operation_type' => 'conference.participant.remove',
            'runtime_node_id' => $nodeId,
            'payload' => [
                'conference_id' => $conferenceId,
                'participant_id' => $participantId,
                'configuration_generation' => 0,
            ],
        ]);

        $this->assertSame('completed', $conference['status']);
        $this->assertSame('runtime_operation.asterisk_conference_stale', $conference['event_type']);
        $this->assertTrue($conference['event_payload']['stale_operation']);
        $this->assertSame('completed', $participant['status']);
        $this->assertSame('runtime_operation.asterisk_conference_participant_stale', $participant['event_type']);
        $this->assertTrue($participant['event_payload']['stale_operation']);
        $this->assertSame('completed', $participantRemove['status']);
        $this->assertSame('runtime_operation.asterisk_conference_participant_stale', $participantRemove['event_type']);
        $this->assertTrue($participantRemove['event_payload']['stale_operation']);
    }

    public function test_old_node_operation_is_stale_after_atomic_runtime_binding_replacement(): void
    {
        [$tenantId, $nodeA] = $this->runtimeNode();
        $nodeB = $this->runtimeNodeForTenant($tenantId, 'asterisk-ari-replacement');
        [$conferenceId] = $this->conferenceFixture($tenantId, $nodeA);
        $operations = new RuntimeOperationRepository;
        $operationId = $operations->create(
            'conference.ensure',
            'conference',
            $conferenceId,
            [
                'conference_id' => $conferenceId,
                'configuration_generation' => 1,
            ],
            ExecutionContext::system(tenantId: $tenantId, reason: 'old node operation fence test'),
            runtimeNodeId: $nodeA,
        );
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $rebind = app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 'old node operation fence rebind'),
            $tenantId,
            $conferenceId,
        );

        $operation = (array) DB::table('runtime_operations')->where('id', $operationId)->first();
        $operation['payload'] = json_decode((string) $operation['payload'], true, flags: JSON_THROW_ON_ERROR);
        $client = new class(new AsteriskCatalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public int $ensureConferenceBridgeCalls = 0;

            public function ensureConferenceBridge(string $tenantId, string $runtimeNodeId, string $conferenceId, int $configurationGeneration): array
            {
                $this->ensureConferenceBridgeCalls++;

                return [
                    'runtime_node_id' => $runtimeNodeId,
                    'bridge_id' => 'should-not-mutate',
                    'configuration_generation' => $configurationGeneration,
                    'already_existed' => false,
                ];
            }
        };

        $result = (new AsteriskRuntimeAdapter(new AsteriskCatalog, $client))->execute($operation);

        $this->assertSame('rebound', $rebind['status']);
        $this->assertSame($nodeA, $operation['runtime_node_id']);
        $this->assertSame($nodeB, DB::table('conference_runtime_bindings')->where('conference_id', $conferenceId)->where('status', 'active')->value('runtime_node_id'));
        $this->assertGreaterThan((int) $operation['payload']['configuration_generation'], (int) DB::table('conferences')->where('id', $conferenceId)->value('configuration_generation'));
        $this->assertSame('completed', $result['status']);
        $this->assertSame('runtime_operation.asterisk_conference_stale', $result['event_type']);
        $this->assertTrue($result['event_payload']['stale_operation']);
        $this->assertSame(0, $client->ensureConferenceBridgeCalls);
        $claim = $operations->claimAvailable('old-node-stale-completion')[0];
        $operations->markRunning($operationId, $claim->leaseToken);
        $operations->complete($operationId, $claim->leaseToken, EventEnvelope::forAggregate(
            $result['event_type'],
            1,
            'conference',
            $conferenceId,
            $result['event_payload'],
            ExecutionContext::system(tenantId: $tenantId, reason: 'old node stale completion'),
        ), new OutboxRepository);
        $this->assertSame(OperationStatus::Succeeded->value, DB::table('runtime_operations')->where('id', $operationId)->value('status'));
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conferenceId)->value('runtime_node_id'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conferenceId)->where('status', 'active')->count());
    }

    public function test_delayed_old_node_conference_and_participant_events_do_not_project_after_rebind(): void
    {
        [$tenantId, $nodeA] = $this->runtimeNode();
        $nodeB = $this->runtimeNodeForTenant($tenantId, 'asterisk-ari-event-replacement');
        [$conferenceId, $participantId] = $this->conferenceFixture($tenantId, $nodeA, observedConferenceState: 'unobserved', observedParticipantState: 'unobserved');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);
        app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 'old node event fence rebind'),
            $tenantId,
            $conferenceId,
        );

        $catalog = new AsteriskCatalog;
        $client = new AsteriskAriClient($catalog, app(AsteriskAriProfileService::class));
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeA, $catalog->adapterKey(), 'old-node-listener');
        $bridgeReceipt = $receipts->ingest($tenantId, $nodeA, $catalog->adapterKey(), $epochId, 'old-node-bridge', $catalog->eventType('bridge_created'), 1, [
            'runtime_node_id' => $nodeA,
            'configuration_generation' => 1,
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'occurred_at' => now()->toISOString(),
        ]);
        $participantReceipt = $receipts->ingest($tenantId, $nodeA, $catalog->adapterKey(), $epochId, 'old-node-participant', $catalog->eventType('channel_entered_bridge'), 1, [
            'runtime_node_id' => $nodeA,
            'configuration_generation' => 1,
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);

        $bridgeReceiptRow = DB::table('runtime_event_receipts')->where('id', $bridgeReceipt['id'])->first();
        $participantReceiptRow = DB::table('runtime_event_receipts')->where('id', $participantReceipt['id'])->first();
        $bridgeObservations = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('bridge_created')))->normalize($bridgeReceiptRow, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'occurred_at' => now()->toISOString(),
        ]);
        $participantObservations = (new AsteriskAriEventNormalizer($catalog, $catalog->eventType('channel_entered_bridge')))->normalize($participantReceiptRow, [
            'bridge_id' => $client->conferenceBridgeId($conferenceId),
            'channel_id' => $client->participantChannelId($participantId),
            'occurred_at' => now()->toISOString(),
        ]);

        (new ProjectionService)->apply($bridgeReceiptRow, $bridgeObservations);
        (new ProjectionService)->apply($participantReceiptRow, $participantObservations);

        $this->assertSame($nodeA, $bridgeReceiptRow->runtime_node_id);
        $this->assertSame($nodeB, DB::table('conference_runtime_bindings')->where('conference_id', $conferenceId)->where('status', 'active')->value('runtime_node_id'));
        $this->assertSame([], $bridgeObservations);
        $this->assertSame([], $participantObservations);
        $this->assertSame(0, DB::table('runtime_observations')->whereIn('source_event_id', [$bridgeReceipt['id'], $participantReceipt['id']])->count());
        $this->assertSame('unobserved', DB::table('conferences')->where('id', $conferenceId)->value('observed_state'));
        $this->assertSame('unobserved', DB::table('conference_participants')->where('id', $participantId)->value('observed_state'));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNode(): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'asterisk-recovery-tenant',
            'display_name' => 'Asterisk Recovery Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Asterisk ARI',
            'slug' => 'asterisk-ari',
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$tenantId, $nodeId];
    }

    private function runtimeNodeForTenant(string $tenantId, string $slug): string
    {
        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Asterisk ARI Replacement',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $nodeId;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function conferenceFixture(
        string $tenantId,
        string $nodeId,
        string $conferenceDesiredState = 'open',
        string $participantDesiredState = 'admitted',
        string $observedConferenceState = 'unobserved',
        string $observedParticipantState = 'unobserved',
    ): array {
        $userId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'asterisk-recovery@example.test',
            'normalized_email' => 'asterisk-recovery@example.test',
            'display_name' => 'Asterisk Recovery User',
            'password' => 'not-a-real-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'asterisk-recovery',
            'display_name' => 'Asterisk Recovery',
            'runtime_node_id' => $nodeId,
            'desired_state' => $conferenceDesiredState,
            'observed_state' => $observedConferenceState,
            'configuration_generation' => 1,
            'observed_generation' => $observedConferenceState === 'ready' ? 1 : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'runtime_node_id' => $nodeId,
            'status' => 'active',
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'role' => 'participant',
            'desired_state' => $participantDesiredState,
            'observed_state' => $observedParticipantState,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$conferenceId, $participantId];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function clientReturning(array $summary): AsteriskAriClient
    {
        return new class(new AsteriskCatalog, app(AsteriskAriProfileService::class), $summary) extends AsteriskAriClient
        {
            /**
             * @param  array<string, mixed>  $summary
             */
            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, private readonly array $summary)
            {
                parent::__construct($catalog, $profiles);
            }

            public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
            {
                return $this->summary;
            }
        };
    }

    private function clientThrowing(FailureClass $failureClass): AsteriskAriClient
    {
        return new class(new AsteriskCatalog, app(AsteriskAriProfileService::class), $failureClass) extends AsteriskAriClient
        {
            public function __construct(AsteriskCatalog $catalog, AsteriskAriProfileService $profiles, private readonly FailureClass $failureClass)
            {
                parent::__construct($catalog, $profiles);
            }

            public function conferenceRuntimeSummary(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): array
            {
                throw new AsteriskAriException($this->failureClass, 'runtime_unavailable', 'runtime unavailable', true);
            }
        };
    }

    private function inspectionServiceReturning(RuntimeConferenceInspectionResult $result, ?object $recorded = null): RuntimeConferenceInspectionService
    {
        return new RuntimeConferenceInspectionService(new RuntimeAdapterRegistry([
            new class($result, $recorded) implements RuntimeAdapter, RuntimeConferenceInspectionAdapter
            {
                public function __construct(private readonly RuntimeConferenceInspectionResult $result, private readonly ?object $recorded = null) {}

                public function adapterKey(): string
                {
                    return 'asterisk-ari';
                }

                public function execute(array $operation): array
                {
                    return ['status' => 'unsupported'];
                }

                public function inspectConferenceRuntime(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult
                {
                    return $this->result;
                }

                public function recordConferenceRuntimeInspectionEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool
                {
                    if ($this->recorded !== null && property_exists($this->recorded, 'items')) {
                        $this->recorded->items[] = [
                            'tenant_id' => $tenantId,
                            'runtime_node_id' => $runtimeNodeId,
                            'conference_id' => $conferenceId,
                            'participant_id' => $participantId,
                        ];
                    }

                    return true;
                }
            },
        ]));
    }

    private function inspectionServiceCounting(object $inspectionCalls): RuntimeConferenceInspectionService
    {
        return new RuntimeConferenceInspectionService(new RuntimeAdapterRegistry([
            new class($inspectionCalls) implements RuntimeAdapter, RuntimeConferenceInspectionAdapter
            {
                public function __construct(private readonly object $inspectionCalls) {}

                public function adapterKey(): string
                {
                    return 'asterisk-ari';
                }

                public function execute(array $operation): array
                {
                    return ['status' => 'unsupported'];
                }

                public function inspectConferenceRuntime(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult
                {
                    $this->inspectionCalls->count++;

                    return RuntimeConferenceInspectionResult::observed(true, false, false);
                }

                public function recordConferenceRuntimeInspectionEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool
                {
                    return true;
                }
            },
        ]));
    }
}
