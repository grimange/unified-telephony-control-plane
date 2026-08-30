<?php

namespace Tests\Unit\RuntimeRegistry;

use App\RuntimeRegistry\RuntimeNodeCapacityEvaluator;
use PHPUnit\Framework\TestCase;

final class RuntimeNodeCapacityEvaluatorTest extends TestCase
{
    public function test_zero_capacity_is_unlimited_and_has_existing_unlimited_rank(): void
    {
        $node = (object) ['capacity_weight' => 0];
        $evaluator = new RuntimeNodeCapacityEvaluator();

        $this->assertTrue($evaluator->eligible($node, 999999));
        $this->assertSame(PHP_INT_MAX, $evaluator->availableSlotRank($node, 999999));
    }

    public function test_capacity_is_one_shared_runtime_node_budget(): void
    {
        $node = (object) ['capacity_weight' => 4];
        $evaluator = new RuntimeNodeCapacityEvaluator();

        $this->assertTrue($evaluator->eligible($node, 3));
        $this->assertFalse($evaluator->eligible($node, 4));
        $this->assertSame(1, $evaluator->availableSlotRank($node, 3));
        $this->assertSame(0, $evaluator->availableSlotRank($node, 4));
    }

    public function test_release_is_automatically_reflected_by_the_next_evaluation(): void
    {
        $node = (object) ['capacity_weight' => 2];
        $evaluator = new RuntimeNodeCapacityEvaluator();

        $this->assertFalse($evaluator->eligible($node, 2));
        $this->assertTrue($evaluator->eligible($node, 1));
    }
}
