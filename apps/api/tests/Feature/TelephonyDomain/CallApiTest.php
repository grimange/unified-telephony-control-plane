<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\Models\User;
use App\TelephonyDomain\CallDomainService;
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

    public function test_additional_leg_uses_same_call_authority_and_idempotent_retry(): void
    {
        [$admin, $tenant] = $this->admin('c6d-legs');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:first'])
            ->assertCreated()->json('data');

        $first = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/legs', ['destination_ref' => 'opaque:second'], ['Idempotency-Key' => 'c6d-leg-1'])
            ->assertCreated()->json('data');
        $second = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/legs', ['destination_ref' => 'opaque:second'], ['Idempotency-Key' => 'c6d-leg-1'])
            ->assertCreated()->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($call['id'], $first['call_id']);
        $this->assertSame($tenant, DB::table('call_legs')->where('id', $first['id'])->value('tenant_id'));
        $this->assertSame('outbound', $first['direction']);
        $this->assertSame('destination', $first['role']);
        $this->assertSame(CallDomainService::runtimeChannelId($first['id']), $first['runtime_channel_id']);
        $this->assertSame(2, DB::table('call_legs')->where('call_id', $call['id'])->count());
        $this->assertSame(2, DB::table('runtime_operations')->where('tenant_id', $tenant)->where('operation_type', 'call.leg.originate')->count());
    }

    public function test_same_call_bridge_relationship_is_reachable_and_cross_call_is_unprocessable(): void
    {
        [$admin, $tenant] = $this->admin('c6d-bridge');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:first'])->assertCreated()->json('data');
        $second = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls/'.$call['id'].'/legs', ['destination_ref' => 'opaque:second'], ['Idempotency-Key' => 'c6d-bridge-leg'])->assertCreated()->json('data');
        $legs = [DB::table('call_legs')->where('call_id', $call['id'])->orderBy('created_at')->pluck('id')->first(), $second['id']];

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/operations', ['operation_type' => 'call.legs.bridge', 'leg_ids' => $legs], ['Idempotency-Key' => 'c6d-bridge-op'])
            ->assertAccepted();

        $otherCall = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:other'])->assertCreated()->json('data');
        $otherLeg = DB::table('call_legs')->where('call_id', $otherCall['id'])->value('id');
        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/operations', ['operation_type' => 'call.legs.bridge', 'leg_ids' => [$legs[0], $otherLeg]], ['Idempotency-Key' => 'c6d-cross-call-bridge'])
            ->assertStatus(422);
    }

    public function test_cross_tenant_bridge_relationship_is_unprocessable_without_leaking_the_other_leg(): void
    {
        [$admin, $tenant] = $this->admin('c6d-bridge-tenant-a');
        [$otherAdmin, $otherTenant] = $this->admin('c6d-bridge-tenant-b');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $otherSession = ['user_session_version' => 1, 'active_tenant_id' => $otherTenant];

        $call = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:tenant-a'])
            ->assertCreated()->json('data');
        $callLeg = DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        $otherCall = $this->actingAs($otherAdmin)->withSession($otherSession)
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:tenant-b'])
            ->assertCreated()->json('data');
        $otherLeg = DB::table('call_legs')->where('call_id', $otherCall['id'])->value('id');

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/operations', ['operation_type' => 'call.legs.bridge', 'leg_ids' => [$callLeg, $otherLeg]], ['Idempotency-Key' => 'c6d-cross-tenant-bridge'])
            ->assertStatus(422);
    }

    public function test_terminal_call_cannot_receive_an_additional_leg(): void
    {
        [$admin, $tenant] = $this->admin('c6d-terminal-leg');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:closed'])->assertCreated()->json('data');
        DB::table('calls')->where('id', $call['id'])->update(['observed_state' => 'completed']);

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/legs', ['destination_ref' => 'opaque:rejected'], ['Idempotency-Key' => 'c6d-terminal-leg'])
            ->assertStatus(422);
    }

    /** @return array{0:User,1:string} */
    private function admin(string $suffix): array
    {
        $user = $this->user('c6d-'.$suffix.'@utcp.local.test');
        $tenant = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'c6d-'.$suffix, 'display_name' => 'C6D', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $runtimeNodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId, 'tenant_id' => $tenant, 'name' => 'C6D runtime',
            'slug' => 'c6d-runtime-'.str_replace('-', '', $runtimeNodeId), 'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('runtime_node_capabilities')->insert([
            'id' => IdentityIds::new(), 'runtime_node_id' => $runtimeNodeId, 'capability_key' => 'call.control',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
