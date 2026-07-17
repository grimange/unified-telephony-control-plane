<?php

namespace App\RuntimeAdapters\Asterisk;

final class AsteriskAriReconnectBackoff
{
    /**
     * @var array<string, array{next_attempt_at:float,delay_ms:int,credential_version:int,configuration_version:int}>
     */
    private array $state = [];

    public function shouldAttempt(string $nodeId, float $now): bool
    {
        $entry = $this->state[$nodeId] ?? null;

        return $entry === null || $now >= $entry['next_attempt_at'];
    }

    public function recordFailure(string $nodeId, float $now, int $minDelayMs, int $maxDelayMs, int $credentialVersion, int $configurationVersion): void
    {
        $min = max(100, $minDelayMs);
        $max = max($min, $maxDelayMs);

        $existing = $this->state[$nodeId] ?? null;
        $changed = $existing === null
            || $existing['credential_version'] !== $credentialVersion
            || $existing['configuration_version'] !== $configurationVersion;

        $delayMs = $changed ? $min : min($max, max($min, $existing['delay_ms'] * 2));

        $this->state[$nodeId] = [
            'next_attempt_at' => $now + ($delayMs / 1000),
            'delay_ms' => $delayMs,
            'credential_version' => $credentialVersion,
            'configuration_version' => $configurationVersion,
        ];
    }

    public function clear(string $nodeId): void
    {
        unset($this->state[$nodeId]);
    }

    public function currentDelayMs(string $nodeId): ?int
    {
        return $this->state[$nodeId]['delay_ms'] ?? null;
    }
}
