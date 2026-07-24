<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeReconciliationPostgresReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_reconciliation_list_and_detail_reads_join_runtime_nodes_and_operations_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL reconciliation read proof runs only on the PostgreSQL integration target.');
        }

        $this->assertSame('uuid', $this->postgresColumnType('runtime_reconciliation_states', 'tenant_id'));
        $this->assertSame('character varying', $this->postgresColumnType('runtime_reconciliation_states', 'target_id'));
        $this->assertSame('uuid', $this->postgresColumnType('runtime_nodes', 'id'));
        $this->assertSame('uuid', $this->postgresColumnType('runtime_operations', 'tenant_id'));
        $this->assertSame('uuid', $this->postgresColumnType('runtime_operations', 'runtime_node_id'));

        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-pg-admin@utcp.local.test', 'runtime-reconciliation-pg');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-reconciliation-pg-other@utcp.local.test', 'runtime-reconciliation-pg-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-pg-node');
        $secondRuntimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-pg-second-node');
        $otherRuntimeNodeId = $this->createRuntimeNode($otherTenantId, 'runtime-reconciliation-pg-other-node');

        $operations = new RuntimeOperationRepository;
        $operationId = $operations->create(
            'runtime.node.inspect',
            'runtime_node',
            $runtimeNodeId,
            ['action' => 'inspect'],
            ExecutionContext::system(tenantId: $tenantId, origin: 'postgres-reconciliation-read-test'),
            runtimeNodeId: $runtimeNodeId,
        );
        $otherOperationId = $operations->create(
            'runtime.node.inspect',
            'runtime_node',
            $otherRuntimeNodeId,
            ['action' => 'inspect'],
            ExecutionContext::system(tenantId: $otherTenantId, origin: 'postgres-reconciliation-read-test'),
            runtimeNodeId: $otherRuntimeNodeId,
        );

        $reconciliations = new ReconciliationRepository;
        $stateId = $reconciliations->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 7);
        $secondStateId = $reconciliations->ensureTarget($tenantId, 'runtime_node', $secondRuntimeNodeId, 3);
        $otherStateId = $reconciliations->ensureTarget($otherTenantId, 'runtime_node', $otherRuntimeNodeId, 5);

        $claim = collect($reconciliations->claimDue('runtime-reconciliation-pg-test', 3, 60))
            ->firstWhere('id', $stateId);
        $this->assertNotNull($claim);
        $this->assertTrue($reconciliations->markResult($stateId, (string) $claim->lease_token, ReconciliationResult::blocked('runtime_node_unhealthy'), $operationId));

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'observed_generation' => 4,
            'updated_at' => CarbonImmutable::parse('2026-07-24T12:00:00Z'),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $secondStateId)->update([
            'updated_at' => CarbonImmutable::parse('2026-07-24T11:00:00Z'),
        ]);
        DB::table('runtime_reconciliation_states')->where('id', $otherStateId)->update([
            'last_operation_id' => $otherOperationId,
            'updated_at' => CarbonImmutable::parse('2026-07-24T13:00:00Z'),
        ]);

        $query = http_build_query([
            'runtime_node_id' => $runtimeNodeId,
            'status' => 'blocked',
            'runtime_operation_id' => strtoupper($operationId),
            'per_page' => 1,
        ]);

        $list = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame([$stateId], array_column($list['runtime_reconciliations'], 'id'));
        $this->assertSame([
            'page' => 1,
            'per_page' => 1,
            'total' => 1,
            'has_more' => false,
        ], $list['pagination']);
        $this->assertSame($runtimeNodeId, $list['runtime_reconciliations'][0]['runtime_node']['id']);
        $this->assertSame($operationId, $list['runtime_reconciliations'][0]['runtime_operation']['id']);
        $this->assertTrue($list['runtime_reconciliations'][0]['has_drift']);

        $paginated = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?per_page=1')
            ->assertOk()
            ->json();

        $this->assertSame(2, $paginated['pagination']['total']);
        $this->assertTrue($paginated['pagination']['has_more']);
        $this->assertSame($stateId, $paginated['runtime_reconciliations'][0]['id']);

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$stateId)
            ->assertOk()
            ->json('runtime_reconciliation');

        $this->assertSame($stateId, $detail['id']);
        $this->assertSame(['type' => 'runtime_node', 'id' => $runtimeNodeId], $detail['target']);
        $this->assertSame($runtimeNodeId, $detail['runtime_node']['id']);
        $this->assertSame($operationId, $detail['runtime_operation']['id']);
        $this->assertSame('blocked', $detail['status']);
        $this->assertSame([
            'category' => 'blocked',
            'code' => 'runtime_node_unhealthy',
            'summary' => 'blocked:runtime_node_unhealthy',
            'occurred_at' => $detail['failure']['occurred_at'],
        ], $detail['failure']);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$otherStateId)
            ->assertNotFound();
    }

    public function test_runtime_reconciliation_noop_event_suppression_runs_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL reconciliation transition proof runs only on the PostgreSQL integration target.');
        }

        [, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-pg-noop-admin@utcp.local.test', 'runtime-reconciliation-pg-noop');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-pg-noop-node');
        $repository = new ReconciliationRepository;
        $stateId = $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);

        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'status' => 'waiting',
            'observed_generation' => null,
            'last_operation_id' => null,
            'blocked_reason' => null,
            'next_check_at' => now()->subSecond(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);

        $baseline = $this->runtimeReconciliationOutboxCount();

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $claim = $repository->claimDue('runtime-reconciliation-pg-noop-'.$cycle, 1, 60);

            $this->assertCount(1, $claim);
            $this->assertSame($stateId, $claim[0]->id);
            $this->assertDatabaseHas('runtime_reconciliation_states', [
                'id' => $stateId,
                'status' => 'waiting',
                'lease_owner' => 'runtime-reconciliation-pg-noop-'.$cycle,
            ]);
            $this->assertTrue($repository->markResult($stateId, (string) $claim[0]->lease_token, ReconciliationResult::waiting('stable', 0)));
        }

        $this->assertSame($baseline, $this->runtimeReconciliationOutboxCount());

        $firstClaim = $repository->claimDue('runtime-reconciliation-pg-fence-a', 1, 60)[0];
        DB::table('runtime_reconciliation_states')->where('id', $stateId)->update([
            'lease_expires_at' => now()->subSecond(),
            'next_check_at' => now()->subSecond(),
        ]);
        $secondClaim = $repository->claimDue('runtime-reconciliation-pg-fence-b', 1, 60)[0];

        $this->assertFalse($repository->markResult($stateId, (string) $firstClaim->lease_token, ReconciliationResult::converged(0)));
        $this->assertSame($baseline, $this->runtimeReconciliationOutboxCount());
        $this->assertTrue($repository->markResult($stateId, (string) $secondClaim->lease_token, ReconciliationResult::converged(0)));
        $this->assertSame($baseline + 1, $this->runtimeReconciliationOutboxCount());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $stateId)
            ->where('event_type', 'runtime_reconciliation.converged')
            ->count());

        $beforeRollback = $this->runtimeReconciliationOutboxCount();

        try {
            DB::transaction(function () use ($repository, $tenantId): void {
                $repository->ensureTarget($tenantId, 'conference', 'runtime-reconciliation-pg-rollback', 1);

                throw new \RuntimeException('rollback runtime reconciliation proof');
            });
            $this->fail('rolled back PostgreSQL reconciliation transaction did not throw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback runtime reconciliation proof', $exception->getMessage());
        }

        $this->assertDatabaseMissing('runtime_reconciliation_states', [
            'target_type' => 'conference',
            'target_id' => 'runtime-reconciliation-pg-rollback',
        ]);
        $this->assertSame($beforeRollback, $this->runtimeReconciliationOutboxCount());
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

    private function runtimeReconciliationOutboxCount(): int
    {
        return DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('event_type', 'like', 'runtime_reconciliation.%')
            ->count();
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
            'display_name' => 'Runtime Reconciliation PostgreSQL Test User',
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
            'name' => 'Runtime Reconciliation PostgreSQL Node',
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
            'labels' => json_encode(['purpose' => 'runtime-reconciliation-postgres-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        ]);

        return $runtimeNodeId;
    }
}
