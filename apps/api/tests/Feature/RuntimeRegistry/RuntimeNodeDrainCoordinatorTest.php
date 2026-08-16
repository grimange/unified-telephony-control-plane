<?php

namespace Tests\Feature\RuntimeRegistry;

use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Reconciliation\RuntimeNodeDrainCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RuntimeNodeDrainCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_drain_progresses_from_remaining_work_to_drained_and_is_idempotent(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('complete@utcp.local.test', 'complete');
        $node = $this->createActiveNode($admin, $tenantId, 'drain-complete');
        $conference = $this->createConference($admin, $tenantId, $node['id'], 'drain-conference');

        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])->assertOk();
        $target = $this->drainTarget($node['id']);

        $result = app(RuntimeNodeDrainCoordinator::class)->evaluate($target);
        $this->assertSame('waiting', $result->status);
        $this->assertSame('draining', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->assertSame(1, DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('remaining_work'));

        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->update(['status' => 'retired', 'unbound_at' => now()]);
        $this->assertSame('converged', app(RuntimeNodeDrainCoordinator::class)->evaluate($target)->status);
        $this->assertSame('drained', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->assertSame('completed', DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('status'));

        $auditCount = DB::table('control_plane_audit_records')->where('action', 'runtime_node.desired_state_changed')->where('subject_id', $node['id'])->count();
        $this->assertSame('converged', app(RuntimeNodeDrainCoordinator::class)->evaluate($target)->status);
        $this->assertSame($auditCount, DB::table('control_plane_audit_records')->where('action', 'runtime_node.desired_state_changed')->where('subject_id', $node['id'])->count());
    }

    public function test_zero_work_completes_immediately_and_admin_cannot_assert_drained(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('zero@utcp.local.test', 'zero');
        $node = $this->createActiveNode($admin, $tenantId, 'zero-work');

        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'drained'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Runtime node can only become drained after the drain coordinator proves zero remaining work.');
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));

        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])->assertOk();
        $this->assertSame('converged', app(RuntimeNodeDrainCoordinator::class)->evaluate($this->drainTarget($node['id']))->status);
        $this->assertSame('drained', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
    }

    public function test_cancelled_drain_cannot_be_completed_by_stale_coordinator_and_drained_reactivates(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('cancel@utcp.local.test', 'cancel');
        $node = $this->createActiveNode($admin, $tenantId, 'cancel-drain');
        $conference = $this->createConference($admin, $tenantId, $node['id'], 'cancel-conference');

        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])->assertOk();
        $target = $this->drainTarget($node['id']);
        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->assertSame('cancelled', DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('status'));
        app(RuntimeNodeDrainCoordinator::class)->evaluate($target);
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));

        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->update(['status' => 'retired', 'unbound_at' => now()]);
        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])->assertOk();
        app(RuntimeNodeDrainCoordinator::class)->evaluate($this->drainTarget($node['id']));
        $this->assertSame('drained', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        $this->assertSame('active', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
    }

    public function test_timeout_is_visible_and_does_not_disable_or_destroy_existing_work(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('timeout@utcp.local.test', 'timeout');
        $node = $this->createActiveNode($admin, $tenantId, 'timeout-drain');
        $conference = $this->createConference($admin, $tenantId, $node['id'], 'timeout-conference');

        $this->asAdmin($admin, $tenantId)->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])->assertOk();
        DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->update(['deadline_at' => now()->subSecond()]);
        $result = app(RuntimeNodeDrainCoordinator::class)->evaluate($this->drainTarget($node['id']));

        $this->assertSame('waiting', $result->status);
        $this->assertSame('draining', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->assertSame('timed_out', DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('status'));
        $this->assertNotNull(DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('timed_out_at'));
        $this->assertDatabaseHas('conference_runtime_bindings', ['conference_id' => $conference['id'], 'runtime_node_id' => $node['id'], 'status' => 'active']);

        $evidence = $this->asAdmin($admin, $tenantId)->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/runtime-evidence")
            ->assertOk()->json('runtime_evidence.drain');
        $this->assertSame(1, $evidence['remaining_work']);
        $this->assertTrue($evidence['timed_out']);

        $timedOutAt = DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('timed_out_at');
        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->update(['status' => 'retired', 'unbound_at' => now()]);

        $this->assertSame('converged', app(RuntimeNodeDrainCoordinator::class)->evaluate($this->drainTarget($node['id']))->status);
        $this->assertSame('drained', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->assertSame('completed', DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('status'));
        $this->assertSame($timedOutAt, DB::table('runtime_node_drains')->where('runtime_node_id', $node['id'])->value('timed_out_at'));
    }

    private function createActiveNode(User $admin, string $tenantId, string $slug): array
    {
        $node = $this->asAdmin($admin, $tenantId)->postJson('/api/v1/admin/runtime-nodes', [
            'name' => ucfirst($slug), 'slug' => $slug, 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari',
            'placement_region' => 'local', 'placement_zone' => 'dev', 'placement_priority' => 100, 'capacity_weight' => 10,
        ])->assertCreated()->json('runtime_node');
        DB::table('runtime_nodes')->where('id', $node['id'])->update(['desired_state' => 'active', 'configuration_version' => 2]);

        return $node;
    }

    private function createConference(User $admin, string $tenantId, string $nodeId, string $slug): array
    {
        $id = IdentityIds::new();
        DB::table('conferences')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'slug' => $slug, 'display_name' => ucfirst($slug), 'runtime_node_id' => $nodeId,
            'desired_state' => 'open', 'observed_state' => 'ready', 'configuration_generation' => 1,
            'created_by' => $admin->id, 'updated_by' => $admin->id, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(), 'tenant_id' => $tenantId, 'conference_id' => $id, 'runtime_node_id' => $nodeId,
            'status' => 'active', 'bound_at' => now(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id];
    }

    private function drainTarget(string $nodeId): object
    {
        $target = DB::table('runtime_reconciliation_states')->where('target_type', 'runtime_node_drain')->where('target_id', $nodeId)->first();
        $this->assertNotNull($target);

        return $target;
    }

    private function asAdmin(User $admin, string $tenantId): self
    {
        return $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId]);
    }

    /** @return array{0: User, 1: string} */
    private function createTenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(), 'email' => $email, 'normalized_email' => $email, 'display_name' => 'Drain Admin',
            'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1,
        ]);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => ucfirst($slug), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_role_assignments')->insert(['id' => IdentityIds::new(), 'user_id' => $user->id, 'role_key' => 'platform-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $user->id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);

        return [$user, $tenantId];
    }
}
