<?php

namespace App\RuntimeEngine\Commands;

final readonly class RuntimeConferenceInspectionResult
{
    private function __construct(
        public string $status,
        public bool $conferencePresent = false,
        public ?bool $participantPresent = null,
        public ?bool $participantAttached = null,
        public ?string $failureClass = null,
        public ?string $failureCode = null,
    ) {}

    public static function observed(bool $conferencePresent, ?bool $participantPresent = null, ?bool $participantAttached = null): self
    {
        return new self('observed', $conferencePresent, $participantPresent, $participantAttached);
    }

    public static function unavailable(?string $failureClass = null, ?string $failureCode = null): self
    {
        return new self('unavailable', failureClass: $failureClass, failureCode: $failureCode);
    }

    public static function unsupported(): self
    {
        return new self('unsupported');
    }

    public static function failed(?string $failureClass = null, ?string $failureCode = null): self
    {
        return new self('failed', failureClass: $failureClass, failureCode: $failureCode);
    }
}
