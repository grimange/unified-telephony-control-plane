<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Identity\IdentityIds;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeOperationPostgresReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_operation_list_and_detail_reads_join_runtime_nodes_and_reconciliation_on_postgres_uuid_columns(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL UUID join proof runs only on the PostgreSQL integration target.');
        }

        $this->assertSame('uuid', $this->postgresColumnType('runtime_operations', 'tenant_id'));
        $this->assertSame('uuid', $this->postgresColumnType('runtime_operations', 'runtime_node_id'));

        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-pg-admin@utcp.local.test', 'runtime-operation-pg');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-operation-pg-other@utcp.local.test', 'runtime-operation-pg-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-operation-pg-node');
        $otherRuntimeNodeId = $this->createRuntimeNode($otherTenantId, 'runtime-operation-pg-other-node');

        $repository = new RuntimeOperationRepository;
        $operationId = $repository->create(
            'runtime.node.inspect',
            'runtime_node',
            $runtimeNodeId,
            ['action' => 'inspect'],
            ExecutionContext::system(tenantId: $tenantId, origin: 'postgres-read-test'),
            runtimeNodeId: $runtimeNodeId,
        );
        $otherOperationId = $repository->create(
            'runtime.node.inspect',
            'runtime_node',
            $otherRuntimeNodeId,
            ['action' => 'inspect'],
            ExecutionContext::system(tenantId: $otherTenantId, origin: 'postgres-read-test'),
            runtimeNodeId: $otherRuntimeNodeId,
        );

        $reconciliationId = RuntimeOperationId::new()->value();
        DB::table('runtime_reconciliation_states')->insert([
            'id' => $reconciliationId,
            'tenant_id' => $tenantId,
            'target_type' => 'runtime_node',
            'target_id' => $runtimeNodeId,
            'desired_generation' => 2,
            'observed_generation' => 1,
            'status' => 'waiting',
            'next_check_at' => CarbonImmutable::parse('2026-07-24T12:00:00Z'),
            'last_operation_id' => $operationId,
            'attempt_count' => 1,
            'created_at' => CarbonImmutable::parse('2026-07-24T11:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T11:00:00Z'),
        ]);

        $query = http_build_query([
            'runtime_node_id' => $runtimeNodeId,
            'status' => OperationStatus::Pending->value,
        ]);

        $list = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame([$operationId], array_column($list['runtime_operations'], 'id'));
        $this->assertSame($runtimeNodeId, $list['runtime_operations'][0]['runtime_node']['id']);
        $this->assertSame(1, $list['pagination']['total']);

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$operationId)
            ->assertOk()
            ->json('runtime_operation');

        $this->assertSame($operationId, $detail['id']);
        $this->assertSame($runtimeNodeId, $detail['runtime_node']['id']);
        $this->assertSame([
            'id' => $reconciliationId,
            'target_type' => 'runtime_node',
            'target_id' => $runtimeNodeId,
            'status' => 'waiting',
        ], $detail['reconciliation']);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$otherOperationId)
            ->assertNotFound();
    }

    private function postgresColumnType(string $table, string $column): string
    {
        return (string) DB::scalar(
            <<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = ?
              AND column_name = ?
            SQL,
            [$table, $column],
        );
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email, string $slug): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Runtime Operation PostgreSQL Test User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
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

    private function createRuntimeNode(string $tenantId, string $slug): string
    {
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime Operation PostgreSQL Node',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'configuration_version' => 1,
            'created_by' => null,
            'updated_by' => null,
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => json_encode(['purpose' => 'runtime-operation-postgres-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        ]);

        return $runtimeNodeId;
    }
}
