<?php

namespace App\Infrastructure\Kubernetes;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeRegistry\RuntimeRegistryService;
use App\RuntimeEngine\EngineIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HostMaintenanceService
{
    public function __construct(
        private readonly KubernetesInfrastructureClient $observation,
        private readonly KubernetesMaintenanceClient $kubernetes,
        private readonly RuntimeRegistryService $registry,
        private readonly AuditRepository $audit,
    ) {}

    /** @return array<string, mixed> */
    public function request(Request $request, string $nodeUid, ?string $reason = null): array
    {
        $host = collect($this->observation->listNodes())->first(fn ($node) => (string) data_get($node, 'metadata.uid') === $nodeUid);
        if (! is_array($host)) throw new KubernetesWorkloadClientException('node_not_observed', 'Kubernetes Node is not currently observed.');
        $nodeName = (string) data_get($host, 'metadata.name', '');
        if ($nodeName === '') throw new KubernetesWorkloadClientException('node_not_observed', 'Kubernetes Node name is unavailable.');
        $existing = DB::table('k5d_host_maintenances')->where('node_uid', $nodeUid)->whereIn('status', ['requested', 'blocked', 'draining_telephony', 'telephony_drained', 'cordoning', 'draining_kubernetes'])->first();
        if ($existing !== null) return $this->serialize($existing);
        $id = EngineIds::new();
        $now = now();
        DB::table('k5d_host_maintenances')->insert([
            'id' => $id, 'node_uid' => $nodeUid, 'node_name' => $nodeName, 'requested_by' => $request->user()?->id,
            'reason' => $reason, 'status' => 'requested', 'phase' => 'requested', 'runtime_node_ids' => null,
            'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->append(ExecutionContext::fromRequest($request), 'host_maintenance.requested', 'kubernetes_node', $nodeUid, ['node_name' => $nodeName], $reason);
        return $this->serialize(DB::table('k5d_host_maintenances')->where('id', $id)->first());
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return DB::table('k5d_host_maintenances')->whereNotIn('status', ['completed', 'failed', 'cancelled'])->orderBy('requested_at')->get()->map(fn ($row) => $this->serialize($row))->all();
    }

    public function reconcileDue(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('k5d_host_maintenances')) return;
        foreach (DB::table('k5d_host_maintenances')->whereNotIn('status', ['completed', 'failed', 'cancelled'])->orderBy('requested_at')->get() as $maintenance) {
            try { $this->reconcile($maintenance); } catch (KubernetesWorkloadClientException $exception) { $this->fail($maintenance, $exception->reason, $exception->getMessage()); } catch (\Throwable $exception) { $this->fail($maintenance, 'maintenance_reconciliation_failed', $exception->getMessage()); }
        }
    }

    /** @return array<string, mixed> */
    private function serialize(object $row): array
    {
        return ['id' => (string) $row->id, 'node_uid' => (string) $row->node_uid, 'node_name' => (string) $row->node_name, 'status' => (string) $row->status, 'phase' => (string) $row->phase, 'runtime_node_ids' => $row->runtime_node_ids === null ? [] : json_decode((string) $row->runtime_node_ids, true), 'failure_code' => $row->failure_code, 'failure_details' => $row->failure_details, 'requested_at' => $row->requested_at, 'telephony_drained_at' => $row->telephony_drained_at, 'cordoned_at' => $row->cordoned_at, 'completed_at' => $row->completed_at];
    }

    private function reconcile(object $maintenance): void
    {
        $node = $this->kubernetes->node((string) $maintenance->node_name);
        if ((string) data_get($node, 'metadata.uid') !== (string) $maintenance->node_uid) { $this->fail($maintenance, 'node_identity_mismatch', 'Observed Node UID does not match the maintenance target.'); return; }
        $host = collect($this->observation->listNodes())->first(fn ($item) => (string) data_get($item, 'metadata.uid') === (string) $maintenance->node_uid);
        if (! is_array($host)) { $this->fail($maintenance, 'kubernetes_node_not_observed', 'Maintenance target is no longer observed.'); return; }
        if ($maintenance->status === 'blocked') {
            // Resume from the last safe boundary after a retryable failure.
            $maintenance->status = $maintenance->cordoned_at === null ? 'draining_telephony' : 'draining_kubernetes';
        }
        $runtimeIds = [];
        // The visibility service's workload identity is authoritative; derive associations from observed UTCP Pods.
        $pods = $this->observation->listPods();
        $runtimeRows = DB::table('runtime_nodes')->get();
        $identities = app(\App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityResolver::class);
        foreach ($pods as $pod) {
            if ((string) data_get($pod, 'spec.nodeName') !== $maintenance->node_name) continue;
            $labels = data_get($pod, 'metadata.labels', []); if (! is_array($labels) || ($labels['app.kubernetes.io/part-of'] ?? null) !== 'utcp') continue;
            foreach ($runtimeRows as $runtime) { try { $identity = $identities->resolve($runtime); } catch (\Throwable) { continue; } if ($identity->namespace === (string) data_get($pod, 'metadata.namespace') && $identity->deployment === (string) ($labels['app.kubernetes.io/instance'] ?? '')) $runtimeIds[] = (string) $runtime->id; }
        }
        $runtimeIds = array_values(array_unique($runtimeIds)); sort($runtimeIds);
        DB::table('k5d_host_maintenances')->where('id', $maintenance->id)->update(['runtime_node_ids' => json_encode($runtimeIds), 'updated_at' => now()]);
        if ($maintenance->status === 'requested') {
            $this->transition($maintenance, 'draining_telephony', ['runtime_node_ids' => json_encode($runtimeIds)]);
            $maintenance->status = 'draining_telephony';
        }
        $allDrained = true;
        foreach ($runtimeIds as $runtimeId) {
            $runtime = DB::table('runtime_nodes')->where('id', $runtimeId)->first(); if ($runtime === null) continue;
            $context = ExecutionContext::system(reason: 'host maintenance', tenantId: (string) $runtime->tenant_id, origin: 'telephony-reconciler');
            if ($runtime->desired_state === 'active') { $this->registry->beginDrain($context, (string) $runtime->tenant_id, $runtimeId); $allDrained = false; continue; }
            $desiredState = DB::table('runtime_nodes')->where('id', $runtimeId)->value('desired_state');
            if ($desiredState !== 'drained') $allDrained = false;
        }
        if (! $allDrained) return;
        if ($maintenance->status === 'draining_telephony') {
            $this->transition($maintenance, 'telephony_drained', ['telephony_drained_at' => now()]);
            $this->transition($maintenance, 'cordoning', ['telephony_drained_at' => now()]);
            $this->kubernetes->cordon($maintenance->node_name);
            $this->transition($maintenance, 'draining_kubernetes', ['cordoned_at' => now()]);
            return;
        }
        if ($maintenance->status === 'cordoning') {
            $this->kubernetes->cordon($maintenance->node_name);
            $this->transition($maintenance, 'draining_kubernetes', ['cordoned_at' => now()]);
            return;
        }
        $pods = $this->kubernetes->drainablePods($maintenance->node_name); if ($pods !== []) { foreach ($pods as $pod) $this->kubernetes->evict($pod['namespace'], $pod['name']); return; }
        $this->transition($maintenance, 'completed', ['completed_at' => now()]);
    }

    /** @param array<string, mixed> $extra */
    private function transition(object $maintenance, string $phase, array $extra = []): void
    {
        DB::table('k5d_host_maintenances')->where('id', $maintenance->id)->update(array_merge(['status' => $phase, 'phase' => $phase, 'updated_at' => now()], $extra));
        $this->audit->append(ExecutionContext::system(reason: 'host maintenance', origin: 'telephony-reconciler'), 'host_maintenance.'.str_replace('_', '.', $phase), 'kubernetes_node', (string) $maintenance->node_uid, ['node_name' => $maintenance->node_name]);
    }

    private function fail(object $maintenance, string $code, string $details): void
    {
        DB::table('k5d_host_maintenances')->where('id', $maintenance->id)->update(['status' => 'blocked', 'phase' => 'blocked', 'failure_code' => $code, 'failure_details' => mb_substr($details, 0, 1000), 'updated_at' => now()]);
        $this->audit->append(ExecutionContext::system(reason: 'host maintenance', origin: 'telephony-reconciler'), 'host_maintenance.blocked', 'kubernetes_node', (string) $maintenance->node_uid, ['node_name' => $maintenance->node_name, 'failure_code' => $code]);
    }
}
