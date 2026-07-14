<?php

namespace App\Support\Health;

final readonly class ReadinessReport
{
    /**
     * @param  array<string, DependencyStatus>  $dependencies
     */
    public function __construct(
        public array $dependencies,
    ) {}

    public function ready(): bool
    {
        foreach ($this->dependencies as $status) {
            if ($status !== DependencyStatus::Ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function dependencyPayload(): array
    {
        return array_map(
            static fn (DependencyStatus $status): string => $status->value,
            $this->dependencies,
        );
    }
}
