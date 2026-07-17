<?php

namespace App\RuntimeRegistry;

use Illuminate\Support\Facades\DB;

final class RuntimeNodeHistoryService
{
    /**
     * @return array<string, mixed>
     */
    public function list(string $tenantId, string $runtimeNodeId, int $limit = 25, ?string $before = null): array
    {
        $node = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId)->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        $allowed = [
            'runtime_node.created',
            'runtime_node.updated',
            'runtime_node.endpoints_changed',
            'runtime_node.asterisk_ari_configuration_changed',
            'runtime_node.simulator_configuration_changed',
            'runtime_node.credential_rotated',
            'runtime_node.credential_retired',
            'runtime_node.capabilities_changed',
            'runtime_node.desired_state_changed',
        ];
        $limit = max(1, min(100, $limit));
        $query = DB::table('control_plane_audit_records')
            ->where('tenant_id', $tenantId)
            ->where('subject_type', 'runtime_node')
            ->where('subject_id', $runtimeNodeId)
            ->whereIn('action', $allowed)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1);
        if ($before !== null && $before !== '') {
            $query->where('occurred_at', '<', $before);
        }
        $rows = $query->get();
        $items = $rows->take($limit)->map(fn (object $row): array => $this->serialize($row))->all();

        return [
            'history' => $items,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $rows->count() > $limit,
                'next_before' => $rows->count() > $limit ? $items[array_key_last($items)]['timestamp'] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(object $row): array
    {
        $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);
        $data = is_array($metadata['data'] ?? null) ? $metadata['data'] : [];

        return [
            'id' => $row->id,
            'timestamp' => $row->occurred_at,
            'action' => $row->action,
            'actor' => $row->actor_type === 'user' ? 'user' : 'system',
            'summary' => $this->summary((string) $row->action, $data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summary(string $action, array $data): string
    {
        return match ($action) {
            'runtime_node.created' => 'Node created for '.$this->safe((string) ($data['adapter_key'] ?? 'runtime adapter')).'.',
            'runtime_node.updated' => 'Node identity or placement changed.',
            'runtime_node.endpoints_changed' => 'Endpoint '.$this->safe((string) ($data['change'] ?? 'changed')).'.',
            'runtime_node.asterisk_ari_configuration_changed' => 'Asterisk ARI adapter configuration changed.',
            'runtime_node.simulator_configuration_changed' => 'Simulator adapter configuration changed.',
            'runtime_node.credential_rotated' => 'Credential '.$this->safe((string) ($data['change'] ?? 'changed')).'.',
            'runtime_node.credential_retired' => 'Credential retired.',
            'runtime_node.capabilities_changed' => 'Declared capabilities changed.',
            'runtime_node.desired_state_changed' => 'Desired state changed from '.$this->safe((string) ($data['from'] ?? 'unknown')).' to '.$this->safe((string) ($data['to'] ?? 'unknown')).'.',
            default => 'Runtime node lifecycle event.',
        };
    }

    private function safe(string $value): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown', 0, 80);
    }
}
