<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CallApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_api_is_tenant_scoped_and_uses_normalized_operations_and_derived_timeline(): void
    {
        [$admin, $tenant] = $this->admin('c6d-a');
        [$otherAdmin, $otherTenant] = $this->admin('c6d-b');
        $created = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:destination-1'])
            ->assertCreated()->json('data');

        $this->assertSame('outbound', $created['direction']);
        $this->assertSame('originating', $created['state']);
        $callId = $created['id'];
        $operation = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])
            ->postJson('/api/v1/calls/'.$callId.'/operations', ['operation_type' => 'call.leg.answer', 'target_leg_id' => DB::table('call_legs')->where('call_id', $callId)->value('id'), 'payload' => []], ['Idempotency-Key' => 'c6d-api-op-1'])
            ->assertAccepted()->json('data');
        $same = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])
            ->postJson('/api/v1/calls/'.$callId.'/operations', ['operation_type' => 'call.leg.answer', 'target_leg_id' => $operation['target']['id'], 'payload' => []], ['Idempotency-Key' => 'c6d-api-op-1'])
            ->assertAccepted()->json('data');
        $this->assertSame($operation['id'], $same['id']);

        $timeline = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])
            ->getJson('/api/v1/calls/'.$callId.'/timeline')->assertOk()->json();
        $this->assertGreaterThanOrEqual(2, $timeline['pagination']['total']);
        $this->assertStringNotContainsString('opaque:destination-1', json_encode($timeline, JSON_THROW_ON_ERROR));

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenant])
            ->getJson('/api/v1/calls/'.$callId)->assertNotFound();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('call_timeline_entries'));
    }

    public function test_view_only_user_cannot_submit_control_operation(): void
    {
        [$admin, $tenant] = $this->admin('c6d-permission');
        $call = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:test'])->assertCreated()->json('data');
        $viewer = $this->user('c6d-viewer');
        $this->attachRole($viewer->id, $tenant, 'tenant-call-viewer', ['telephony.calls.view']);
        $this->actingAs($viewer)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenant])->postJson('/api/v1/calls/'.$call['id'].'/operations', ['operation_type' => 'call.hangup'])->assertForbidden();
        $this->assertSame(1, DB::table('runtime_operations')->where('tenant_id', $tenant)->count());
    }

    /** @return array{0:User,1:string} */
    private function admin(string $suffix): array
    {
        $user = $this->user('c6d-'.$suffix.'@utcp.local.test');
        $tenant = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'c6d-'.$suffix, 'display_name' => 'C6D', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->attachRole($user->id, $tenant, 'tenant-admin');

        return [$user, $tenant];
    }

    private function user(string $email): User
    {
        return User::query()->create(['id' => IdentityIds::new(), 'email' => $email, 'normalized_email' => $email, 'display_name' => 'C6D', 'password' => Hash::make('password'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
    }

    /** @param list<string>|null $capabilities */
    private function attachRole(string $userId, string $tenantId, string $role, ?array $capabilities = null): void
    {
        if ($capabilities !== null) {
            DB::table('roles')->insert(['key' => $role, 'scope' => 'tenant', 'display_name' => $role, 'built_in' => false, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($capabilities as $capability) {
                DB::table('role_capabilities')->insert(['role_key' => $role, 'capability_key' => $capability]);
            }
        }
        $membership = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membership, 'user_id' => $userId, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membership, 'role_key' => $role, 'assigned_by_user_id' => null, 'created_at' => now()]);
    }
}
