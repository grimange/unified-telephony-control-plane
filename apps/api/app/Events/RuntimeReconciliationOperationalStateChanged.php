<?php

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RuntimeReconciliationOperationalStateChanged implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $eventType,
        public readonly string $aggregateId,
        public readonly string $runtimeReconciliationId,
        public readonly ?string $runtimeNodeId,
        public readonly ?string $runtimeOperationId,
        public readonly string $tenantId,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tenant.'.$this->tenantId.'.runtime-reconciliations');
    }

    public function broadcastAs(): string
    {
        return 'runtime-reconciliation.operational-state.changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'event_type' => $this->eventType,
            'aggregate_type' => 'runtime_reconciliation',
            'aggregate_id' => $this->aggregateId,
            'runtime_reconciliation_id' => $this->runtimeReconciliationId,
            'tenant_id' => $this->tenantId,
            'occurred_at' => $this->occurredAt->toJSON(),
        ];

        if ($this->runtimeNodeId !== null && $this->runtimeNodeId !== '') {
            $payload['runtime_node_id'] = $this->runtimeNodeId;
        }
        if ($this->runtimeOperationId !== null && $this->runtimeOperationId !== '') {
            $payload['runtime_operation_id'] = $this->runtimeOperationId;
        }

        return $payload;
    }
}
