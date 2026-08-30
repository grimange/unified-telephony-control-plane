<?php

namespace App\RuntimeRegistry;

final class RuntimeNodeCapacityEvaluator
{
    public function eligible(object $node, int $activeTelephonyWork): bool
    {
        $capacity = (int) ($node->capacity_weight ?? 0);

        return $capacity === 0 || $activeTelephonyWork < $capacity;
    }

    public function availableSlotRank(object $node, int $activeTelephonyWork): int
    {
        $capacity = (int) ($node->capacity_weight ?? 0);

        return $capacity === 0 ? PHP_INT_MAX : max(0, $capacity - $activeTelephonyWork);
    }
}
