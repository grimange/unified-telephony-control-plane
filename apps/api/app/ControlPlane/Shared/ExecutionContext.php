<?php

namespace App\ControlPlane\Shared;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final readonly class ExecutionContext
{
    public function __construct(
        public RequestId $requestId,
        public CorrelationId $correlationId,
        public ?CausationId $causationId,
        public string $actorType,
        public ?string $actorId,
        public ?string $tenantId,
        public ?string $reason,
        public string $origin,
        public CarbonImmutable $occurredAt,
    ) {}

    public static function system(
        ?RequestId $requestId = null,
        ?string $reason = 'system operation',
        ?string $tenantId = null,
        string $origin = 'system',
    ): self {
        $correlation = CorrelationId::new();

        return new self(
            requestId: $requestId ?? RequestId::new(),
            correlationId: $correlation,
            causationId: null,
            actorType: 'system',
            actorId: null,
            tenantId: $tenantId,
            reason: $reason,
            origin: $origin,
            occurredAt: CarbonImmutable::now(),
        );
    }

    public static function fromRequest(Request $request): self
    {
        $requestId = $request->headers->get('X-Request-ID');
        $trustedRequestId = is_string($requestId) && preg_match('/\A[a-f0-9]{32}\z/i', $requestId) === 1
            ? RequestId::fromString($requestId)
            : RequestId::new();

        return new self(
            requestId: $trustedRequestId,
            correlationId: CorrelationId::new(),
            causationId: null,
            actorType: 'system',
            actorId: null,
            tenantId: null,
            reason: null,
            origin: 'http',
            occurredAt: CarbonImmutable::now(),
        );
    }

    public function causedBy(CausationId $causationId): self
    {
        return new self(
            requestId: $this->requestId,
            correlationId: $this->correlationId,
            causationId: $causationId,
            actorType: $this->actorType,
            actorId: $this->actorId,
            tenantId: $this->tenantId,
            reason: $this->reason,
            origin: $this->origin,
            occurredAt: $this->occurredAt,
        );
    }
}
