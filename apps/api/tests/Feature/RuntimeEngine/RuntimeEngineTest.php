<?php

namespace Tests\Feature\RuntimeEngine;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Identity\IdentityIds;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\RuntimeEngine\Events\EventNormalizer;
use App\RuntimeEngine\Events\EventNormalizerRegistry;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\RuntimeEngine\Sources\EventSourceRepository;
use App\TelephonyDomain\CallDomainService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RuntimeEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_dispatch_claims_leases_queue_delivery_and_fencing(): void
    {
        $context = ExecutionContext::system();
        $outbox = new OutboxRepository;
        $messageId = $outbox->append(EventEnvelope::forAggregate(
            'test.engine.event',
            1,
            'test.aggregate',
            'aggregate-1',
            ['safe' => true],
            $context,
        ));

        try {
            DB::transaction(function () use ($outbox, $context): void {
                $outbox->append(EventEnvelope::forAggregate(
                    'test.engine.rolled_back',
                    1,
                    'test.aggregate',
                    'aggregate-rollback',
                    ['safe' => true],
                    $context,
                ));
                throw new RollbackProof;
            }, 1);
            $this->fail('rolled back outbox transaction did not throw');
        } catch (RollbackProof) {
            $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $messageId]);
            $this->assertDatabaseMissing('control_plane_outbox_messages', ['event_type' => 'test.engine.rolled_back']);
        }
    }

    public function test_outbox_dispatcher_end_to_end(): void
    {
        $context = ExecutionContext::system();
        $outbox = new OutboxRepository;
        $inbox = new InboxRepository;
        $messageId = $outbox->append(EventEnvelope::forAggregate(
            'test.engine.event',
            1,
            'test.aggregate',
            'aggregate-1',
            ['safe' => true],
            $context,
        ));

        $claims = $outbox->claimAvailable('dispatcher-a', batchSize: 1, leaseSeconds: 60);
        $this->assertCount(1, $claims);
        $this->assertSame([], $outbox->claimAvailable('dispatcher-b', batchSize: 1, leaseSeconds: 60));

        DB::table('control_plane_outbox_messages')->where('id', $messageId)->update(['lease_expires_at' => now()->subSecond()]);
        $takeover = $outbox->claimAvailable('dispatcher-b', batchSize: 1, leaseSeconds: 60);
        $this->assertCount(1, $takeover);
        $this->assertFalse($outbox->markDispatchedWithFence($messageId, $claims[0]->leaseToken));
        DB::table('control_plane_outbox_messages')->where('id', $messageId)->update(['lease_expires_at' => now()->subSecond()]);

        $dispatcher = new OutboxDispatcher($outbox, $inbox);
        $this->assertSame(1, $dispatcher->dispatchOnce('dispatcher-c', batchSize: 1));
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'id' => $messageId,
            'dispatch_status' => 'dispatched',
        ]);

        $this->assertSame('duplicate_processed', $inbox->receive('control-plane-generic-consumer', $messageId, 'test.engine.event', ['safe' => true]));
    }

    public function test_command_worker_executes_success_retry_terminal_and_unsupported_paths(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $context = ExecutionContext::system(tenantId: $tenantId);
        $operations = new RuntimeOperationRepository;
        $outbox = new OutboxRepository;
        $worker = new CommandWorker(
            $operations,
            $outbox,
            new RuntimeOperationHandlerRegistry([
                new TestRuntimeHandler('test.operation.success', 'completed'),
                new TestRuntimeHandler('test.operation.retry', 'retry_scheduled'),
                new TestRuntimeHandler('test.operation.terminal', 'terminal_failure'),
            ]),
            new RuntimeAdapterRegistry([new TestRuntimeAdapter]),
            app(CallDomainService::class),
        );

        $successId = $operations->create('test.operation.success', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);
        $retryId = $operations->create('test.operation.retry', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);
        $terminalId = $operations->create('test.operation.terminal', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);
        $unsupportedHandlerId = $operations->create('test.operation.missing', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);

        $this->assertSame(1, $worker->workOnce('command-worker-a', batchSize: 4));
        $this->assertDatabaseHas('runtime_operations', ['id' => $successId, 'status' => OperationStatus::Succeeded->value]);
        $this->assertDatabaseHas('runtime_operations', ['id' => $retryId, 'status' => OperationStatus::RetryScheduled->value, 'last_failure_class' => 'transient_transport']);
        $this->assertDatabaseHas('runtime_operations', ['id' => $terminalId, 'status' => OperationStatus::TerminalFailed->value, 'last_failure_class' => 'invalid_request']);
        $this->assertDatabaseHas('runtime_operations', ['id' => $unsupportedHandlerId, 'status' => OperationStatus::TerminalFailed->value, 'last_failure_class' => 'unsupported_capability']);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_node')
            ->where('event_type', 'runtime_operation.test_completed')
            ->count());

        $unsupportedAdapterNode = $this->runtimeNode('active', 'adapterless')[1];
        DB::table('runtime_nodes')->where('id', $unsupportedAdapterNode)->update(['tenant_id' => $tenantId, 'adapter_key' => 'unknown-test-adapter']);
        $unsupportedAdapterId = $operations->create('test.operation.success', 'runtime_node', $unsupportedAdapterNode, ['safe' => true], $context, runtimeNodeId: $unsupportedAdapterNode);

        $this->assertSame(0, $worker->workOnce('command-worker-b', batchSize: 1));
        $this->assertDatabaseHas('runtime_operations', ['id' => $unsupportedAdapterId, 'status' => OperationStatus::TerminalFailed->value, 'last_failure_class' => 'unsupported_capability']);
    }

    public function test_worker_type_filters_keep_fence_operations_out_of_generic_workers(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $context = ExecutionContext::system(tenantId: $tenantId);
        $operations = new RuntimeOperationRepository;
        $worker = new CommandWorker(
            $operations,
            new OutboxRepository,
            new RuntimeOperationHandlerRegistry([
                new TestRuntimeHandler('test.operation.normal', 'completed'),
                new TestRuntimeHandler('runtime.node.runtime.fence', 'completed'),
            ]),
            new RuntimeAdapterRegistry([new TestRuntimeAdapter]),
            app(CallDomainService::class),
        );

        $normalId = $operations->create('test.operation.normal', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);
        $fenceId = $operations->create('runtime.node.runtime.fence', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);

        $this->assertSame(1, $worker->workOnce(
            'generic-worker',
            batchSize: 10,
            excludeOperationTypes: ['runtime.node.runtime.fence'],
        ));
        $this->assertDatabaseHas('runtime_operations', ['id' => $normalId, 'status' => OperationStatus::Succeeded->value]);
        $this->assertDatabaseHas('runtime_operations', ['id' => $fenceId, 'status' => OperationStatus::Pending->value]);

        $this->assertSame(1, $worker->workOnce(
            'infrastructure-worker',
            batchSize: 10,
            includeOperationTypes: ['runtime.node.runtime.fence'],
        ));
        $this->assertDatabaseHas('runtime_operations', ['id' => $fenceId, 'status' => OperationStatus::Succeeded->value]);
        $this->assertSame(2, DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_node')
            ->where('event_type', 'runtime_operation.test_completed')
            ->count());
    }

    public function test_infrastructure_worker_filter_does_not_claim_normal_operations(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $context = ExecutionContext::system(tenantId: $tenantId);
        $operations = new RuntimeOperationRepository;
        $worker = new CommandWorker(
            $operations,
            new OutboxRepository,
            new RuntimeOperationHandlerRegistry([
                new TestRuntimeHandler('test.operation.normal', 'completed'),
                new TestRuntimeHandler('runtime.node.runtime.fence', 'completed'),
            ]),
            new RuntimeAdapterRegistry([new TestRuntimeAdapter]),
            app(CallDomainService::class),
        );

        $normalId = $operations->create('test.operation.normal', 'runtime_node', $nodeId, ['safe' => true], $context, runtimeNodeId: $nodeId);

        $this->assertSame(0, $worker->workOnce(
            'infrastructure-worker',
            batchSize: 10,
            includeOperationTypes: ['runtime.node.runtime.fence'],
        ));
        $this->assertDatabaseHas('runtime_operations', ['id' => $normalId, 'status' => OperationStatus::Pending->value]);
    }

    public function test_worker_filters_reject_unknown_operation_types(): void
    {
        $worker = new CommandWorker(
            new RuntimeOperationRepository,
            new OutboxRepository,
            new RuntimeOperationHandlerRegistry([
                new TestRuntimeHandler('test.operation.normal', 'completed'),
            ]),
            new RuntimeAdapterRegistry([new TestRuntimeAdapter]),
            app(CallDomainService::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $worker->workOnce('generic-worker', includeOperationTypes: ['runtime.node.runtime.fence']);
    }

    public function test_event_receipts_deduplicate_normalize_project_checkpoint_and_fence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'test-listener');
        $sourceId = DB::table('event_sources')->where('source_kind', 'runtime-node')->where('source_key', $nodeId)->value('id');
        $this->assertIsString($sourceId);
        $this->assertDatabaseHas('runtime_event_connection_epochs', ['id' => $epochId, 'event_source_id' => $sourceId]);

        $accepted = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epochId, 'external-1', 'test.runtime.ready', 1, ['state' => 'ready']);
        $duplicate = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epochId, 'external-1', 'test.runtime.ready', 1, ['state' => 'ready']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame('duplicate', $duplicate['status']);

        try {
            $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epochId, 'external-1', 'test.runtime.ready', 1, ['state' => 'degraded']);
            $this->fail('conflicting duplicate event was not rejected');
        } catch (DomainException) {
            $this->assertDatabaseHas('runtime_event_receipts', ['id' => $accepted['id'], 'status' => 'conflict']);
            DB::table('runtime_event_receipts')->where('id', $accepted['id'])->update(['status' => 'pending']);
        }

        $claims = $receipts->claimAvailable('normalizer-a', batchSize: 1);
        $this->assertCount(1, $claims);
        DB::table('runtime_event_receipts')->where('id', $accepted['id'])->update(['lease_expires_at' => now()->subSecond()]);
        $takeover = $receipts->claimAvailable('normalizer-b', batchSize: 1);
        $this->assertCount(1, $takeover);
        $this->assertFalse($receipts->markProcessed($accepted['id'], $claims[0]->lease_token));

        DB::table('runtime_event_receipts')->where('id', $accepted['id'])->update([
            'status' => 'pending',
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
        ]);

        $worker = new EventNormalizerWorker(
            $receipts,
            new EventNormalizerRegistry([new TestEventNormalizer]),
            new ProjectionService,
        );
        $this->assertSame(1, $worker->workOnce('normalizer-c', batchSize: 1));
        $this->assertDatabaseHas('runtime_event_receipts', ['id' => $accepted['id'], 'status' => 'processed']);
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'observed_state' => 'ready', 'last_evidence_source' => $accepted['id']]);
        $this->assertDatabaseCount('runtime_projection_checkpoints', 1);
        $this->assertDatabaseHas('runtime_projection_checkpoints', ['event_source_id' => $sourceId, 'runtime_node_id' => $nodeId]);
        $this->assertDatabaseCount('runtime_observations', 1);

        $unknown = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epochId, 'external-unknown', 'test.runtime.unknown', 1, ['state' => 'unknown']);
        $this->assertSame(0, $worker->workOnce('normalizer-d', batchSize: 1));
        $this->assertDatabaseHas('runtime_event_receipts', ['id' => $unknown['id'], 'status' => 'unsupported']);
    }

    public function test_event_source_identity_supports_runtime_node_and_platform_sources(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $sources = new EventSourceRepository;

        $runtimeSource = $sources->ensureRuntimeNodeSource($tenantId, $nodeId, 'asterisk-ari');
        $again = $sources->ensureRuntimeNodeSource($tenantId, $nodeId, 'asterisk-ari');
        $platformSource = $sources->ensure(EventSourceRepository::KIND_KAMAILIO_REGISTRATION, EventSourceRepository::KAMAILIO_REGISTRATION_KEY);

        $this->assertSame($runtimeSource->id, $again->id);
        $this->assertSame('runtime-node', $runtimeSource->source_kind);
        $this->assertSame($nodeId, $runtimeSource->source_key);
        $this->assertSame($nodeId, $runtimeSource->runtime_node_id);
        $this->assertSame('kamailio-registration', $platformSource->source_kind);
        $this->assertSame('local-shared-registrar', $platformSource->source_key);
        $this->assertNull($platformSource->runtime_node_id);
        $this->assertSame(2, DB::table('event_sources')->count());

        $this->expectException(DomainException::class);
        $sources->ensure('unknown-kind', 'local-shared-registrar', null);
    }

    public function test_platform_source_lease_epoch_receipt_checkpoint_and_fencing(): void
    {
        $sources = new EventSourceRepository;
        $source = $sources->ensure(EventSourceRepository::KIND_KAMAILIO_REGISTRATION, EventSourceRepository::KAMAILIO_REGISTRATION_KEY);
        $leases = new RuntimeListenerLeaseRepository($sources);
        $receipts = new RuntimeEventReceiptRepository($sources);
        $projection = new ProjectionService;

        $firstLease = $leases->claimSource($source->id, 'platform-registration-observer', 'observer-a', 60);
        $this->assertNotNull($firstLease);
        $this->assertSame($source->id, $firstLease->event_source_id);
        $this->assertNull($firstLease->runtime_node_id);
        $this->assertNull($leases->claimSource($source->id, 'platform-registration-observer', 'observer-b', 60));

        $firstEpoch = $receipts->openSourceEpoch($source->id, EventSourceRepository::KIND_KAMAILIO_REGISTRATION, 'observer-a');
        $accepted = $receipts->ingestSource($source->id, EventSourceRepository::KIND_KAMAILIO_REGISTRATION, $firstEpoch, 'snapshot-1', 'test.platform.snapshot', 1, [
            'safe' => true,
            'contact_state' => 'present',
        ]);
        $duplicate = $receipts->ingestSource($source->id, EventSourceRepository::KIND_KAMAILIO_REGISTRATION, $firstEpoch, 'snapshot-1', 'test.platform.snapshot', 1, [
            'safe' => true,
            'contact_state' => 'present',
        ]);

        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame('duplicate', $duplicate['status']);
        $this->assertDatabaseHas('runtime_event_receipts', [
            'id' => $accepted['id'],
            'event_source_id' => $source->id,
            'runtime_node_id' => null,
        ]);
        $payload = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->value('sanitized_payload');
        $this->assertStringNotContainsString('password', (string) $payload);

        $projection->advanceCheckpointForSource(
            'kamailio-registration-observer',
            'local-shared-registrar',
            $source->id,
            null,
            $accepted['id'],
            now(),
        );
        $this->assertDatabaseHas('runtime_projection_checkpoints', [
            'event_source_id' => $source->id,
            'runtime_node_id' => null,
            'last_source_event_id' => $accepted['id'],
        ]);

        DB::table('runtime_listener_leases')->where('id', $firstLease->id)->update(['lease_expires_at' => now()->subSecond()]);
        $takeover = $leases->claimSource($source->id, 'platform-registration-observer', 'observer-b', 60);
        $this->assertNotNull($takeover);
        $this->assertNotSame($firstLease->fencing_token, $takeover->fencing_token);
        $secondEpoch = $receipts->openSourceEpoch($source->id, EventSourceRepository::KIND_KAMAILIO_REGISTRATION, 'observer-b');
        $this->assertSame(1, $receipts->closeStaleEpochsForSource($source->id, 'observer-b'));
        $this->assertDatabaseHas('runtime_event_connection_epochs', ['id' => $firstEpoch, 'status' => 'expired']);
        $this->assertDatabaseHas('runtime_event_connection_epochs', ['id' => $secondEpoch, 'status' => 'open']);

        $this->expectException(DomainException::class);
        $receipts->ingestSource($source->id, EventSourceRepository::KIND_KAMAILIO_REGISTRATION, $firstEpoch, 'snapshot-stale', 'test.platform.snapshot', 1, ['safe' => true]);
    }

    public function test_reconciler_leasing_idempotent_operation_generation_blocked_and_takeover(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 7);

        $claims = $repository->claimDue('reconciler-a', batchSize: 1);
        $this->assertCount(1, $claims);
        $this->assertSame([], $repository->claimDue('reconciler-b', batchSize: 1));
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update(['lease_expires_at' => now()->subSecond()]);
        $takeover = $repository->claimDue('reconciler-b', batchSize: 1);
        $this->assertCount(1, $takeover);
        $this->assertFalse($repository->markResult($stateId, $claims[0]->lease_token, ReconciliationResult::waiting(30)));

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_check_at' => now()->subSecond(),
            'last_operation_id' => null,
        ]);

        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new OperationRequiredReconciler]),
            $operations,
        );
        $this->assertSame(1, $worker->workOnce('reconciler-c', batchSize: 1));
        $this->assertDatabaseHas('runtime_reconciliation_states', ['id' => $stateId, 'status' => 'operation_required']);
        $this->assertDatabaseCount('runtime_operations', 1);
        $this->assertSame(0, $worker->workOnce('reconciler-d', batchSize: 1));
        $this->assertDatabaseCount('runtime_operations', 1);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'next_check_at' => now()->subSecond(),
            'last_operation_id' => null,
        ]);
        $blockedWorker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new BlockedReconciler]),
            $operations,
        );
        $this->assertSame(1, $blockedWorker->workOnce('reconciler-e', batchSize: 1));
        $this->assertDatabaseHas('runtime_reconciliation_states', ['id' => $stateId, 'status' => 'blocked', 'blocked_reason' => 'test_blocked']);
    }

    public function test_reconciler_preserves_last_operation_id_across_non_operation_required_results(): void
    {
        // Regression: a prior build re-initialised the per-claim operation id to
        // null before every evaluate() call, so any non-operation_required result
        // (blocked/waiting/converged) wiped out an already-linked last_operation_id.
        // The next forced re-check then saw last_operation_id === null again, asked
        // for a fresh operation with the same deterministic idempotency key, and
        // oscillated forever between operation_required and blocked without ever
        // settling. last_operation_id must be preserved unless a new operation is
        // actually created for this pass.
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 9);

        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new OperationRequiredReconciler]),
            $operations,
        );
        $this->assertSame(1, $worker->workOnce('reconciler-f', batchSize: 1));
        $linkedOperationId = DB::table('runtime_reconciliation_states')->where('id', $stateId)->value('last_operation_id');
        $this->assertIsString($linkedOperationId);
        $this->assertDatabaseCount('runtime_operations', 1);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update(['next_check_at' => now()->subSecond()]);
        $blockedWorker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new BlockedReconciler]),
            $operations,
        );
        $this->assertSame(1, $blockedWorker->workOnce('reconciler-g', batchSize: 1));
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'status' => 'blocked',
            'last_operation_id' => $linkedOperationId,
        ]);

        // A forced re-check (e.g. a periodic target-ensure sweep) must not disturb
        // the preserved link or re-request an operation for the same generation.
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update(['next_check_at' => now()->subSecond()]);
        $this->assertSame(1, $blockedWorker->workOnce('reconciler-h', batchSize: 1));
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'status' => 'blocked',
            'last_operation_id' => $linkedOperationId,
        ]);
        $this->assertDatabaseCount('runtime_operations', 1);
    }

    public function test_terminal_operation_does_not_suppress_recovery_operation_for_same_generation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 10);

        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new OperationRequiredReconciler]),
            $operations,
        );
        $this->assertSame(1, $worker->workOnce('reconciler-terminal-a', batchSize: 1));
        $firstOperationId = DB::table('runtime_reconciliation_states')->where('id', $stateId)->value('last_operation_id');
        $this->assertIsString($firstOperationId);

        DB::table('runtime_operations')->where('id', $firstOperationId)->update([
            'status' => OperationStatus::Succeeded->value,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'next_check_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $worker->workOnce('reconciler-terminal-b', batchSize: 1));
        $secondOperationId = DB::table('runtime_reconciliation_states')->where('id', $stateId)->value('last_operation_id');

        $this->assertIsString($secondOperationId);
        $this->assertNotSame($firstOperationId, $secondOperationId);
        $this->assertDatabaseCount('runtime_operations', 2);
        $this->assertDatabaseHas('runtime_operations', ['id' => $firstOperationId, 'status' => OperationStatus::Succeeded->value]);
        $this->assertDatabaseHas('runtime_operations', ['id' => $secondOperationId, 'status' => OperationStatus::Pending->value]);
    }

    public function test_successful_runtime_operation_wakes_linked_reconciliation_target(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $reconciliation = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $reconciliation->ensureTarget($tenantId, 'runtime_node', $nodeId, 11);
        $operationId = $operations->create(
            'test.runtime.ensure',
            'runtime_node',
            $nodeId,
            ['runtime_node_id' => $nodeId],
            ExecutionContext::system(tenantId: $tenantId),
            runtimeNodeId: $nodeId,
        );
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'operation_required',
            'last_operation_id' => $operationId,
            'next_check_at' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);

        $claim = $operations->claimAvailable('command-worker', batchSize: 1)[0];
        $operations->markRunning($claim->id, $claim->leaseToken);
        $operations->complete(
            $claim->id,
            $claim->leaseToken,
            EventEnvelope::forAggregate(
                'test.runtime.completed',
                1,
                'runtime_node',
                $nodeId,
                ['safe' => true],
                ExecutionContext::system(tenantId: $tenantId),
            ),
            new OutboxRepository,
        );

        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('waiting', $state->status);
        $this->assertSame($operationId, $state->last_operation_id);
        $this->assertTrue(now()->greaterThanOrEqualTo($state->next_check_at));
        $this->assertNull($state->lease_owner);
        $this->assertDatabaseHas('runtime_operations', ['id' => $operationId, 'status' => OperationStatus::Succeeded->value]);
    }

    public function test_successful_runtime_operation_wakes_matching_aggregate_reconciliation_target(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $reconciliation = new ReconciliationRepository;
        $operations = new RuntimeOperationRepository;
        $stateId = $reconciliation->ensureTarget($tenantId, 'conference_participant', 'participant-1', 13);
        $operationId = $operations->create(
            'conference.participant.ensure',
            'conference_participant',
            'participant-1',
            ['runtime_node_id' => $nodeId, 'conference_participant_id' => 'participant-1'],
            ExecutionContext::system(tenantId: $tenantId),
            runtimeNodeId: $nodeId,
        );
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'operation_required',
            'last_operation_id' => null,
            'next_check_at' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);

        $claim = $operations->claimAvailable('command-worker', batchSize: 1)[0];
        $operations->markRunning($claim->id, $claim->leaseToken);
        $operations->complete(
            $claim->id,
            $claim->leaseToken,
            EventEnvelope::forAggregate(
                'test.runtime.completed',
                1,
                'conference_participant',
                'participant-1',
                ['safe' => true],
                ExecutionContext::system(tenantId: $tenantId),
            ),
            new OutboxRepository,
        );

        $state = DB::table('runtime_reconciliation_states')->where('id', $stateId)->first();
        $this->assertSame('waiting', $state->status);
        $this->assertNull($state->last_operation_id);
        $this->assertTrue(now()->greaterThanOrEqualTo($state->next_check_at));
        $this->assertNull($state->lease_owner);
        $this->assertDatabaseHas('runtime_operations', ['id' => $operationId, 'status' => OperationStatus::Succeeded->value]);
    }

    public function test_due_converged_reconciliation_target_is_rechecked(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 12);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'converged',
            'next_check_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);

        $claim = $repository->claimDue('reconciler-periodic', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($stateId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'status' => 'converged',
            'lease_owner' => 'reconciler-periodic',
        ]);
    }

    public function test_wake_target_promotes_converged_reconciliation_to_immediate_waiting_work(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'conference_participant', 'participant-reconnect', 2);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'converged',
            'last_operation_id' => 'previous-operation',
            'next_check_at' => now()->addMinutes(5),
            'lease_owner' => 'stale-reconciler',
            'lease_token' => 'stale-token',
            'lease_expires_at' => now()->addMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->assertSame($stateId, $repository->wakeTarget($tenantId, 'conference_participant', 'participant-reconnect', 2));

        $claim = $repository->claimDue('reconciler-reconnect', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($stateId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'status' => 'waiting',
            'lease_owner' => 'reconciler-reconnect',
            'last_operation_id' => null,
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $stateId,
            'desired_generation' => 2,
        ]);
    }

    public function test_due_non_converged_reconciliation_work_is_claimed_before_periodic_converged_rechecks(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $oldConvergedId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 12);
        $waitingId = $repository->ensureTarget($tenantId, 'conference', 'conference-1', 1);

        DB::table('runtime_reconciliation_states')->where('id', $oldConvergedId)->update([
            'status' => 'converged',
            'next_check_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $waitingId)->update([
            'status' => 'waiting',
            'next_check_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claim = $repository->claimDue('reconciler-fresh-work', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($waitingId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $waitingId,
            'status' => 'waiting',
            'lease_owner' => 'reconciler-fresh-work',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $oldConvergedId,
            'status' => 'converged',
            'lease_owner' => null,
        ]);
    }

    public function test_operation_follow_up_reconciliation_is_claimed_before_unlinked_waiting_work(): void
    {
        [$tenantId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $oldWaitingId = $repository->ensureTarget($tenantId, 'conference', 'old-conference', 1);
        $operationFollowUpId = $repository->ensureTarget($tenantId, 'conference_participant', 'participant-after-operation', 1);

        DB::table('runtime_reconciliation_states')->where('id', $oldWaitingId)->update([
            'status' => 'waiting',
            'last_operation_id' => null,
            'next_check_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $operationFollowUpId)->update([
            'status' => 'waiting',
            'last_operation_id' => 'operation-completed',
            'next_check_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claim = $repository->claimDue('reconciler-operation-follow-up', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($operationFollowUpId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $operationFollowUpId,
            'status' => 'waiting',
            'lease_owner' => 'reconciler-operation-follow-up',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $oldWaitingId,
            'status' => 'waiting',
            'lease_owner' => null,
        ]);
    }

    public function test_recent_lifecycle_change_is_claimed_before_stale_operation_follow_up(): void
    {
        [$tenantId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $staleFollowUpId = $repository->ensureTarget($tenantId, 'conference_participant', 'stale-participant', 1);
        $freshLifecycleId = $repository->ensureTarget($tenantId, 'conference_participant', 'fresh-participant-removal', 2);

        DB::table('runtime_reconciliation_states')->where('id', $staleFollowUpId)->update([
            'status' => 'waiting',
            'last_operation_id' => 'older-operation',
            'next_check_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $freshLifecycleId)->update([
            'status' => 'waiting',
            'last_operation_id' => null,
            'next_check_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claim = $repository->claimDue('reconciler-fresh-lifecycle', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($freshLifecycleId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $freshLifecycleId,
            'status' => 'waiting',
            'lease_owner' => 'reconciler-fresh-lifecycle',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $staleFollowUpId,
            'status' => 'waiting',
            'lease_owner' => null,
        ]);
    }

    public function test_waiting_reconciliation_follow_up_is_claimed_before_recurring_operation_required_work(): void
    {
        [$tenantId] = $this->runtimeNode('active');
        $repository = new ReconciliationRepository;
        $recurringRuntimeId = $repository->ensureTarget($tenantId, 'runtime_node', 'runtime-node-retry', 1);
        $conferenceFollowUpId = $repository->ensureTarget($tenantId, 'conference', 'conference-after-runtime-event', 2);

        DB::table('runtime_reconciliation_states')->where('id', $recurringRuntimeId)->update([
            'status' => 'operation_required',
            'last_operation_id' => 'runtime-operation',
            'next_check_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $conferenceFollowUpId)->update([
            'status' => 'waiting',
            'last_operation_id' => 'conference-operation',
            'next_check_at' => now()->subSecond(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $claim = $repository->claimDue('reconciler-waiting-follow-up', batchSize: 1);

        $this->assertCount(1, $claim);
        $this->assertSame($conferenceFollowUpId, $claim[0]->id);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $conferenceFollowUpId,
            'status' => 'waiting',
            'lease_owner' => 'reconciler-waiting-follow-up',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $recurringRuntimeId,
            'status' => 'operation_required',
            'lease_owner' => null,
        ]);
    }

    public function test_noop_reconciliation_claim_and_result_cycles_emit_no_outbox_events(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active', 'noop-reconciliation-node');
        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 1);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'observed_generation' => null,
            'last_operation_id' => null,
            'blocked_reason' => null,
            'next_check_at' => now()->subSecond(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);

        $baseline = $this->runtimeReconciliationOutboxCount();

        for ($cycle = 0; $cycle < 10; $cycle++) {
            $claim = $repository->claimDue('noop-reconciler-'.$cycle, batchSize: 1, leaseSeconds: 60);

            $this->assertCount(1, $claim);
            $this->assertSame($stateId, $claim[0]->id);
            $this->assertDatabaseHas('runtime_reconciliation_states', [
                'id' => $stateId,
                'status' => 'waiting',
                'lease_owner' => 'noop-reconciler-'.$cycle,
            ]);
            $this->assertTrue($repository->markResult($stateId, (string) $claim[0]->lease_token, ReconciliationResult::waiting('stable', 0)));
            $this->assertDatabaseHas('runtime_reconciliation_states', [
                'id' => $stateId,
                'status' => 'waiting',
                'last_operation_id' => null,
                'blocked_reason' => null,
            ]);
        }

        $this->assertSame($baseline, $this->runtimeReconciliationOutboxCount());
    }

    public function test_superseded_reconciliation_claim_is_skipped_and_next_claim_is_processed(): void
    {
        [$tenantId, $firstNodeId] = $this->runtimeNode('active', 'superseded-claim-first');
        [, $secondNodeId] = $this->runtimeNode('active', 'superseded-claim-second');
        $repository = new ReconciliationRepository;
        $firstStateId = $repository->ensureTarget($tenantId, 'runtime_node', $firstNodeId, 1);
        $secondStateId = $repository->ensureTarget($tenantId, 'runtime_node', $secondNodeId, 1);
        $reconciler = new SupersedingThenConvergedReconciler($firstStateId);
        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([$reconciler]),
            new RuntimeOperationRepository,
        );

        $this->assertSame(1, $worker->workOnce('reconciler-lease-loss', batchSize: 2));
        $this->assertSame(2, $reconciler->evaluations);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $firstStateId,
            'lease_owner' => 'replacement-worker',
            'lease_token' => 'replacement-token',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $secondStateId,
            'status' => 'converged',
            'lease_owner' => null,
        ]);
        $this->assertDatabaseMissing('runtime_operations', [
            'aggregate_id' => $firstNodeId,
            'operation_type' => 'test.operation.reconcile',
        ]);
    }

    public function test_unexpected_reconciliation_exception_is_not_silently_swallowed(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active', 'unexpected-reconciliation-failure');
        $repository = new ReconciliationRepository;
        $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 1);
        $worker = new ReconciliationWorker(
            $repository,
            new ReconcilerRegistry([new ThrowingReconciler]),
            new RuntimeOperationRepository,
        );

        $this->expectException(\RuntimeException::class);
        $worker->workOnce('reconciler-unexpected-failure', batchSize: 1);
    }

    public function test_claim_due_only_changes_lease_fields_and_emits_no_reconciliation_events(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active', 'claim-silent-node');
        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 1);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'converged',
            'observed_generation' => 1,
            'next_check_at' => now()->subSecond(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);

        $baseline = $this->runtimeReconciliationOutboxCount();

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $claim = $repository->claimDue('claim-silent-reconciler-'.$cycle, batchSize: 1, leaseSeconds: 60);

            $this->assertCount(1, $claim);
            $this->assertSame($stateId, $claim[0]->id);
            $this->assertDatabaseHas('runtime_reconciliation_states', [
                'id' => $stateId,
                'status' => 'converged',
                'observed_generation' => 1,
                'lease_owner' => 'claim-silent-reconciler-'.$cycle,
            ]);
            $this->assertSame($baseline, $this->runtimeReconciliationOutboxCount());

            DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
                'next_check_at' => now()->subSecond(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function test_reconciliation_result_events_are_emitted_only_for_real_fingerprint_transitions(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active', 'transition-events-node');
        $repository = new ReconciliationRepository;

        $convergedId = $repository->ensureTarget($tenantId, 'runtime_node', $nodeId, 1);
        $baseline = $this->runtimeReconciliationOutboxCount();
        $this->claimAndMark($repository, $convergedId, ReconciliationResult::converged(0));
        $this->assertSame($baseline + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, $this->runtimeReconciliationEventCount($convergedId, 'runtime_reconciliation.converged'));
        $this->makeReconciliationDue($convergedId);
        $this->claimAndMark($repository, $convergedId, ReconciliationResult::converged(0));
        $this->assertSame($baseline + 1, $this->runtimeReconciliationOutboxCount());

        $driftId = $repository->ensureTarget($tenantId, 'conference', 'transition-drift-conference', 1);
        $beforeDrift = $this->runtimeReconciliationOutboxCount();
        $this->assertSame($driftId, $repository->ensureTarget($tenantId, 'conference', 'transition-drift-conference', 2));
        $this->assertSame($beforeDrift + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, $this->runtimeReconciliationEventCount($driftId, 'runtime_reconciliation.drift_detected'));
        $this->assertSame($driftId, $repository->ensureTarget($tenantId, 'conference', 'transition-drift-conference', 2));
        $this->assertSame($beforeDrift + 1, $this->runtimeReconciliationOutboxCount());

        $operationId = RuntimeOperationId::new()->value();
        $operationRequiredId = $repository->ensureTarget($tenantId, 'conference', 'transition-operation-conference', 1);
        $beforeOperation = $this->runtimeReconciliationOutboxCount();
        $this->claimAndMark(
            $repository,
            $operationRequiredId,
            ReconciliationResult::operationRequired('conference.inspect', ['conference_id' => 'transition-operation-conference']),
            $operationId,
        );
        $this->assertSame($beforeOperation + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, $this->runtimeReconciliationEventCount($operationRequiredId, 'runtime_reconciliation.operation_required'));
        $this->makeReconciliationDue($operationRequiredId);
        $this->claimAndMark(
            $repository,
            $operationRequiredId,
            ReconciliationResult::operationRequired('conference.inspect', ['conference_id' => 'transition-operation-conference']),
            $operationId,
        );
        $this->assertSame($beforeOperation + 1, $this->runtimeReconciliationOutboxCount());

        $failureId = $repository->ensureTarget($tenantId, 'conference', 'transition-failure-conference', 1);
        $beforeFailure = $this->runtimeReconciliationOutboxCount();
        $this->claimAndMark($repository, $failureId, ReconciliationResult::blocked('test_blocked', 0));
        $this->assertSame($beforeFailure + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, $this->runtimeReconciliationEventCount($failureId, 'runtime_reconciliation.failed'));
        $this->makeReconciliationDue($failureId);
        $this->claimAndMark($repository, $failureId, ReconciliationResult::blocked('test_blocked', 0));
        $this->assertSame($beforeFailure + 1, $this->runtimeReconciliationOutboxCount());
        $this->makeReconciliationDue($failureId);
        $this->claimAndMark($repository, $failureId, ReconciliationResult::blocked('test_blocked_changed', 0));
        $this->assertSame($beforeFailure + 2, $this->runtimeReconciliationOutboxCount());

        $retryId = $repository->ensureTarget($tenantId, 'conference', 'transition-retry-conference', 1);
        DB::table('runtime_reconciliation_states')->where('id', $retryId)->update([
            'status' => 'blocked',
            'blocked_reason' => 'transient_failure',
            'next_check_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);
        $beforeRetry = $this->runtimeReconciliationOutboxCount();
        $this->claimAndMark($repository, $retryId, ReconciliationResult::waiting('ready_for_retry', 0));
        $this->assertSame($beforeRetry + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, $this->runtimeReconciliationEventCount($retryId, 'runtime_reconciliation.retry_scheduled'));
        $this->makeReconciliationDue($retryId);
        $this->claimAndMark($repository, $retryId, ReconciliationResult::waiting('ready_for_retry', 0));
        $this->assertSame($beforeRetry + 1, $this->runtimeReconciliationOutboxCount());
    }

    public function test_repeated_polling_over_stable_reconciliation_rows_produces_no_event_storm(): void
    {
        [$tenantId] = $this->runtimeNode('active', 'volume-tenant-seed');
        $repository = new ReconciliationRepository;
        $stateIds = [];

        for ($row = 0; $row < 20; $row++) {
            $stateId = $repository->ensureTarget($tenantId, 'conference', 'stable-conference-'.$row, 1);
            DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
                'status' => 'waiting',
                'observed_generation' => null,
                'last_operation_id' => null,
                'blocked_reason' => null,
                'next_check_at' => now()->subSecond(),
                'updated_at' => now(),
            ]);
            $stateIds[] = $stateId;
        }

        $baseline = $this->runtimeReconciliationOutboxCount();

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $claims = $repository->claimDue('volume-reconciler-'.$cycle, batchSize: 20, leaseSeconds: 60);
            $this->assertCount(20, $claims);

            foreach ($claims as $claim) {
                $this->assertTrue($repository->markResult((string) $claim->id, (string) $claim->lease_token, ReconciliationResult::waiting('stable', 0)));
            }

            DB::table('runtime_reconciliation_states')
                ->whereIn('id', $stateIds)
                ->update(['next_check_at' => now()->subSecond()]);
        }

        $this->assertSame($baseline, $this->runtimeReconciliationOutboxCount());
    }

    public function test_stale_observation_derivation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_at' => now()->subHour(),
        ]);

        $this->assertSame(1, (new ProjectionService)->markStale(60));
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'observed_state' => 'stale']);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'tenant_id' => $tenantId,
            'aggregate_type' => 'runtime_node',
            'aggregate_id' => $nodeId,
            'event_type' => 'runtime_node.observed_state_changed',
        ]);
    }

    public function test_background_runtime_observation_appends_notification_intent_with_canonical_state(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'projection-test');
        $accepted = $receipts->ingest(
            $tenantId,
            $nodeId,
            'asterisk-ari',
            $epochId,
            'runtime-node-observation-1',
            'runtime.node.observed',
            1,
            ['safe' => true],
        );
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();

        (new ProjectionService)->apply($receipt, [[
            'tenant_id' => $tenantId,
            'observation_type' => 'runtime.state.observed',
            'observation_version' => 1,
            'subject_type' => 'runtime_node',
            'subject_id' => $nodeId,
            'observed_state' => 'ready',
            'configuration_version' => 2,
            'observed_at' => now(),
            'payload' => ['safe' => true],
        ]]);

        $this->assertDatabaseHas('runtime_nodes', [
            'id' => $nodeId,
            'observed_state' => 'ready',
            'observed_configuration_version' => 2,
        ]);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'tenant_id' => $tenantId,
            'aggregate_type' => 'runtime_node',
            'aggregate_id' => $nodeId,
            'event_type' => 'runtime_node.observed_state_changed',
        ]);
    }

    public function test_background_runtime_observation_and_notification_roll_back_together(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'projection-rollback-test');
        $accepted = $receipts->ingest(
            $tenantId,
            $nodeId,
            'asterisk-ari',
            $epochId,
            'runtime-node-observation-rollback',
            'runtime.node.observed',
            1,
            ['safe' => true],
        );
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();

        try {
            DB::transaction(function () use ($receipt, $tenantId, $nodeId): void {
                (new ProjectionService)->apply($receipt, [[
                    'tenant_id' => $tenantId,
                    'observation_type' => 'runtime.state.observed',
                    'observation_version' => 1,
                    'subject_type' => 'runtime_node',
                    'subject_id' => $nodeId,
                    'observed_state' => 'degraded',
                    'configuration_version' => 3,
                    'observed_at' => now(),
                    'payload' => ['safe' => true],
                ]]);
                throw new RollbackProof;
            }, 1);
            $this->fail('projection rollback did not throw');
        } catch (RollbackProof) {
            $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'observed_state' => 'unobserved']);
            $this->assertDatabaseMissing('control_plane_outbox_messages', [
                'aggregate_type' => 'runtime_node',
                'aggregate_id' => $nodeId,
                'event_type' => 'runtime_node.observed_state_changed',
            ]);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runtimeNode(string $desiredState = 'active', string $slug = 'engine-node'): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug.'-tenant',
            'display_name' => 'Engine Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Engine Runtime',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => $desiredState,
            'observed_state' => 'unobserved',
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_node_capabilities')->insert([
            'id' => IdentityIds::new(),
            'runtime_node_id' => $nodeId,
            'capability_key' => 'event.stream',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $nodeId];
    }

    private function claimAndMark(ReconciliationRepository $repository, string $stateId, ReconciliationResult $result, ?string $operationId = null): void
    {
        DB::table('runtime_reconciliation_states')->where('id', '!=', $stateId)->update([
            'next_check_at' => now()->addHour(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);
        $this->makeReconciliationDue($stateId);
        $claim = $repository->claimDue('transition-reconciler', batchSize: 1, leaseSeconds: 60);

        $this->assertCount(1, $claim);
        $this->assertSame($stateId, $claim[0]->id);
        $this->assertTrue($repository->markResult($stateId, (string) $claim[0]->lease_token, $result, $operationId));
    }

    private function makeReconciliationDue(string $stateId): void
    {
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'next_check_at' => now()->subSecond(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function runtimeReconciliationOutboxCount(): int
    {
        return DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('event_type', 'like', 'runtime_reconciliation.%')
            ->count();
    }

    private function runtimeReconciliationEventCount(string $stateId, string $eventType): int
    {
        return DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $stateId)
            ->where('event_type', $eventType)
            ->count();
    }
}

final class RollbackProof extends \RuntimeException {}

final class TestRuntimeHandler implements RuntimeOperationHandler
{
    public function __construct(private readonly string $operationType, private readonly string $result) {}

    public function operationType(): string
    {
        return $this->operationType;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return 'event.stream';
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        return match ($this->result) {
            'completed' => [
                'status' => 'completed',
                'event_type' => 'runtime_operation.test_completed',
                'event_payload' => ['operation_type' => $operation['operation_type']],
            ],
            'retry_scheduled' => [
                'status' => 'retry_scheduled',
                'failure_class' => 'transient_transport',
                'failure_code' => 'test_retry',
                'failure_message' => 'retryable test failure',
            ],
            default => [
                'status' => 'terminal_failure',
                'failure_class' => 'invalid_request',
                'failure_code' => 'test_terminal',
                'failure_message' => 'terminal test failure',
            ],
        };
    }
}

final class TestRuntimeAdapter implements RuntimeAdapter
{
    public function adapterKey(): string
    {
        return 'asterisk-ari';
    }

    public function execute(array $operation): array
    {
        return ['status' => 'ok'];
    }
}

final class TestEventNormalizer implements EventNormalizer
{
    public function adapterKey(): string
    {
        return 'asterisk-ari';
    }

    public function eventType(): string
    {
        return 'test.runtime.ready';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function normalize(object $receipt, array $payload): array
    {
        return [[
            'observation_type' => 'runtime.readiness.observed',
            'observation_version' => 1,
            'subject_type' => 'runtime_node',
            'subject_id' => $receipt->runtime_node_id,
            'observed_state' => $payload['state'] ?? 'unknown',
            'configuration_version' => 1,
            'observed_at' => now(),
            'payload' => ['source' => 'test-normalizer'],
        ]];
    }
}

final class OperationRequiredReconciler implements Reconciler
{
    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $state): ReconciliationResult
    {
        return ReconciliationResult::operationRequired(
            'test.operation.reconcile',
            ['action' => 'refresh'],
        );
    }
}

final class SupersedingThenConvergedReconciler implements Reconciler
{
    public int $evaluations = 0;

    public function __construct(private readonly string $supersededStateId) {}

    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $state): ReconciliationResult
    {
        $this->evaluations++;

        if ($this->evaluations === 1) {
            DB::table('runtime_reconciliation_states')->where('id', $this->supersededStateId)->update([
                'lease_owner' => 'replacement-worker',
                'lease_token' => 'replacement-token',
                'lease_expires_at' => now()->addMinute(),
                'updated_at' => now(),
            ]);
        }

        return ReconciliationResult::converged(0);
    }
}

final class ThrowingReconciler implements Reconciler
{
    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $state): ReconciliationResult
    {
        throw new \RuntimeException('unexpected reconciliation failure');
    }
}

final class BlockedReconciler implements Reconciler
{
    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $state): ReconciliationResult
    {
        return ReconciliationResult::blocked('test_blocked', 300);
    }
}
