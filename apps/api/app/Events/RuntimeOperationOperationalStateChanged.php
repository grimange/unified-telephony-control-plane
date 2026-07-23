<?php

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RuntimeOperationOperationalStateChanged implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $eventType,
        public readonly string $aggregateId,
        public readonly string $runtimeOperationId,
        public readonly ?string $runtimeNodeId,
        public readonly string $tenantId,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tenant.'.$this->tenantId.'.runtime-operations');
    }

    public function broadcastAs(): string
    {
        return 'runtime-operation.operational-state.changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'event_type' => $this->eventType,
            'aggregate_type' => 'runtime_operation',
            'aggregate_id' => $this->aggregateId,
            'runtime_operation_id' => $this->runtimeOperationId,
            'tenant_id' => $this->tenantId,
            'occurred_at' => $this->occurredAt->toJSON(),
        ];

        if ($this->runtimeNodeId !== null && $this->runtimeNodeId !== '') {
            $payload['runtime_node_id'] = $this->runtimeNodeId;
        }

        return $payload;
    }
}
