<?php

namespace Tests\Feature\RuntimeEngine;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
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
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
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
        $this->assertDatabaseCount('control_plane_outbox_messages', 1);

        $unsupportedAdapterNode = $this->runtimeNode('active', 'adapterless')[1];
        DB::table('runtime_nodes')->where('id', $unsupportedAdapterNode)->update(['tenant_id' => $tenantId, 'adapter_key' => 'freeswitch-esl']);
        $unsupportedAdapterId = $operations->create('test.operation.success', 'runtime_node', $unsupportedAdapterNode, ['safe' => true], $context, runtimeNodeId: $unsupportedAdapterNode);

        $this->assertSame(0, $worker->workOnce('command-worker-b', batchSize: 1));
        $this->assertDatabaseHas('runtime_operations', ['id' => $unsupportedAdapterId, 'status' => OperationStatus::TerminalFailed->value, 'last_failure_class' => 'unsupported_capability']);
    }

    public function test_event_receipts_deduplicate_normalize_project_checkpoint_and_fence(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode('active');
        $receipts = new RuntimeEventReceiptRepository;
        $epochId = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'test-listener');

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
        $this->assertDatabaseCount('runtime_observations', 1);

        $unknown = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epochId, 'external-unknown', 'test.runtime.unknown', 1, ['state' => 'unknown']);
        $this->assertSame(0, $worker->workOnce('normalizer-d', batchSize: 1));
        $this->assertDatabaseHas('runtime_event_receipts', ['id' => $unknown['id'], 'status' => 'unsupported']);
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

    public function test_stale_observation_derivation(): void
    {
        [, $nodeId] = $this->runtimeNode('active');
        DB::table('runtime_nodes')->where('id', $nodeId)->update([
            'observed_state' => 'ready',
            'observed_at' => now()->subHour(),
        ]);

        $this->assertSame(1, (new ProjectionService)->markStale(60));
        $this->assertDatabaseHas('runtime_nodes', ['id' => $nodeId, 'observed_state' => 'stale']);
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
