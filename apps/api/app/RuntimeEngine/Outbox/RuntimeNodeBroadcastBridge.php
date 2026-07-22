<?php

namespace App\RuntimeEngine\Outbox;

use App\Events\RuntimeNodeOperationalStateChanged;
use Carbon\CarbonImmutable;

class RuntimeNodeBroadcastBridge
{
    public function dispatchForOutboxRow(object $row): bool
    {
        $eventType = (string) $row->event_type;
        $aggregateType = (string) $row->aggregate_type;
        $tenantId = $row->tenant_id;

        if ($aggregateType !== 'runtime_node' || ! str_starts_with($eventType, 'runtime_node.')) {
            return false;
        }

        if (! is_string($tenantId) || $tenantId === '') {
            return false;
        }

        event(new RuntimeNodeOperationalStateChanged(
            eventType: $eventType,
            runtimeNodeId: (string) $row->aggregate_id,
            tenantId: $tenantId,
            occurredAt: CarbonImmutable::parse((string) $row->occurred_at),
        ));

        return true;
    }
}
