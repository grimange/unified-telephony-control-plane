<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MessagingAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolled_back_transaction_creates_no_outbox_message(): void
    {
        $outbox = new OutboxRepository;
        $context = ExecutionContext::system();

        try {
            DB::transaction(function () use ($outbox, $context): void {
                $outbox->append(EventEnvelope::forAggregate(
                    'test.operation.completed',
                    1,
                    'test.aggregate',
                    'aggregate-rollback',
                    ['result' => 'ok'],
                    $context,
                ));

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // Expected rollback path.
        }

        $this->assertDatabaseCount('control_plane_outbox_messages', 0);
    }

    public function test_outbox_dispatch_marking_is_idempotent(): void
    {
        $outbox = new OutboxRepository;
        $id = $outbox->append(EventEnvelope::forAggregate(
            'test.operation.completed',
            1,
            'test.aggregate',
            'aggregate-dispatch',
            ['result' => 'ok'],
            ExecutionContext::system(),
        ));

        $this->assertTrue($outbox->markDispatched($id));
        $this->assertFalse($outbox->markDispatched($id));
    }

    public function test_inbox_deduplicates_messages_and_rejects_payload_mismatch(): void
    {
        $inbox = new InboxRepository;

        $this->assertSame('accepted', $inbox->receive('consumer-a', 'message-1', 'test.message', ['value' => 1]));
        $inbox->markProcessed('consumer-a', 'message-1');
        $this->assertSame('duplicate_processed', $inbox->receive('consumer-a', 'message-1', 'test.message', ['value' => 1]));

        $this->expectException(\DomainException::class);
        $inbox->receive('consumer-a', 'message-1', 'test.message', ['value' => 2]);
    }

    public function test_audit_records_are_redacted_and_append_only_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger enforcement is proven by control-plane scripts.');
        }

        $audit = new AuditRepository;
        $id = $audit->append(
            ExecutionContext::system(),
            'test.action',
            'test.subject',
            'subject-1',
            ['password' => 'should-redact', 'safe' => 'value'],
            'proof',
        );

        $record = DB::table('control_plane_audit_records')->where('id', $id)->first();
        $this->assertStringContainsString('[redacted]', (string) $record->metadata);
        $this->assertStringNotContainsString('should-redact', (string) $record->metadata);

        $this->expectException(QueryException::class);
        DB::table('control_plane_audit_records')->where('id', $id)->update(['reason' => 'mutated']);
    }
}
