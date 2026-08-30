<?php

namespace Tests\Unit\RuntimeRegistry;

use App\RuntimeRegistry\RuntimeNodeFailureDomainEvaluator;
use PHPUnit\Framework\TestCase;

final class RuntimeNodeFailureDomainEvaluatorTest extends TestCase
{
    public function test_no_constraint_does_not_require_observed_topology(): void
    {
        $node = (object) ['placement_region' => null, 'placement_zone' => null];

        $this->assertTrue((new RuntimeNodeFailureDomainEvaluator())->eligible($node, null));
    }

    public function test_region_and_zone_are_exact_hard_constraints(): void
    {
        $node = (object) ['placement_region' => 'us-east-1', 'placement_zone' => 'us-east-1a'];
        $evaluator = new RuntimeNodeFailureDomainEvaluator();

        $this->assertTrue($evaluator->eligible($node, (object) ['status' => 'placed', 'observed_region' => 'us-east-1', 'observed_zone' => 'us-east-1a']));
        $this->assertFalse($evaluator->eligible($node, (object) ['status' => 'placed', 'observed_region' => 'us-west-2', 'observed_zone' => 'us-east-1a']));
        $this->assertFalse($evaluator->eligible($node, (object) ['status' => 'placed', 'observed_region' => 'us-east-1', 'observed_zone' => 'us-east-1b']));
    }

    public function test_configured_constraint_rejects_unknown_unavailable_or_ambiguous_topology(): void
    {
        $node = (object) ['placement_region' => 'us-east-1', 'placement_zone' => null];
        $evaluator = new RuntimeNodeFailureDomainEvaluator();

        foreach ([null, (object) ['status' => 'kubernetes_observation_unavailable'], (object) ['status' => 'identity_present_but_not_currently_observed'], (object) ['status' => 'ambiguous_multiple_nodes_observed', 'observed_region' => null, 'observed_zone' => null]] as $observation) {
            $this->assertFalse($evaluator->eligible($node, $observation));
        }
    }

    public function test_external_runtime_without_constraint_remains_eligible(): void
    {
        $node = (object) ['placement_region' => null, 'placement_zone' => null];

        $this->assertTrue((new RuntimeNodeFailureDomainEvaluator())->eligible($node, (object) ['status' => 'no_managed_kubernetes_identity']));
    }
}
