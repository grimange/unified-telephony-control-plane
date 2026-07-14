<?php

namespace App\RuntimeEngine\Events;

final class EventNormalizerRegistry
{
    /** @var array<string, EventNormalizer> */
    private array $normalizers = [];

    /**
     * @param  iterable<EventNormalizer>  $normalizers
     */
    public function __construct(iterable $normalizers = [])
    {
        foreach ($normalizers as $normalizer) {
            $this->register($normalizer);
        }
    }

    public function register(EventNormalizer $normalizer): void
    {
        $this->normalizers[$normalizer->adapterKey().':'.$normalizer->eventType().':'.$normalizer->eventVersion()] = $normalizer;
    }

    public function get(string $adapterKey, string $eventType, int $eventVersion): ?EventNormalizer
    {
        return $this->normalizers[$adapterKey.':'.$eventType.':'.$eventVersion] ?? null;
    }
}
