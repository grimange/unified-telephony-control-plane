<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\FencingViolation;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ControlPlaneProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_plane_kernel_end_to_end_proof(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The C0 proof requires PostgreSQL locking and audit triggers.');
        }

        $context = ExecutionContext::system();
        $idempotency = new IdempotencyStore;
        $operations = new RuntimeOperationRepository;
        $outbox = new OutboxRepository;
        $inbox = new InboxRepository;
        $audit = new AuditRepository;
        $key = IdempotencyKey::fromString('proof-operation-key');
        $fingerprint = ['operation_type' => 'test.operation.execute', 'aggregate_id' => 'proof-aggregate'];

        $this->assertNull($idempotency->begin('runtime-operation:test.operation.execute', $key, $fingerprint));
        $operationId = $operations->create(
            'test.operation.execute',
            'test.aggregate',
            'proof-aggregate',
            ['action' => 'execute'],
            $context,
            $key,
        );
        $idempotency->complete('runtime-operation:test.operation.execute', $key, ['operation_id' => $operationId]);

        $duplicate = $idempotency->begin('runtime-operation:test.operation.execute', $key, $fingerprint);
        $this->assertSame(['operation_id' => $operationId], $duplicate?->result);
        $this->assertSame($operationId, $operations->findIdempotent('test.operation.execute', $key));

        try {
            $idempotency->begin('runtime-operation:test.operation.execute', $key, ['operation_type' => 'test.operation.execute', 'aggregate_id' => 'other']);
            $this->fail('conflicting idempotent request was not rejected');
        } catch (IdempotencyConflict) {
            $this->assertTrue(true);
        }

        $firstClaim = $operations->claimAvailable('worker-a', leaseSeconds: 60);
        $this->assertCount(1, $firstClaim);
        $this->assertSame([], $operations->claimAvailable('worker-b', leaseSeconds: 60));

        DB::table('runtime_operations')->where('id', $operationId)->update([
            'lease_expires_at' => now()->subSecond(),
        ]);

        $secondClaim = $operations->claimAvailable('worker-b', leaseSeconds: 60);
        $this->assertCount(1, $secondClaim);

        try {
            $operations->markRunning($operationId, $firstClaim[0]->leaseToken);
            $this->fail('superseded fencing token was allowed to finalize');
        } catch (FencingViolation) {
            $this->assertTrue(true);
        }

        $operations->markRunning($operationId, $secondClaim[0]->leaseToken);
        $operations->complete(
            $operationId,
            $secondClaim[0]->leaseToken,
            EventEnvelope::forAggregate(
                'test.operation.completed',
                1,
                'test.aggregate',
                'proof-aggregate',
                ['operation_id' => $operationId],
                $context,
            ),
            $outbox,
        );

        $this->assertDatabaseHas('runtime_operations', [
            'id' => $operationId,
            'status' => OperationStatus::Succeeded->value,
        ]);
        $this->assertDatabaseCount('control_plane_outbox_messages', 1);

        $this->assertSame('accepted', $inbox->receive('proof-consumer', 'message-1', 'test.message', ['value' => 1]));
        $inbox->markProcessed('proof-consumer', 'message-1');
        $this->assertSame('duplicate_processed', $inbox->receive('proof-consumer', 'message-1', 'test.message', ['value' => 1]));

        $auditId = $audit->append($context, 'test.operation.completed', 'runtime_operation', $operationId, ['safe' => true]);
        $this->assertDatabaseHas('control_plane_audit_records', [
            'id' => $auditId,
            'action' => 'test.operation.completed',
        ]);

        $this->expectException(QueryException::class);
        DB::table('control_plane_audit_records')->where('id', $auditId)->delete();
    }
}
