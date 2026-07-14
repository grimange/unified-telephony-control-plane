<?php

namespace App\RuntimeEngine\Events;

interface EventNormalizer
{
    public function adapterKey(): string;

    public function eventType(): string;

    public function eventVersion(): int;

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function normalize(object $receipt, array $payload): array;
}
