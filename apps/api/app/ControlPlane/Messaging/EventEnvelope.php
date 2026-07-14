<?php

namespace App\ControlPlane\Messaging;

use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\OutboxMessageId;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use Carbon\CarbonImmutable;

final readonly class EventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public OutboxMessageId $eventId,
        public string $eventType,
        public int $eventVersion,
        public string $aggregateType,
        public string $aggregateId,
        public ?string $tenantId,
        public string $correlationId,
        public ?string $causationId,
        public string $requestId,
        public CarbonImmutable $occurredAt,
        public array $payload,
    ) {
        PayloadSafety::assertSafe($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forAggregate(
        string $eventType,
        int $eventVersion,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ExecutionContext $context,
    ): self {
        return new self(
            eventId: OutboxMessageId::new(),
            eventType: $eventType,
            eventVersion: $eventVersion,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            tenantId: $context->tenantId,
            correlationId: $context->correlationId->value(),
            causationId: $context->causationId?->value(),
            requestId: $context->requestId->value(),
            occurredAt: $context->occurredAt,
            payload: $payload,
        );
    }

    public function stablePayload(): string
    {
        return StableJson::encode($this->payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function safeLogContext(): array
    {
        return [
            'event_id' => $this->eventId->value(),
            'event_type' => $this->eventType,
            'event_version' => $this->eventVersion,
            'aggregate_type' => $this->aggregateType,
            'correlation_id' => $this->correlationId,
            'request_id' => $this->requestId,
        ];
    }
}
