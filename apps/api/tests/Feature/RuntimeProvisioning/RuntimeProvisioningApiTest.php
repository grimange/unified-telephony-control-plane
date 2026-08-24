<?php

namespace Tests\Feature\RuntimeProvisioning;

use App\Identity\IdentityIds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RuntimeProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_target_is_bootstrapped_and_valid_asterisk_request_creates_one_draft_node_idempotently(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-admin@utcp.local.test', 'rnp');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];

        $target = $this->actingAs($admin)->withSession($session)
            ->getJson('/api/v1/admin/deployment-targets')
            ->assertOk()
            ->assertJsonCount(1, 'deployment_targets')
            ->assertJsonPath('deployment_targets.0.kind', 'local_kubernetes')
            ->json('deployment_targets.0');

        $this->assertSame([
            'cluster' => 'utcp-local',
            'context' => 'k3d-utcp-local',
            'namespace' => 'utcp-runtime',
        ], $target['configuration']);

        $created = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $target['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Managed Asterisk One',
                'slug' => 'managed-asterisk-one',
            ], ['Idempotency-Key' => 'rnp-provisioning-1'])
            ->assertAccepted()
            ->assertJsonPath('provisioning_request.status', 'requested')
            ->assertJsonPath('provisioning_request.runtime_node.desired_state', 'draft')
            ->json('provisioning_request');

        $this->assertSame([], $created['runtime_node']['endpoints']);
        $this->assertSame([], $created['runtime_node']['credentials']);
        $this->assertDatabaseHas('runtime_nodes', [
            'id' => $created['runtime_node']['id'],
            'tenant_id' => $tenantId,
            'desired_state' => 'draft',
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
        ]);
        $this->assertDatabaseCount('runtime_provisioning_requests', 1);

        $this->actingAs($admin)->withSession($session)
            ->getJson('/api/v1/admin/runtime-nodes')
            ->assertOk()
            ->assertJsonPath('runtime_nodes.0.management.mode', 'managed')
            ->assertJsonPath('runtime_nodes.0.management.provisioning_request.id', $created['id'])
            ->assertJsonPath('runtime_nodes.0.management.provisioning_request.deployment_target.kind', 'local_kubernetes')
            ->assertJsonMissingPath('runtime_nodes.0.management.provisioning_request.secret');

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $target['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Managed Asterisk One',
                'slug' => 'managed-asterisk-one',
            ], ['Idempotency-Key' => 'rnp-provisioning-1'])
            ->assertAccepted()
            ->assertJsonPath('provisioning_request.id', $created['id'])
            ->assertJsonPath('provisioning_request.runtime_node.id', $created['runtime_node']['id']);

        $this->assertDatabaseCount('runtime_provisioning_requests', 1);
        $this->assertDatabaseCount('runtime_nodes', 1);
        $this->assertDatabaseHas('control_plane_audit_records', [
            'action' => 'deployment_target.registered',
            'subject_type' => 'deployment_target',
        ]);
        $this->assertDatabaseHas('control_plane_audit_records', [
            'action' => 'runtime_provisioning.requested',
            'subject_type' => 'runtime_provisioning_request',
        ]);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'event_type' => 'runtime_provisioning.requested',
        ]);
    }

    public function test_runtime_node_management_projection_distinguishes_external_nodes_without_inferencing_from_adapter(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-external-projection@utcp.local.test', 'rnp-external-projection');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];

        $created = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-nodes', [
                'name' => 'External Asterisk',
                'slug' => 'external-asterisk',
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
            ], ['Idempotency-Key' => 'rnp-external-node-1'])
            ->assertCreated()
            ->json('runtime_node');

        $this->actingAs($admin)->withSession($session)
            ->getJson('/api/v1/admin/runtime-nodes/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('runtime_node.management.mode', 'external')
            ->assertJsonPath('runtime_node.management.provisioning_request', null)
            ->assertJsonMissingPath('runtime_node.management.secret');
    }

    public function test_managed_creation_derives_slug_and_duplicate_identifier_is_a_validation_error_without_orphans(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-slug-admin@utcp.local.test', 'rnp-slug');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $target['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Berlin Voice Node',
            ], ['Idempotency-Key' => 'rnp-derived-slug-1'])
            ->assertAccepted()
            ->assertJsonPath('provisioning_request.requested_slug', 'berlin-voice-node');

        $beforeAudit = DB::table('control_plane_audit_records')->where('action', 'runtime_provisioning.requested')->count();
        $beforeOutbox = DB::table('control_plane_outbox_messages')->where('event_type', 'runtime_provisioning.requested')->count();

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $target['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Berlin Voice Node',
            ], ['Idempotency-Key' => 'rnp-derived-slug-2'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A runtime with this name or identifier already exists.')
            ->assertJsonPath('errors.name.0', 'A runtime with this name or identifier already exists.');

        $this->assertDatabaseCount('runtime_nodes', 1);
        $this->assertDatabaseCount('runtime_provisioning_requests', 1);
        $this->assertSame($beforeAudit, DB::table('control_plane_audit_records')->where('action', 'runtime_provisioning.requested')->count());
        $this->assertSame($beforeOutbox, DB::table('control_plane_outbox_messages')->where('event_type', 'runtime_provisioning.requested')->count());
    }

    public function test_managed_generated_configuration_rejects_manual_mutation(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-authority-admin@utcp.local.test', 'rnp-authority');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $node = $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $target['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Managed Authority Node',
            ], ['Idempotency-Key' => 'rnp-authority-1'])
            ->assertAccepted()
            ->json('provisioning_request.runtime_node');

        $this->actingAs($admin)->withSession($session)
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/endpoints", [
                'purpose' => 'control', 'transport' => 'https', 'host' => 'managed.local', 'port' => 8089,
            ])->assertUnprocessable()->assertJsonPath('message', 'This runtime is managed by UTCP. Its generated runtime configuration cannot be edited manually.');
        $this->actingAs($admin)->withSession($session)
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/capabilities", ['capabilities' => ['event.stream']])
            ->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/credentials", [
                'credential_type' => 'ari-basic', 'identifier' => 'manual', 'secret' => 'not-allowed-secret',
            ])->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This runtime is managed by UTCP. Its generated runtime configuration cannot be edited manually.');
    }

    public function test_conflicting_idempotency_is_rejected_and_managed_freeswitch_is_accepted(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-validation-admin@utcp.local.test', 'rnp-validation');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $payload = [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'name' => 'Managed Asterisk',
            'slug' => 'managed-asterisk',
        ];

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp-conflict-1'])
            ->assertAccepted();
        $beforeAudit = DB::table('control_plane_audit_records')->where('action', 'runtime_provisioning.requested')->count();
        $beforeOutbox = DB::table('control_plane_outbox_messages')->where('event_type', 'runtime_provisioning.requested')->count();

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [...$payload, 'name' => 'Different Name'], ['Idempotency-Key' => 'rnp-conflict-1'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Idempotency key conflict.');

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', [...$payload, 'runtime_family' => 'freeswitch', 'adapter_key' => 'freeswitch-esl', 'name' => 'Managed FreeSWITCH', 'slug' => 'managed-freeswitch'], ['Idempotency-Key' => 'rnp-freeswitch-1'])
            ->assertAccepted();

        $this->assertSame($beforeAudit + 1, DB::table('control_plane_audit_records')->where('action', 'runtime_provisioning.requested')->count());
        $this->assertSame($beforeOutbox + 1, DB::table('control_plane_outbox_messages')->where('event_type', 'runtime_provisioning.requested')->count());
        $this->assertDatabaseCount('runtime_provisioning_requests', 2);
        $this->assertDatabaseCount('runtime_nodes', 2);
    }

    public function test_cross_tenant_target_and_request_are_not_available(): void
    {
        [$adminA, $tenantA] = $this->createTenantAdmin('rnp-a@utcp.local.test', 'rnp-a');
        [$adminB, $tenantB] = $this->createTenantAdmin('rnp-b@utcp.local.test', 'rnp-b');
        $sessionA = ['user_session_version' => 1, 'active_tenant_id' => $tenantA];
        $sessionB = ['user_session_version' => 1, 'active_tenant_id' => $tenantB];

        $targetB = $this->actingAs($adminB)->withSession($sessionB)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $this->actingAs($adminA)->withSession($sessionA)
            ->getJson("/api/v1/admin/deployment-targets/{$targetB['id']}")
            ->assertNotFound();

        $this->actingAs($adminA)->withSession($sessionA)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $targetB['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Cross Tenant',
                'slug' => 'cross-tenant',
            ], ['Idempotency-Key' => 'rnp-cross-tenant-1'])
            ->assertUnprocessable();

        $targetA = $this->actingAs($adminA)->withSession($sessionA)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $requestA = $this->actingAs($adminA)->withSession($sessionA)
            ->postJson('/api/v1/admin/runtime-provisioning', [
                'deployment_target_id' => $targetA['id'],
                'runtime_family' => 'asterisk',
                'adapter_key' => 'asterisk-ari',
                'name' => 'Tenant A Asterisk',
                'slug' => 'tenant-a-asterisk',
            ], ['Idempotency-Key' => 'rnp-tenant-a-1'])
            ->assertAccepted()
            ->json('provisioning_request');

        $this->actingAs($adminB)->withSession($sessionB)
            ->getJson("/api/v1/admin/runtime-provisioning/{$requestA['id']}")
            ->assertNotFound();
    }

    public function test_provisioning_requires_authorization_and_transaction_rolls_back_on_runtime_node_failure(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('rnp-transaction-admin@utcp.local.test', 'rnp-transaction');
        $member = $this->createUser('rnp-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-member');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/deployment-targets')->json('deployment_targets.0');
        $payload = [
            'deployment_target_id' => $target['id'],
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'name' => 'Transactional Asterisk',
            'slug' => 'transactional-asterisk',
        ];

        $this->actingAs($member)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp-member-1'])
            ->assertForbidden();

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp-transaction-1'])
            ->assertAccepted();

        $this->actingAs($admin)->withSession($session)
            ->postJson('/api/v1/admin/runtime-provisioning', $payload, ['Idempotency-Key' => 'rnp-transaction-2'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A runtime with this name or identifier already exists.');

        $this->assertDatabaseCount('runtime_provisioning_requests', 1);
        $this->assertDatabaseCount('runtime_nodes', 1);
    }

    private function createTenantAdmin(string $email, string $slug): array
    {
        $user = $this->createUser($email);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => ucfirst($slug).' Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return [$user, $tenantId];
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'RNP Test User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => $roleKey,
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
    }
}
