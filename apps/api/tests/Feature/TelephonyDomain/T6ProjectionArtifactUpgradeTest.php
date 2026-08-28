<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\StableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class T6ProjectionArtifactUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_v1_artifact_is_upgraded_without_a_business_event_and_hash_is_recomputed(): void
    {
        $artifact = [
            'schema' => 'utcp.t6.projection.v1',
            'provider' => 'kamailio',
            'external_trunk_id' => '00000000-0000-0000-0000-000000000001',
            'desired_state' => 'active',
            'accept_new_calls' => true,
            'routes' => [[
                'direction' => 'outbound',
                'route_id' => 'route-1',
                'priority' => 10,
                'address_id' => 'address-1',
                'address' => 'sip:97001@38.146.161.46',
            ]],
        ];
        $encoded = StableJson::encode($artifact);
        $this->projectionParents($artifact['external_trunk_id']);
        DB::table('external_trunk_projection_artifacts')->insert([
            'id' => '00000000-0000-0000-0000-000000000011',
            'tenant_id' => '00000000-0000-0000-0000-000000000002',
            'external_trunk_id' => $artifact['external_trunk_id'],
            'provider' => 'kamailio',
            'projection_key' => 'external-trunk:'.$artifact['external_trunk_id'],
            'desired_state' => 'active',
            'desired_generation' => 7,
            'artifact' => $encoded,
            'artifact_hash' => hash('sha256', $encoded),
            'observed_state' => 'projected',
            'projected_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_28_130000_upgrade_external_trunk_projection_artifact_schema.php';
        $migration->up();

        $row = DB::table('external_trunk_projection_artifacts')->first();
        $upgraded = json_decode((string) $row->artifact, true, 512, JSON_THROW_ON_ERROR);
        $expectedV2 = $artifact;
        $expectedV2['schema'] = 'utcp.t6.projection.v2';
        $expectedV2['routes'][0]['destination_user'] = '97001';
        $expected = StableJson::encode($expectedV2);

        $this->assertSame($expected, $row->artifact);
        $this->assertSame('utcp.t6.projection.v2', $upgraded['schema']);
        $this->assertSame('sip:97001@38.146.161.46', $upgraded['routes'][0]['address']);
        $this->assertSame('97001', $upgraded['routes'][0]['destination_user']);
        $this->assertSame(7, (int) $row->desired_generation);
        $this->assertSame(hash('sha256', $expected), $row->artifact_hash);
    }

    public function test_current_v2_artifact_is_idempotent_and_removed_artifact_is_upgraded_without_routes(): void
    {
        $current = ['schema' => 'utcp.t6.projection.v2', 'routes' => [['address' => 'sip:97001@38.146.161.46', 'destination_user' => '97001']]];
        $currentEncoded = StableJson::encode($current);
        $this->projectionParents('00000000-0000-0000-0000-000000000003');
        DB::table('external_trunks')->insert([
            'id' => '00000000-0000-0000-0000-000000000004', 'tenant_id' => '00000000-0000-0000-0000-000000000002', 'name' => 'Removed Carrier', 'slug' => 'removed-carrier', 'desired_state' => 'retired', 'observed_health' => 'unknown', 'configuration_version' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_trunk_projection_artifacts')->insert([
            'id' => '00000000-0000-0000-0000-000000000012', 'tenant_id' => '00000000-0000-0000-0000-000000000002', 'external_trunk_id' => '00000000-0000-0000-0000-000000000003', 'provider' => 'asterisk', 'projection_key' => 'current', 'desired_state' => 'active', 'desired_generation' => 3, 'artifact' => $currentEncoded, 'artifact_hash' => hash('sha256', $currentEncoded), 'observed_state' => 'projected', 'projected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_trunk_projection_artifacts')->insert([
            'id' => '00000000-0000-0000-0000-000000000013', 'tenant_id' => '00000000-0000-0000-0000-000000000002', 'external_trunk_id' => '00000000-0000-0000-0000-000000000004', 'provider' => 'kamailio', 'projection_key' => 'removed', 'desired_state' => 'removed', 'desired_generation' => 4, 'artifact' => StableJson::encode(['schema' => 'utcp.t6.projection.v1', 'desired_state' => 'removed']), 'artifact_hash' => hash('sha256', 'removed'), 'observed_state' => 'projected', 'projected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_28_130000_upgrade_external_trunk_projection_artifact_schema.php';
        $migration->up();
        $migration->up();

        $currentRow = DB::table('external_trunk_projection_artifacts')->where('id', '00000000-0000-0000-0000-000000000012')->first();
        $removedRow = DB::table('external_trunk_projection_artifacts')->where('id', '00000000-0000-0000-0000-000000000013')->first();
        $this->assertSame($currentEncoded, $currentRow->artifact);
        $this->assertSame(hash('sha256', $currentEncoded), $currentRow->artifact_hash);
        $this->assertSame('utcp.t6.projection.v2', json_decode((string) $removedRow->artifact, true, 512, JSON_THROW_ON_ERROR)['schema']);
        $this->assertSame('removed', json_decode((string) $removedRow->artifact, true, 512, JSON_THROW_ON_ERROR)['desired_state']);
        $this->assertSame(hash('sha256', (string) $removedRow->artifact), $removedRow->artifact_hash);
        $this->assertSame(4, (int) $removedRow->desired_generation);
    }

    private function projectionParents(string $trunkId): void
    {
        DB::table('tenants')->insert([
            'id' => '00000000-0000-0000-0000-000000000002', 'slug' => 'migration-test', 'display_name' => 'Migration Test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_trunks')->insert([
            'id' => $trunkId, 'tenant_id' => '00000000-0000-0000-0000-000000000002', 'name' => 'Migration Carrier', 'slug' => 'migration-carrier', 'desired_state' => 'active', 'observed_health' => 'ready', 'configuration_version' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
