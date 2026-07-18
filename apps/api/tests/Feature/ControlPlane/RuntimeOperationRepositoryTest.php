<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\RuntimeOperations\FencingViolation;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RuntimeOperationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_claiming_uses_one_active_lease_and_fencing(): void
    {
        $repository = new RuntimeOperationRepository;
        $operationId = $repository->create(
            'test.operation.execute',
            'test.aggregate',
            'aggregate-1',
            ['action' => 'execute'],
            ExecutionContext::system(),
        );

        $firstClaim = $repository->claimAvailable('worker-a', leaseSeconds: 60);

        $this->assertCount(1, $firstClaim);
        $this->assertSame($operationId, $firstClaim[0]->id);
        $this->assertSame(1, $firstClaim[0]->attemptCount);
        $this->assertSame([], $repository->claimAvailable('worker-b', leaseSeconds: 60));

        DB::table('runtime_operations')->where('id', $operationId)->update([
            'lease_expires_at' => now()->subSeconds(5),
        ]);

        $secondClaim = $repository->claimAvailable('worker-b', leaseSeconds: 60);

        $this->assertCount(1, $secondClaim);
        $this->assertNotSame($firstClaim[0]->leaseToken, $secondClaim[0]->leaseToken);
        $this->assertSame(2, $secondClaim[0]->attemptCount);

        $this->expectException(FencingViolation::class);
        $repository->markRunning($operationId, $firstClaim[0]->leaseToken);
    }

    public function test_current_fencing_token_can_complete_operation_and_outbox_atomically(): void
    {
        $repository = new RuntimeOperationRepository;
        $outbox = new OutboxRepository;
        $context = ExecutionContext::system();
        $operationId = $repository->create(
            'test.operation.execute',
            'test.aggregate',
            'aggregate-2',
            ['action' => 'execute'],
            $context,
        );
        $claim = $repository->claimAvailable('worker-a')[0];
        $repository->markRunning($operationId, $claim->leaseToken);

        $event = EventEnvelope::forAggregate(
            'test.operation.completed',
            1,
            'test.aggregate',
            'aggregate-2',
            ['operation_id' => $operationId],
            $context,
        );

        $repository->complete($operationId, $claim->leaseToken, $event, $outbox);

        $this->assertDatabaseHas('runtime_operations', [
            'id' => $operationId,
            'status' => OperationStatus::Succeeded->value,
        ]);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'aggregate_type' => 'test.aggregate',
            'aggregate_id' => 'aggregate-2',
            'event_type' => 'test.operation.completed',
        ]);
    }

    public function test_operation_claiming_filters_types_before_leasing(): void
    {
        $repository = new RuntimeOperationRepository;
        $context = ExecutionContext::system();
        $normalId = $repository->create(
            'test.operation.normal',
            'test.aggregate',
            'aggregate-normal',
            ['action' => 'normal'],
            $context,
        );
        $fenceId = $repository->create(
            'runtime.node.runtime.fence',
            'conference',
            'conference-1',
            ['action' => 'fence'],
            $context,
        );

        $genericClaims = $repository->claimAvailable(
            'generic-worker',
            batchSize: 10,
            excludeOperationTypes: ['runtime.node.runtime.fence'],
        );

        $this->assertCount(1, $genericClaims);
        $this->assertSame($normalId, $genericClaims[0]->id);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => $normalId,
            'status' => OperationStatus::Leased->value,
            'lease_owner' => 'generic-worker',
        ]);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => $fenceId,
            'status' => OperationStatus::Pending->value,
            'lease_owner' => null,
        ]);

        $infrastructureClaims = $repository->claimAvailable(
            'infrastructure-worker',
            batchSize: 10,
            includeOperationTypes: ['runtime.node.runtime.fence'],
        );

        $this->assertCount(1, $infrastructureClaims);
        $this->assertSame($fenceId, $infrastructureClaims[0]->id);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => $fenceId,
            'status' => OperationStatus::Leased->value,
            'lease_owner' => 'infrastructure-worker',
        ]);
    }

    public function test_retryable_failures_schedule_retry_and_terminal_failures_complete(): void
    {
        $repository = new RuntimeOperationRepository;
        $operationId = $repository->create(
            'test.operation.execute',
            'test.aggregate',
            'aggregate-3',
            ['action' => 'execute'],
            ExecutionContext::system(),
            maxAttempts: 2,
        );

        $claim = $repository->claimAvailable('worker-a')[0];
        $next = $repository->fail($operationId, $claim->leaseToken, FailureClass::Timeout, 'timeout', 'runtime timed out');

        $this->assertSame(OperationStatus::RetryScheduled, $next);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => $operationId,
            'status' => OperationStatus::RetryScheduled->value,
        ]);

        DB::table('runtime_operations')->where('id', $operationId)->update([
            'available_at' => now()->subSecond(),
        ]);

        $claim = $repository->claimAvailable('worker-b')[0];
        $next = $repository->fail($operationId, $claim->leaseToken, FailureClass::InvalidRequest, 'invalid', 'bad request');

        $this->assertSame(OperationStatus::TerminalFailed, $next);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => $operationId,
            'status' => OperationStatus::TerminalFailed->value,
            'last_failure_class' => FailureClass::InvalidRequest->value,
        ]);
    }
}
