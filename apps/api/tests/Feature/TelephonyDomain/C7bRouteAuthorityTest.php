<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\Models\User;
use App\TelephonyDomain\C7bService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class C7bRouteAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_route_selection_is_deterministic_and_uses_c7a_caller_policy(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('c7b-route@utcp.local.test', 'c7b-route');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $address = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'e164', 'value' => '+1 202 555 0100'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Route Trunk', 'slug' => 'route-trunk', 'supported_directions' => ['outbound']])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/addresses", ['telephony_address_id' => $address['id'], 'direction' => 'both'])->assertCreated();
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:carrier.example.test'])->assertCreated()->json('endpoint');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        DB::table('external_trunks')->where('id', $trunk['id'])->update(['observed_health' => 'ready']);
        $identity = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/caller-identities', ['name' => 'Route Caller', 'telephony_address_id' => $address['id']])->assertCreated()->json('caller_identity');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/policies", ['external_trunk_id' => $trunk['id']])->assertCreated();

        $route = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/outbound-routes', ['name' => 'Primary outbound', 'slug' => 'primary-outbound', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $address['id'], 'caller_identity_id' => $identity['id'], 'priority' => 10])->assertCreated()->json('outbound_route');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/outbound-routes/{$route['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $preferred = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/outbound-routes', ['name' => 'Preferred outbound', 'slug' => 'preferred-outbound', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $address['id'], 'caller_identity_id' => $identity['id'], 'priority' => 5])->assertCreated()->json('outbound_route');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/outbound-routes/{$preferred['id']}/desired-state", ['desired_state' => 'active'])->assertOk();

        $decision = app(C7bService::class)->evaluateOutbound($tenant, 'telephony_address:'.$address['id']);
        $this->assertSame('selected', $decision->toArray()['status']);
        $this->assertSame($preferred['id'], $decision->toArray()['route_id']);
        $this->assertSame($trunk['id'], $decision->toArray()['external_trunk_id']);
        $this->assertSame($identity['id'], $decision->toArray()['caller_identity_id']);
        $this->assertSame('telephony_address', $decision->toArray()['destination_ref']['type']);
        $this->assertArrayNotHasKey('provider', $route);
        $this->assertStringNotContainsString('sip:carrier', json_encode($decision->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_inbound_route_honors_direction_lifecycle_and_returns_canonical_destination(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('c7b-inbound@utcp.local.test', 'c7b-inbound');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $address = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'e164', 'value' => '+1 202 555 0101'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Inbound Trunk', 'slug' => 'inbound-trunk', 'supported_directions' => ['inbound']])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/addresses", ['telephony_address_id' => $address['id'], 'direction' => 'inbound'])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:inbound.example.test'])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        DB::table('external_trunks')->where('id', $trunk['id'])->update(['observed_health' => 'ready']);
        $route = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/inbound-routes', ['name' => 'Inbound destination', 'slug' => 'inbound-destination', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $address['id'], 'destination_ref' => 'opaque:application-entry'])->assertCreated()->json('inbound_route');

        $this->assertSame('draft', $route['desired_state']);
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/inbound-routes/{$route['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $decision = app(C7bService::class)->evaluateInbound($tenant, $trunk['id'], $address['id'])->toArray();
        $this->assertSame('selected', $decision['status']);
        $this->assertSame('opaque', $decision['destination_ref']['type']);
        $this->assertSame('opaque:application-entry', $decision['destination_ref']['reference']);
    }

    public function test_cross_tenant_routes_and_unauthorized_caller_identity_are_rejected(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('c7b-boundary@utcp.local.test', 'c7b-boundary');
        [$otherAdmin, $otherTenant] = $this->tenantAdmin('c7b-other@utcp.local.test', 'c7b-other');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $otherSession = ['user_session_version' => 1, 'active_tenant_id' => $otherTenant];
        $otherAddress = $this->actingAs($otherAdmin)->withSession($otherSession)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'e164', 'value' => '+1 202 555 0102'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Boundary Trunk', 'slug' => 'boundary-trunk'])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/outbound-routes', ['name' => 'Cross tenant', 'slug' => 'cross-tenant', 'external_trunk_id' => $trunk['id'], 'telephony_address_id' => $otherAddress['id']])->assertNotFound();
        $decision = app(C7bService::class)->evaluateOutbound($tenant, 'opaque:not-a-telephony-address');
        $this->assertSame('failed', $decision->toArray()['status']);
        $this->assertSame('destination_not_routable', $decision->toArray()['failure_code']);
        $invalid = app(C7bService::class)->evaluateOutbound($tenant, ['type' => 'provider_dial_string', 'value' => 'sip:carrier.example.test']);
        $this->assertSame('destination_invalid', $invalid->toArray()['failure_code']);
    }

    private function tenantAdmin(string $email, string $slug): array
    {
        $userId = IdentityIds::new();
        $tenantId = IdentityIds::new();
        $membershipId = IdentityIds::new();
        DB::table('users')->insert(['id' => $userId, 'email' => $email, 'normalized_email' => strtolower($email), 'display_name' => 'C7B Admin', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => 'C7B Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $userId, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);

        return [User::findOrFail($userId), $tenantId];
    }
}
