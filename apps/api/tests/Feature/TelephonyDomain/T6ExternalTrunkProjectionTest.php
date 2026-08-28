<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\StableJson;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use App\TelephonyDomain\C7bService;
use App\TelephonyDomain\Projection\ExternalTrunkProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class T6ExternalTrunkProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_authority_converges_to_idempotent_provider_artifacts_and_route_execution_intent(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('t6-projection@utcp.local.test', 't6-projection');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $address = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'sip_uri', 'value' => 'sip:97001@38.146.161.46'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'T6 Carrier', 'slug' => 't6-carrier', 'supported_directions' => ['outbound']])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/addresses", ['telephony_address_id' => $address['id'], 'direction' => 'both'])->assertCreated();
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-secret-123'])->assertCreated()->json('credential_reference');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:carrier.example.test', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id']])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        DB::table('external_trunks')->where('id', $trunk['id'])->update(['observed_health' => 'ready']);
        $identity = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/caller-identities', ['name' => 'T6 Caller', 'telephony_address_id' => $address['id']])->assertCreated()->json('caller_identity');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/policies", ['external_trunk_id' => $trunk['id']])->assertCreated();
        $route = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/outbound-routes', ['name' => 'T6 outbound', 'slug' => 't6-outbound', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $address['id'], 'caller_identity_id' => $identity['id'], 'priority' => 10])->assertCreated()->json('outbound_route');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/outbound-routes/{$route['id']}/desired-state", ['desired_state' => 'active'])->assertOk();

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $dispatcher->dispatchOnce('t6-projection-worker', 100);
        $projection = app(ExternalTrunkProjectionService::class);
        $first = $projection->projectTenant($tenant);
        $second = $projection->projectTenant($tenant);

        $this->assertCount(2, $first);
        $this->assertSame(array_column($first, 'artifact_hash'), array_column($second, 'artifact_hash'));
        $this->assertDatabaseCount('external_trunk_projection_artifacts', 2);
        $artifact = json_decode((string) $first[1]['artifact'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('utcp.t6.projection.v2', $artifact['schema']);
        $this->assertSame($trunk['id'], $artifact['external_trunk_id']);
        $this->assertSame($credential['id'], $artifact['endpoints'][0]['credential_reference_id']);
        $this->assertSame($route['id'], $artifact['routes'][0]['route_id']);
        $this->assertSame($address['id'], $artifact['routes'][0]['address_id']);
        $this->assertSame('sip:97001@38.146.161.46', $artifact['routes'][0]['address']);
        $this->assertSame('97001', $artifact['routes'][0]['destination_user']);
        $this->assertStringNotContainsString('synthetic-secret-123', json_encode($artifact, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('utcp-', $artifact['provider_local_trunk_id']);

        $asterisk = $artifact['provider_representation'];
        $this->assertSame($trunk['id'], $asterisk['canonical_external_trunk_id']);
        $this->assertSame($artifact['provider_local_trunk_id'], $asterisk['endpoint_id']);
        $this->assertSame($artifact['provider_local_trunk_id'].'-aor', $asterisk['aor_id']);
        $this->assertSame($route['id'], $asterisk['route_correlations'][0]['route_id']);
        $this->assertSame($address['id'], $asterisk['route_correlations'][0]['telephony_address_id']);
        $this->assertSame($identity['id'], $asterisk['route_correlations'][0]['caller_identity_id']);
        $this->assertSame('telephony_address:'.$address['id'], $asterisk['route_correlations'][0]['destination_ref']);
        $this->assertArrayNotHasKey('password', $asterisk);
        $this->assertArrayNotHasKey('secret', $asterisk);

        $decision = app(C7bService::class)->evaluateOutbound($tenant, 'telephony_address:'.$address['id']);
        $intent = $projection->executionIntent($tenant, $decision, 'asterisk');
        $this->assertSame($route['id'], $intent['route_id']);
        $this->assertSame($trunk['id'], $decision->toArray()['external_trunk_id']);
        $this->assertSame('asterisk', $intent['provider']);
        $this->assertSame($artifact['schema'], $intent['artifact_schema']);
        $this->assertArrayNotHasKey('endpoint_uri', $intent);
        $this->assertStringNotContainsString('carrier.example.test', json_encode($intent, JSON_THROW_ON_ERROR));

        $replacement = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-secret-456'])->assertCreated()->json('credential_reference');
        $dispatcher->dispatchOnce('t6-credential-rotation-worker', 100);
        $rotated = app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        $rotatedArtifact = json_decode((string) $rotated[1]['artifact'], true, 512, JSON_THROW_ON_ERROR);
        $endpoint = DB::table('trunk_endpoints')->where('id', $artifact['endpoints'][0]['endpoint_id'])->first();
        $this->assertSame($replacement['id'], $endpoint->credential_reference_id);
        $this->assertSame($replacement['id'], $rotatedArtifact['endpoints'][0]['credential_reference_id']);
        $this->assertSame((int) $replacement['version'], $rotatedArtifact['endpoints'][0]['credential_version']);
        $this->assertSame($replacement['id'], $rotatedArtifact['provider_representation']['credential_reference_id']);
        $this->assertSame((int) $replacement['version'], $rotatedArtifact['provider_representation']['credential_version']);
        $this->assertDatabaseHas('trunk_credential_references', ['id' => $credential['id'], 'status' => 'retired']);
        $this->assertStringNotContainsString('synthetic-secret-', json_encode($rotatedArtifact, JSON_THROW_ON_ERROR));
    }

    public function test_disabled_and_retired_authority_is_cut_off_without_deleting_projection_evidence(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('t6-lifecycle@utcp.local.test', 't6-lifecycle');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Lifecycle Carrier', 'slug' => 'lifecycle-carrier'])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'disabled'])->assertOk();

        $rows = app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        $this->assertCount(2, $rows);
        $asteriskRow = collect($rows)->firstWhere('provider', 'asterisk');
        $this->assertNotNull($asteriskRow);
        $this->assertSame('removed', $asteriskRow['desired_state']);
        $artifact = json_decode((string) $asteriskRow['artifact'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('removed', $artifact['desired_state']);
        $this->assertNull($artifact['provider_representation']);
        $this->assertDatabaseHas('external_trunk_projection_artifacts', ['external_trunk_id' => $trunk['id'], 'desired_state' => 'removed', 'observed_state' => 'projected']);
    }

    public function test_postgresql_route_projection_selects_one_canonical_registration_route_and_fails_closed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The route view contract requires PostgreSQL JSONB and UUID semantics.');
        }

        [$admin, $tenant] = $this->tenantAdmin('t6-sql@utcp.local.test', 't6-sql');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $address = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'sip_uri', 'value' => 'sip:97001@38.146.161.46'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'SQL Carrier', 'slug' => 'sql-carrier', 'supported_directions' => ['outbound']])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/addresses", ['telephony_address_id' => $address['id'], 'direction' => 'outbound'])->assertCreated();
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'sql-user', 'secret' => 'sql-secret-123'])->assertCreated()->json('credential_reference');
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:38.146.161.46:5060', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:38.146.161.46:5060', 'registration_realm' => '38.146.161.46', 'registration_identity' => 'sql-user'])->assertCreated()->json('endpoint');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        DB::table('external_trunks')->where('id', $trunk['id'])->update(['observed_health' => 'ready']);
        $identity = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/caller-identities', ['name' => 'SQL Caller', 'telephony_address_id' => $address['id']])->assertCreated()->json('caller_identity');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/policies", ['external_trunk_id' => $trunk['id']])->assertCreated();
        $route = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/outbound-routes', ['name' => 'SQL outbound', 'slug' => 'sql-outbound', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $address['id'], 'caller_identity_id' => $identity['id'], 'priority' => 10])->assertCreated()->json('outbound_route');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/outbound-routes/{$route['id']}/desired-state", ['desired_state' => 'active'])->assertOk();

        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);

        $kamailioArtifactRow = DB::table('external_trunk_projection_artifacts')->where('external_trunk_id', $trunk['id'])->where('provider', 'kamailio')->first();
        $oldArtifact = json_decode((string) $kamailioArtifactRow->artifact, true, 512, JSON_THROW_ON_ERROR);
        $oldArtifact['schema'] = 'utcp.t6.projection.v1';
        foreach ($oldArtifact['routes'] as &$oldRoute) {
            unset($oldRoute['destination_user']);
        }
        unset($oldRoute);
        $oldEncoded = StableJson::encode($oldArtifact);
        DB::table('external_trunk_projection_artifacts')->where('id', $kamailioArtifactRow->id)->update([
            'artifact' => $oldEncoded,
            'artifact_hash' => hash('sha256', $oldEncoded),
        ]);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_28_130000_upgrade_external_trunk_projection_artifact_schema.php';
        $migration->up();

        $types = DB::selectOne('select pg_typeof(route.trunk_endpoint_id)::text as route_type, pg_typeof(registration.trunk_endpoint_id)::text as registration_type from kamailio_external_trunk_route_view route left join kamailio_external_trunk_registration_view registration on registration.trunk_endpoint_id = route.trunk_endpoint_id where route.trunk_endpoint_id = ? limit 1', [$endpoint['id']]);
        $this->assertSame('uuid', $types->route_type);
        $this->assertSame('uuid', $types->registration_type);

        $select = fn (string $endpointId, string $destination, string $direction = 'outbound') => DB::table('kamailio_external_trunk_route_view')->where('trunk_endpoint_id', $endpointId)->where('destination_user', $destination)->where('direction', $direction)->where('desired_state', 'active')->where('accept_new_calls', true);
        $row = $select($endpoint['id'], '97001')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, $select($endpoint['id'], '97001')->count());
        $this->assertSame('sip:38.146.161.46:5060', $row->endpoint_uri);
        $this->assertSame('sip:97001@38.146.161.46', $row->normalized_address);
        $this->assertSame('97001', $row->destination_user);
        $this->assertSame(0, $select(IdentityIds::new(), '97001')->count());
        $this->assertSame(0, $select($endpoint['id'], '97002')->count());
        $this->assertSame(0, $select($endpoint['id'], '97001', 'inbound')->count());

        DB::table('outbound_routes')->where('id', $route['id'])->update(['desired_state' => 'disabled']);
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        $this->assertSame(0, $select($endpoint['id'], '97001')->count());

        DB::table('external_trunk_projection_artifacts')->where('external_trunk_id', $trunk['id'])->where('provider', 'kamailio')->update(['desired_state' => 'disabled']);
        $this->assertSame(0, $select($endpoint['id'], '97001')->count());
    }

    private function tenantAdmin(string $email, string $slug): array
    {
        $userId = IdentityIds::new();
        $tenantId = IdentityIds::new();
        $membershipId = IdentityIds::new();
        DB::table('users')->insert(['id' => $userId, 'email' => $email, 'normalized_email' => strtolower($email), 'display_name' => 'T6 Admin', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => 'T6 Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $userId, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);

        return [User::findOrFail($userId), $tenantId];
    }
}
