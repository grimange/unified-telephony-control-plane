<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class C7aAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_c7a_authorities_are_tenant_scoped_idempotent_and_provider_neutral(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('c7a-admin@utcp.local.test', 'c7a');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];

        $address = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'e164', 'value' => '+1 (202) 555-0100'], ['Idempotency-Key' => 'c7a-address-key'])
            ->assertCreated()->assertJsonPath('telephony_address.value', '+12025550100')->json('telephony_address');
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'e164', 'value' => '+12025550100'], ['Idempotency-Key' => 'c7a-address-key'])->assertCreated()->assertJsonPath('telephony_address.id', $address['id']);

        $trunk = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/external-trunks', ['name' => 'Primary SIP', 'slug' => 'primary-sip', 'supported_directions' => ['inbound', 'outbound']], ['Idempotency-Key' => 'c7a-trunk-key'])
            ->assertCreated()->assertJsonPath('external_trunk.desired_state', 'draft')->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Primary SIP', 'slug' => 'primary-sip', 'supported_directions' => ['inbound', 'outbound']], ['Idempotency-Key' => 'c7a-trunk-key'])->assertCreated()->assertJsonPath('external_trunk.id', $trunk['id']);

        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'utcp', 'secret' => 'c7a-secret-value'], ['Idempotency-Key' => 'c7a-credential-key'])->assertCreated()->assertJsonMissing(['secret' => 'c7a-secret-value'])->json('credential_reference');
        $this->assertSame('c7a-secret-value', Crypt::decryptString((string) DB::table('trunk_credential_references')->where('id', $credential['id'])->value('encrypted_secret')));

        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:carrier.example.test', 'transport' => 'tls', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id']])->assertCreated()->json('endpoint');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/addresses", ['telephony_address_id' => $address['id'], 'direction' => 'outbound'])->assertCreated()->assertJsonPath('external_trunk.addresses.0.id', $address['id']);
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints/{$endpoint['id']}/desired-state", ['desired_state' => 'disabled'])->assertOk()->assertJsonPath('endpoint.desired_state', 'disabled');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints/{$endpoint['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk()->assertJsonPath('external_trunk.desired_state', 'active');

        $identity = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/caller-identities', ['name' => 'Primary Caller', 'telephony_address_id' => $address['id'], 'display_name' => 'UTCP'])->assertCreated()->json('caller_identity');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/caller-identities/{$identity['id']}/policies", ['external_trunk_id' => $trunk['id']])->assertCreated()->assertJsonPath('caller_identity.policies.0.external_trunk_id', $trunk['id']);

        $serialized = json_encode([DB::table('external_trunks')->get(), DB::table('trunk_credential_references')->get(), DB::table('caller_identities')->get()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('c7a-secret-value', $serialized);
        $this->assertArrayNotHasKey('provider', $trunk);
        $this->assertArrayNotHasKey('asterisk_endpoint', $trunk);
    }

    public function test_c7a_rejects_cross_tenant_relationships_and_unsafe_activation(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('c7a-boundary@utcp.local.test', 'c7a-boundary');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('c7a-other@utcp.local.test', 'c7a-other');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $otherSession = ['user_session_version' => 1, 'active_tenant_id' => $otherTenantId];

        $address = $this->actingAs($otherAdmin)->withSession($otherSession)->postJson('/api/v1/admin/telephony-addresses', ['address_type' => 'sip_uri', 'value' => 'sip:alice@other.example.test'])->assertCreated()->json('telephony_address');
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Boundary Trunk', 'slug' => 'boundary-trunk'])->assertCreated()->json('external_trunk');

        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/caller-identities', ['name' => 'Cross Tenant', 'telephony_address_id' => $address['id']])->assertNotFound();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/telephony-addresses')->assertOk()->assertJsonCount(0, 'telephony_addresses');
    }

    private function createTenantAdmin(string $email, string $slug): array
    {
        $userId = IdentityIds::new(); $tenantId = IdentityIds::new(); $membershipId = IdentityIds::new();
        DB::table('users')->insert(['id' => $userId, 'email' => $email, 'normalized_email' => strtolower($email), 'display_name' => 'C7A Admin', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => 'C7A Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $userId, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        return [\App\Models\User::findOrFail($userId), $tenantId];
    }
}
