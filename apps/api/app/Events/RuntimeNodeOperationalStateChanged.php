<?php

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RuntimeNodeOperationalStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $eventType,
        public readonly string $runtimeNodeId,
        public readonly string $tenantId,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tenant.{$this->tenantId}.runtime-nodes");
    }

    public function broadcastAs(): string
    {
        return 'runtime-node.operational-state.changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'event_type' => $this->eventType,
            'aggregate_type' => 'runtime_node',
            'runtime_node_id' => $this->runtimeNodeId,
            'tenant_id' => $this->tenantId,
            'occurred_at' => $this->occurredAt->toJSON(),
        ];
    }
}
