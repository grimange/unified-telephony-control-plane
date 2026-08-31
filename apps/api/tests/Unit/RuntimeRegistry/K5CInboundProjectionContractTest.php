<?php

namespace Tests\Unit\RuntimeRegistry;

use App\TelephonyDomain\CallState;
use PHPUnit\Framework\TestCase;

final class K5CInboundProjectionContractTest extends TestCase
{
    public function test_inbound_projection_contains_the_shared_capacity_and_topology_contract(): void
    {
        $migration = dirname(__DIR__, 3).'/database/migrations/2026_08_31_100000_repair_k5c_inbound_capacity_and_ordering.php';
        $sql = file_get_contents($migration);
        $this->assertIsString($sql);

        $this->assertStringContainsString('runtime_node_k5c_placement_observations', $sql);
        $this->assertStringContainsString('conference_runtime_bindings', $sql);
        $this->assertStringContainsString('call_legs', $sql);
        $this->assertStringContainsString('n.capacity_weight = 0', $sql);
        $this->assertStringContainsString('n.capacity_weight = 0 or n.active_telephony_work < n.capacity_weight', $sql);
        $this->assertStringContainsString('order by n.placement_priority asc, available_capacity desc, active_telephony_work asc, n.id asc', $sql);
        $this->assertStringContainsString('n.placement_region', $sql);
        $this->assertStringContainsString('n.placement_zone', $sql);

        $terminalStates = array_filter(CallState::cases(), static fn (CallState $state): bool => $state->terminal());
        foreach ($terminalStates as $state) {
            $this->assertStringContainsString("'{$state->value}'", $sql, "SQL projection must exclude canonical terminal state {$state->value}.");
        }
    }

    public function test_kamailio_consumes_the_complete_k5c_ordering_tuple(): void
    {
        $config = file_get_contents(dirname(__DIR__, 5).'/infrastructure/kubernetes/base/platform/kamailio-configmap.yaml');
        $this->assertIsString($config);

        $this->assertStringContainsString(
            'select runtime_node_id, sip_target, available_capacity, active_telephony_work from kamailio_inbound_runtime_target_view',
            $config,
        );
        $this->assertStringContainsString(
            'order by placement_priority asc, available_capacity desc, active_telephony_work asc, runtime_node_id asc',
            $config,
        );
        $this->assertStringNotContainsString(
            'order by placement_priority, runtime_node_id',
            $config,
        );
    }
}
