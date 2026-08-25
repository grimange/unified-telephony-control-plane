<?php

namespace App\TelephonyDomain;

/** Derived, immutable routing result. It is never administrator-editable state. */
final readonly class RouteDecision
{
    /** @param list<RouteConstraint> $constraints */
    private function __construct(
        private string $id,
        private string $direction,
        private string $status,
        private ?string $routeId,
        private ?string $externalTrunkId,
        private ?DestinationRef $destination,
        private ?string $callerIdentityId,
        private array $constraints,
        private ?string $failureCode,
    ) {}

    /** @param list<RouteConstraint> $constraints */
    public static function selected(string $direction, string $routeId, string $trunkId, DestinationRef $destination, ?string $callerIdentityId, array $constraints): self
    {
        return new self((string) \Illuminate\Support\Str::uuid(), $direction, 'selected', $routeId, $trunkId, $destination, $callerIdentityId, $constraints, null);
    }

    /** @param list<RouteConstraint> $constraints */
    public static function failed(string $direction, string $failureCode, array $constraints = []): self
    {
        return new self((string) \Illuminate\Support\Str::uuid(), $direction, 'failed', null, null, null, null, $constraints, $failureCode);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'status' => $this->status,
            'route_id' => $this->routeId,
            'external_trunk_id' => $this->externalTrunkId,
            'destination_ref' => $this->destination?->toArray(),
            'caller_identity_id' => $this->callerIdentityId,
            'constraints' => array_map(static fn (RouteConstraint $constraint): array => $constraint->toArray(), $this->constraints),
            'failure_code' => $this->failureCode,
        ];
    }

    public function id(): string { return $this->id; }
    public function routeId(): ?string { return $this->routeId; }
    public function externalTrunkId(): ?string { return $this->externalTrunkId; }
    public function destination(): ?DestinationRef { return $this->destination; }
    public function callerIdentityId(): ?string { return $this->callerIdentityId; }
    public function isSelected(): bool { return $this->status === 'selected'; }
}
