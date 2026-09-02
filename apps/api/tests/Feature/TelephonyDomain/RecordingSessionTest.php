<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RecordingArtifactId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RecordingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_authority_is_durable_tenant_scoped_and_idempotent(): void
    {
        [$admin, $tenant] = $this->admin('authority');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:recording'])
            ->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');

        $first = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId], ['Idempotency-Key' => 'rma-a-start-1'])
            ->assertStatus(202)->json('data');
        $same = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId], ['Idempotency-Key' => 'rma-a-start-1'])
            ->assertStatus(202)->json('data');

        $this->assertSame($first['id'], $same['id']);
        $this->assertSame($tenant, DB::table('recording_sessions')->where('id', $first['id'])->value('tenant_id'));
        $this->assertSame('recording', $first['desired_state']);
        $this->assertSame('requested', $first['observed_state']);
        $this->assertNull($first['artifact']);
        $this->assertNull($first['start_operation_id']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'call.leg.start_recording')->count());
        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        app(\App\TelephonyDomain\RecordingSessionService::class)->reconcileForLeg($tenant, $legId, ExecutionContext::system(tenantId: $tenant, reason: 'test answer'));
        app(\App\TelephonyDomain\RecordingSessionService::class)->reconcileForLeg($tenant, $legId, ExecutionContext::system(tenantId: $tenant, reason: 'duplicate test answer'));
        $started = DB::table('recording_sessions')->where('id', $first['id'])->first();
        $this->assertNotNull($started->start_operation_id);
        $this->assertSame('call.leg.start_recording', DB::table('runtime_operations')->where('id', $started->start_operation_id)->value('operation_type'));
        $startPayload = json_decode((string) DB::table('runtime_operations')->where('id', $started->start_operation_id)->value('payload'), true);
        $this->assertSame('utcp:capture/'.(string) $first['id'], $startPayload['capture_ref']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'call.leg.start_recording')->count());
        $this->assertSame(1, DB::table('recording_sessions')->where('tenant_id', $tenant)->count());

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/operations', ['operation_type' => 'call.leg.start_recording', 'target_leg_id' => $legId])
            ->assertStatus(422);
        $this->assertSame(1, DB::table('recording_sessions')->where('tenant_id', $tenant)->count());
    }

    public function test_pending_intent_resolves_when_leg_terminates_before_answer(): void
    {
        [$admin, $tenant] = $this->admin('pre-answer-terminal');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:recording-terminal'])->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        $recording = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId])->assertStatus(202)->json('data');

        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'completed']);
        app(\App\TelephonyDomain\RecordingSessionService::class)->reconcileForLeg($tenant, $legId, ExecutionContext::system(tenantId: $tenant, reason: 'test terminal'));
        $failed = DB::table('recording_sessions')->where('id', $recording['id'])->first();
        $this->assertSame('failed', $failed->observed_state);
        $this->assertSame('recording_subject_terminated_before_start', $failed->failure_code);
        $this->assertNull($failed->start_operation_id);

        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        app(\App\TelephonyDomain\RecordingSessionService::class)->reconcileForLeg($tenant, $legId);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'call.leg.start_recording')->count());
    }

    public function test_terminal_start_operation_failure_projects_to_session(): void
    {
        [$admin, $tenant] = $this->admin('operation-failure');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:recording-failure'])->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        $recording = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId])->assertStatus(202)->json('data');
        $operationId = (string) $recording['start_operation_id'];
        DB::table('runtime_operations')->where('id', $operationId)->update(['status' => 'terminal_failed']);
        app(\App\TelephonyDomain\RecordingSessionService::class)->markOperationFailed($operationId, \App\ControlPlane\RuntimeOperations\FailureClass::Conflict, 'channel_not_in_stasis', 'Channel not in Stasis application', \App\ControlPlane\RuntimeOperations\OperationStatus::TerminalFailed);
        $failed = DB::table('recording_sessions')->where('id', $recording['id'])->first();
        $this->assertSame('failed', $failed->observed_state);
        $this->assertSame('channel_not_in_stasis', $failed->failure_code);
        $this->assertNotNull($failed->failed_at);
    }

    public function test_stop_intent_updates_canonical_session_and_creates_subordinate_operation(): void
    {
        [$admin, $tenant] = $this->admin('stop');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:recording-stop'])->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        $recording = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId], ['Idempotency-Key' => 'rma-a-stop-start'])->assertStatus(202)->json('data');

        $stopped = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/recordings/'.$recording['id'].'/stop', [], ['Idempotency-Key' => 'rma-a-stop-1'])
            ->assertStatus(202)->json('data');

        $this->assertSame('stopped', $stopped['desired_state']);
        $this->assertNotNull($stopped['stop_operation_id']);
        $this->assertSame('call.leg.stop_recording', DB::table('runtime_operations')->where('id', $stopped['stop_operation_id'])->value('operation_type'));
        $stopPayload = json_decode((string) DB::table('runtime_operations')->where('id', $stopped['stop_operation_id'])->value('payload'), true);
        $this->assertSame('utcp:capture/'.(string) $recording['id'], $stopPayload['capture_ref']);
        DB::table('call_legs')->where('id', $legId)->update(['observed_state' => 'answered']);
        app(\App\TelephonyDomain\RecordingSessionService::class)->reconcileForLeg($tenant, $legId);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'call.leg.start_recording')->count());
    }

    public function test_recording_sessions_and_controls_are_tenant_scoped(): void
    {
        [$admin, $tenant] = $this->admin('tenant-a');
        [$otherAdmin, $otherTenant] = $this->admin('tenant-b');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:tenant-a'])->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        $recording = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId], ['Idempotency-Key' => 'rma-a-tenant'])->assertStatus(202)->json('data');

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenant])
            ->getJson('/api/v1/calls/'.$call['id'].'/recordings/'.$recording['id'])->assertNotFound();
        $this->assertSame(0, DB::table('recording_sessions')->where('tenant_id', $otherTenant)->count());
    }

    public function test_recording_session_reads_expose_only_safe_artifact_metadata(): void
    {
        [$admin, $tenant] = $this->admin('artifact-api');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $call = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls', ['direction' => 'outbound', 'destination_ref' => 'opaque:artifact-api'])
            ->assertCreated()->json('data');
        $legId = (string) DB::table('call_legs')->where('call_id', $call['id'])->value('id');
        $recording = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/calls/'.$call['id'].'/recordings', ['target_leg_id' => $legId])
            ->assertStatus(202)->json('data');
        $nodeId = (string) DB::table('call_legs')->where('id', $legId)->value('runtime_node_id');
        DB::table('recording_artifacts')->insert([
            'id' => RecordingArtifactId::new()->value(),
            'tenant_id' => $tenant,
            'recording_session_id' => $recording['id'],
            'call_id' => $call['id'],
            'call_leg_id' => $legId,
            'runtime_node_id' => $nodeId,
            'capture_ref' => 'utcp:capture/'.$recording['id'],
            'state' => 'available',
            'media_format' => 'wav',
            'duration_ms' => 3000,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->actingAs($admin)->withSession($session)
            ->getJson('/api/v1/calls/'.$call['id'].'/recordings/'.$recording['id'])
            ->assertOk()->json('data');

        $this->assertSame('available', $data['artifact']['state']);
        $this->assertSame('wav', $data['artifact']['media_format']);
        $this->assertSame(3000, $data['artifact']['duration_ms']);
        $this->assertNotEmpty($data['artifact']['id']);
        $this->assertArrayHasKey('available_at', $data['artifact']);
        foreach (['capture_ref', 'runtime_node_id', 'path', 'size', 'checksum', 'storage'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['artifact']);
        }
    }

    public function test_recording_session_schema_keeps_subject_and_lifecycle_authority_explicit(): void
    {
        $this->assertTrue(Schema::hasTable('recording_sessions'));
        $this->assertTrue(Schema::hasColumns('recording_sessions', [
            'id', 'tenant_id', 'call_id', 'call_leg_id', 'conference_id',
            'desired_state', 'observed_state', 'start_operation_id', 'stop_operation_id',
            'failure_class', 'failure_code', 'failure_message', 'requested_at',
            'started_at', 'stopped_at', 'failed_at', 'created_at', 'updated_at',
        ]));
    }

    /** @return array{0:User,1:string} */
    private function admin(string $suffix): array
    {
        $user = User::query()->create(['id' => IdentityIds::new(), 'email' => 'rma-a-'.$suffix.'@utcp.local.test', 'normalized_email' => 'rma-a-'.$suffix.'@utcp.local.test', 'display_name' => 'RMA-A', 'password' => Hash::make('password'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1]);
        $tenant = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'rma-a-'.$suffix, 'display_name' => 'RMA-A '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => IdentityIds::new(), 'user_id' => $user->id, 'tenant_id' => $tenant, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $membershipId = (string) DB::table('tenant_memberships')->where('user_id', $user->id)->where('tenant_id', $tenant)->value('id');
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        $runtimeNodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert(['id' => $runtimeNodeId, 'tenant_id' => $tenant, 'name' => 'RMA runtime', 'slug' => 'rma-runtime-'.$suffix, 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_node_capabilities')->insert(['id' => IdentityIds::new(), 'runtime_node_id' => $runtimeNodeId, 'capability_key' => 'call.control', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $tenant];
    }
}
