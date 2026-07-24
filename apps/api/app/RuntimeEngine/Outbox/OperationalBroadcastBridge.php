<?php

namespace App\RuntimeEngine\Outbox;

use App\Events\ConferenceOperationalStateChanged;
use App\Events\RuntimeNodeOperationalStateChanged;
use App\Events\RuntimeOperationOperationalStateChanged;
use App\Events\RuntimeReconciliationOperationalStateChanged;
use Carbon\CarbonImmutable;

class OperationalBroadcastBridge
{
    public function dispatchForOutboxRow(object $row): bool
    {
        return $this->dispatchRuntimeNodeNotification($row)
            || $this->dispatchConferenceNotification($row)
            || $this->dispatchRuntimeOperationNotification($row)
            || $this->dispatchRuntimeReconciliationNotification($row);
    }

    private function dispatchRuntimeNodeNotification(object $row): bool
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

    private function dispatchConferenceNotification(object $row): bool
    {
        $eventType = (string) $row->event_type;
        $aggregateType = (string) $row->aggregate_type;
        $aggregateId = (string) $row->aggregate_id;
        $tenantId = $row->tenant_id;

        if (! in_array($aggregateType, ['conference', 'conference_participant'], true)) {
            return false;
        }

        if (
            ($aggregateType === 'conference' && ! str_starts_with($eventType, 'conference.'))
            || ($aggregateType === 'conference_participant' && ! str_starts_with($eventType, 'conference_participant.'))
        ) {
            return false;
        }

        if (! is_string($tenantId) || $tenantId === '') {
            return false;
        }

        $conferenceId = $aggregateType === 'conference' ? $aggregateId : $this->participantConferenceId($row);
        if ($conferenceId === null || $conferenceId === '') {
            return false;
        }

        event(new ConferenceOperationalStateChanged(
            eventType: $eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            conferenceId: $conferenceId,
            tenantId: $tenantId,
            occurredAt: CarbonImmutable::parse((string) $row->occurred_at),
        ));

        return true;
    }

    private function dispatchRuntimeOperationNotification(object $row): bool
    {
        $eventType = (string) $row->event_type;
        $aggregateType = (string) $row->aggregate_type;
        $aggregateId = (string) $row->aggregate_id;
        $tenantId = $row->tenant_id;

        if ($aggregateType !== 'runtime_operation' || ! str_starts_with($eventType, 'runtime_operation.')) {
            return false;
        }

        if (! is_string($tenantId) || $tenantId === '' || $aggregateId === '') {
            return false;
        }

        event(new RuntimeOperationOperationalStateChanged(
            eventType: $eventType,
            aggregateId: $aggregateId,
            runtimeOperationId: $aggregateId,
            runtimeNodeId: $this->runtimeOperationRuntimeNodeId($row),
            tenantId: $tenantId,
            occurredAt: CarbonImmutable::parse((string) $row->occurred_at),
        ));

        return true;
    }

    private function dispatchRuntimeReconciliationNotification(object $row): bool
    {
        $eventType = (string) $row->event_type;
        $aggregateType = (string) $row->aggregate_type;
        $aggregateId = (string) $row->aggregate_id;
        $tenantId = $row->tenant_id;

        if ($aggregateType !== 'runtime_reconciliation' || ! str_starts_with($eventType, 'runtime_reconciliation.')) {
            return false;
        }

        if (! is_string($tenantId) || $tenantId === '' || $aggregateId === '') {
            return false;
        }

        $runtimeReconciliationId = $this->runtimeReconciliationId($row);
        if ($runtimeReconciliationId === null || $runtimeReconciliationId !== $aggregateId) {
            return false;
        }

        event(new RuntimeReconciliationOperationalStateChanged(
            eventType: $eventType,
            aggregateId: $aggregateId,
            runtimeReconciliationId: $runtimeReconciliationId,
            runtimeNodeId: $this->runtimeReconciliationRuntimeNodeId($row),
            runtimeOperationId: $this->runtimeReconciliationRuntimeOperationId($row),
            tenantId: $tenantId,
            occurredAt: CarbonImmutable::parse((string) $row->occurred_at),
        ));

        return true;
    }

    private function participantConferenceId(object $row): ?string
    {
        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $conferenceId = $payload['conference_id'] ?? null;

        return is_string($conferenceId) ? $conferenceId : null;
    }

    private function runtimeOperationRuntimeNodeId(object $row): ?string
    {
        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $runtimeNodeId = $payload['runtime_node_id'] ?? null;

        return is_string($runtimeNodeId) && $runtimeNodeId !== '' ? $runtimeNodeId : null;
    }

    private function runtimeReconciliationId(object $row): ?string
    {
        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $runtimeReconciliationId = $payload['runtime_reconciliation_id'] ?? null;

        return is_string($runtimeReconciliationId) && $runtimeReconciliationId !== '' ? $runtimeReconciliationId : null;
    }

    private function runtimeReconciliationRuntimeNodeId(object $row): ?string
    {
        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $runtimeNodeId = $payload['runtime_node_id'] ?? null;

        return is_string($runtimeNodeId) && $runtimeNodeId !== '' ? $runtimeNodeId : null;
    }

    private function runtimeReconciliationRuntimeOperationId(object $row): ?string
    {
        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $runtimeOperationId = $payload['runtime_operation_id'] ?? null;

        return is_string($runtimeOperationId) && $runtimeOperationId !== '' ? $runtimeOperationId : null;
    }
}
