<?php

namespace Tests\Unit\RuntimeRegistry;

use App\TelephonyDomain\CallState;
use PHPUnit\Framework\TestCase;

final class K5CInboundProjectionContractTest extends TestCase
{
    public function test_inbound_projection_contains_the_shared_capacity_and_topology_contract(): void
    {
        $migration = dirname(__DIR__, 3).'/database/migrations/2026_08_30_120000_create_k5c_placement_observation_projection.php';
        $sql = file_get_contents($migration);
        $this->assertIsString($sql);

        $this->assertStringContainsString('runtime_node_k5c_placement_observations', $sql);
        $this->assertStringContainsString('conference_runtime_bindings', $sql);
        $this->assertStringContainsString('call_legs', $sql);
        $this->assertStringContainsString('n.capacity_weight = 0', $sql);
        $this->assertStringContainsString('order by n.placement_priority asc, available_capacity desc, active_telephony_work asc, n.id asc', $sql);
        $this->assertStringContainsString('n.placement_region', $sql);
        $this->assertStringContainsString('n.placement_zone', $sql);

        $terminalStates = array_filter(CallState::cases(), static fn (CallState $state): bool => $state->terminal());
        foreach ($terminalStates as $state) {
            $this->assertStringContainsString("'{$state->value}'", $sql, "SQL projection must exclude canonical terminal state {$state->value}.");
        }
    }
}
