<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Shared\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IdempotencyStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_and_fingerprint_replays_original_result(): void
    {
        $store = new IdempotencyStore;
        $key = IdempotencyKey::fromString('test-key-1');

        $this->assertNull($store->begin('test.operation.execute', $key, ['aggregate_id' => 'a']));
        $store->complete('test.operation.execute', $key, ['operation_id' => 'operation-1']);

        $record = $store->begin('test.operation.execute', $key, ['aggregate_id' => 'a']);

        $this->assertNotNull($record);
        $this->assertSame('completed', $record->status);
        $this->assertSame(['operation_id' => 'operation-1'], $record->result);
    }

    public function test_same_key_with_different_fingerprint_conflicts(): void
    {
        $store = new IdempotencyStore;
        $key = IdempotencyKey::fromString('test-key-2');

        $store->begin('test.operation.execute', $key, ['aggregate_id' => 'a']);

        $this->expectException(IdempotencyConflict::class);
        $store->begin('test.operation.execute', $key, ['aggregate_id' => 'b']);
    }

    public function test_in_progress_request_is_distinguishable(): void
    {
        $store = new IdempotencyStore;
        $key = IdempotencyKey::fromString('test-key-3');

        $store->begin('test.operation.execute', $key, ['aggregate_id' => 'a']);
        $record = $store->begin('test.operation.execute', $key, ['aggregate_id' => 'a']);

        $this->assertNotNull($record);
        $this->assertTrue($record->inProgress());
    }
}
