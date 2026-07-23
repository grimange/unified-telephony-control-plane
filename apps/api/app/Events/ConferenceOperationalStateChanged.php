<?php

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ConferenceOperationalStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $eventType,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $conferenceId,
        public readonly string $tenantId,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tenant.{$this->tenantId}.conferences");
    }

    public function broadcastAs(): string
    {
        return 'conference.operational-state.changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'conference_id' => $this->conferenceId,
            'tenant_id' => $this->tenantId,
            'occurred_at' => $this->occurredAt->toJSON(),
        ];
    }
}
