<?php

namespace Tests\Feature\RuntimeEngine;

use App\Identity\IdentityIds;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\RuntimeRegistry\RuntimeEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class ObservedCapabilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_snapshots_project_drift_without_mutating_declared_capabilities(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(['conference', 'recording', 'event.stream']);

        $this->project($tenantId, $nodeId, ['conference', 'event.stream'], '2026-08-08T10:00:00Z');
        $evidence = app(RuntimeEvidenceService::class)->show($tenantId, $nodeId);

        $this->assertSame(['conference', 'event.stream', 'recording'], $evidence['capabilities']['declared']);
        $this->assertSame(['conference', 'event.stream'], $evidence['capabilities']['observed']);
        $this->assertSame(['recording'], $evidence['capabilities']['declared_not_observed']);
        $this->assertSame([], $evidence['capabilities']['observed_not_declared']);
        $this->assertSame(['conference', 'event.stream', 'recording'], DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->orderBy('capability_key')->pluck('capability_key')->all());
    }

    public function test_observed_only_capabilities_remain_evidence_and_snapshot_replacement_removes_stale_keys(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(['conference']);

        $this->project($tenantId, $nodeId, ['conference', 'transcoding'], '2026-08-08T10:00:00Z');
        $this->project($tenantId, $nodeId, ['conference', 'event.stream'], '2026-08-08T10:01:00Z');

        $evidence = app(RuntimeEvidenceService::class)->show($tenantId, $nodeId);
        $this->assertSame(['conference', 'event.stream'], $evidence['capabilities']['observed']);
        $this->assertSame(['event.stream'], $evidence['capabilities']['observed_not_declared']);
        $this->assertDatabaseMissing('runtime_node_observed_capabilities', ['runtime_node_id' => $nodeId, 'capability_key' => 'transcoding']);
    }

    public function test_replay_and_older_snapshot_are_idempotently_ignored(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(['conference']);

        $this->project($tenantId, $nodeId, ['conference', 'event.stream'], '2026-08-08T10:01:00Z', 'newer');
        $this->project($tenantId, $nodeId, ['conference'], '2026-08-08T10:00:00Z', 'older');

        $snapshot = DB::table('runtime_node_observed_capability_snapshots')->where('runtime_node_id', $nodeId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(['conference', 'event.stream'], DB::table('runtime_node_observed_capabilities')->where('runtime_node_id', $nodeId)->orderBy('capability_key')->pluck('capability_key')->all());
        $this->assertSame(2, DB::table('runtime_node_observed_capabilities')->where('runtime_node_id', $nodeId)->count());

        $this->project($tenantId, $nodeId, ['conference', 'event.stream'], '2026-08-08T10:01:00Z', 'newer-replay');
        $this->assertSame(2, DB::table('runtime_node_observed_capabilities')->where('runtime_node_id', $nodeId)->count());
    }

    public function test_no_observation_is_unknown_and_authoritative_empty_snapshot_is_empty(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(['conference']);
        $before = app(RuntimeEvidenceService::class)->show($tenantId, $nodeId);
        $this->assertNull($before['capabilities']['observed']);
        $this->assertSame('unknown', $before['capabilities']['freshness']);

        $this->project($tenantId, $nodeId, [], '2026-08-08T10:00:00Z');
        $after = app(RuntimeEvidenceService::class)->show($tenantId, $nodeId);
        $this->assertSame([], $after['capabilities']['observed']);
        $this->assertSame(['conference'], $after['capabilities']['declared_not_observed']);
    }

    public function test_stale_runtime_keeps_observed_history_but_reports_stale_freshness_and_tenant_isolation(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode(['conference']);
        $this->project($tenantId, $nodeId, ['conference'], now()->subSeconds(600)->toISOString());

        $evidence = app(RuntimeEvidenceService::class)->show($tenantId, $nodeId);
        $this->assertSame(['conference'], $evidence['capabilities']['observed']);
        $this->assertSame('stale', $evidence['capabilities']['freshness']);
        $this->assertDatabaseHas('runtime_node_observed_capabilities', ['tenant_id' => $tenantId, 'runtime_node_id' => $nodeId, 'capability_key' => 'conference']);

        $otherTenant = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $otherTenant, 'slug' => 'other-capability', 'display_name' => 'Other', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(NotFoundHttpException::class);
        app(RuntimeEvidenceService::class)->show($otherTenant, $nodeId);
    }

    /** @param list<string> $declared */
    private function runtimeNode(array $declared): array
    {
        $tenantId = IdentityIds::new();
        $nodeId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'capability-'.substr($tenantId, 0, 8), 'display_name' => 'Capability Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'Capability Runtime', 'slug' => 'capability-'.substr($nodeId, 0, 8),
            'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'ready',
            'observed_at' => now(), 'configuration_version' => 1, 'placement_priority' => 100, 'capacity_weight' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($declared as $capability) {
            DB::table('runtime_node_capabilities')->insert(['id' => IdentityIds::new(), 'runtime_node_id' => $nodeId, 'capability_key' => $capability, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$tenantId, $nodeId];
    }

    /** @param list<string> $capabilities */
    private function project(string $tenantId, string $nodeId, array $capabilities, string $occurredAt, ?string $key = null): void
    {
        $catalog = app(AsteriskCatalog::class);
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, $catalog->adapterKey(), 'capability-test');
        $eventType = $catalog->eventType('unknown_event_observed');
        $accepted = $receipts->ingest($tenantId, $nodeId, $catalog->adapterKey(), $epoch, 'capability:'.($key ?? bin2hex(random_bytes(4))), $eventType, 1, [
            'observed_state' => 'ready', 'configuration_generation' => 1, 'occurred_at' => $occurredAt, 'capabilities' => $capabilities,
        ]);
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();
        $normalizer = new AsteriskAriEventNormalizer($catalog, $eventType);
        app(ProjectionService::class)->apply($receipt, $normalizer->normalize($receipt, json_decode((string) $receipt->sanitized_payload, true, 512, JSON_THROW_ON_ERROR)));
    }
}
