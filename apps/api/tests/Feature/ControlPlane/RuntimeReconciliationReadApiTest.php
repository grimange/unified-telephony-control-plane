<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeReconciliationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_requires_identity_session_active_tenant_and_runtime_node_view_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $member = $this->createUser('runtime-reconciliation-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-reconciliation-denied');

        $this->assertTrue(app(AuthorizationService::class)->hasTenant($admin->id, $tenantId, 'runtime.nodes.view'));
        $this->assertFalse(app(AuthorizationService::class)->hasTenant($member->id, $tenantId, 'runtime.nodes.view'));

        $this->getJson('/api/v1/admin/runtime-reconciliations')->assertUnauthorized();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->getJson('/api/v1/admin/runtime-reconciliations')
            ->assertStatus(409);

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations')
            ->assertForbidden();

        $admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations')
            ->assertUnauthorized();
    }

    public function test_list_is_paginated_ordered_and_excludes_other_tenant_records(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-order-admin@utcp.local.test', 'runtime-reconciliation-order');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-reconciliation-other-admin@utcp.local.test', 'runtime-reconciliation-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-order-node');
        $sameTimestamp = CarbonImmutable::parse('2026-07-24T10:00:00Z');

        $old = $this->createReconciliation($tenantId, [
            'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'target_id' => $runtimeNodeId,
            'updated_at' => CarbonImmutable::parse('2026-07-24T09:00:00Z'),
        ]);
        $tieLow = $this->createReconciliation($tenantId, [
            'id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'target_id' => $runtimeNodeId,
            'desired_generation' => 2,
            'updated_at' => $sameTimestamp,
        ]);
        $tieHigh = $this->createReconciliation($tenantId, [
            'id' => 'cccccccccccccccccccccccccccccccc',
            'target_id' => $runtimeNodeId,
            'desired_generation' => 3,
            'updated_at' => $sameTimestamp,
        ]);
        $this->createReconciliation($otherTenantId, [
            'id' => 'dddddddddddddddddddddddddddddddd',
            'target_id' => $this->createRuntimeNode($otherTenantId, 'runtime-reconciliation-other-node'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T11:00:00Z'),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?per_page=2')
            ->assertOk()
            ->json();

        $this->assertSame([$tieHigh, $tieLow], array_column($response['runtime_reconciliations'], 'id'));
        $this->assertSame([
            'page' => 1,
            'per_page' => 2,
            'total' => 3,
            'has_more' => true,
        ], $response['pagination']);

        $secondPage = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?per_page=2&page=2')
            ->assertOk()
            ->json();

        $this->assertSame([$old], array_column($secondPage['runtime_reconciliations'], 'id'));
    }

    public function test_list_response_contract_is_explicit_and_omits_sensitive_fields(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-contract-admin@utcp.local.test', 'runtime-reconciliation-contract');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-contract-node');
        $operationId = $this->createOperation($tenantId, $runtimeNodeId, [
            'status' => OperationStatus::TerminalFailed->value,
            'last_failure_message' => 'stack trace /srv/app/.env SIP_PASSWORD=super-secret',
            'payload' => ['credential' => 'must-not-leak', 'raw_command' => 'provider secret command'],
        ]);
        $reconciliationId = $this->createReconciliation($tenantId, [
            'target_id' => $runtimeNodeId,
            'status' => 'blocked',
            'observed_generation' => 2,
            'desired_generation' => 3,
            'last_operation_id' => $operationId,
            'blocked_reason' => 'host password stack',
            'last_checked_at' => CarbonImmutable::parse('2026-07-24T10:10:00Z'),
        ]);

        $reconciliation = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations')
            ->assertOk()
            ->json('runtime_reconciliations.0');

        $this->assertSame([
            'id',
            'target',
            'runtime_node',
            'status',
            'desired_generation',
            'observed_generation',
            'has_drift',
            'attempt_count',
            'last_checked_at',
            'next_check_at',
            'last_operation_id',
            'runtime_operation',
            'failure',
            'created_at',
            'updated_at',
        ], array_keys($reconciliation));
        $this->assertSame($reconciliationId, $reconciliation['id']);
        $this->assertSame(['type' => 'runtime_node', 'id' => $runtimeNodeId], $reconciliation['target']);
        $this->assertSame([
            'id' => $runtimeNodeId,
            'name' => 'Runtime Reconciliation Node',
            'slug' => 'runtime-reconciliation-contract-node',
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
        ], $reconciliation['runtime_node']);
        $this->assertSame([
            'id' => $operationId,
            'operation_type' => 'runtime.node.inspect',
            'status' => OperationStatus::TerminalFailed->value,
            'created_at' => '2026-07-24T10:00:00.000000Z',
            'completed_at' => '2026-07-24T10:20:00.000000Z',
        ], $reconciliation['runtime_operation']);
        $this->assertTrue($reconciliation['has_drift']);
        $this->assertSame([
            'category' => 'blocked',
            'code' => 'redacted',
            'summary' => 'blocked:redacted',
            'occurred_at' => '2026-07-24T10:10:00.000000Z',
        ], $reconciliation['failure']);

        $serialized = json_encode($reconciliation, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-leak', $serialized);
        $this->assertStringNotContainsString('raw_command', $serialized);
        $this->assertStringNotContainsString('super-secret', $serialized);
        $this->assertStringNotContainsString('host password stack', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('stack trace', $serialized);
        $this->assertStringNotContainsString('lease_token', $serialized);
        $this->assertStringNotContainsString('"payload":', $serialized);
        $this->assertStringNotContainsString('outbox', $serialized);
    }

    public function test_list_filters_are_validated_tenant_scoped_and_composable(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-filter-admin@utcp.local.test', 'runtime-reconciliation-filter');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-reconciliation-filter-other@utcp.local.test', 'runtime-reconciliation-filter-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-filter-node');
        $otherRuntimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-filter-other-node');
        $operationId = $this->createOperation($tenantId, $runtimeNodeId);
        $matching = $this->createReconciliation($tenantId, [
            'target_id' => $runtimeNodeId,
            'status' => 'blocked',
            'last_operation_id' => $operationId,
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:30:00Z'),
        ]);
        $this->createReconciliation($tenantId, [
            'target_id' => $otherRuntimeNodeId,
            'status' => 'waiting',
            'updated_at' => CarbonImmutable::parse('2026-07-24T11:00:00Z'),
        ]);
        $this->createReconciliation($otherTenantId, [
            'target_id' => $this->createRuntimeNode($otherTenantId, 'runtime-reconciliation-filter-foreign-node'),
            'status' => 'blocked',
            'last_operation_id' => $operationId,
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:30:00Z'),
        ]);

        $query = http_build_query([
            'runtime_node_id' => $runtimeNodeId,
            'target_type' => 'runtime_node',
            'status' => 'blocked',
            'runtime_operation_id' => strtoupper($operationId),
            'updated_from' => '2026-07-24T10:30:00Z',
            'updated_to' => '2026-07-24T10:31:00Z',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame([$matching], array_column($response['runtime_reconciliations'], 'id'));
        $this->assertSame(1, $response['pagination']['total']);

        foreach ([
            'runtime_node_id=not-a-uuid',
            'target_type=adapter_specific',
            'status=not_a_status',
            'runtime_operation_id=not-opaque',
            'updated_from=not-a-date',
            'updated_from=2026-07-24T10%3A31%3A00Z&updated_to=2026-07-24T10%3A30%3A00Z',
        ] as $invalidQuery) {
            $this->actingAs($admin)
                ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
                ->getJson('/api/v1/admin/runtime-reconciliations?'.$invalidQuery)
                ->assertUnprocessable();
        }
    }

    public function test_detail_is_tenant_scoped_authorized_and_explicitly_safe(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-detail-admin@utcp.local.test', 'runtime-reconciliation-detail');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('runtime-reconciliation-detail-other@utcp.local.test', 'runtime-reconciliation-detail-other');
        $member = $this->createUser('runtime-reconciliation-detail-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-reconciliation-detail-denied');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-detail-node');
        $operationId = $this->createOperation($tenantId, $runtimeNodeId, [
            'payload' => ['secret' => 'runtime-secret', 'adapter_response' => ['private' => true]],
            'last_failure_message' => 'provider secret response',
        ]);
        $reconciliationId = $this->createReconciliation($tenantId, [
            'target_id' => $runtimeNodeId,
            'last_operation_id' => $operationId,
            'observed_generation' => 5,
            'desired_generation' => 5,
        ]);
        $otherReconciliationId = $this->createReconciliation($otherTenantId, [
            'target_id' => $this->createRuntimeNode($otherTenantId, 'runtime-reconciliation-detail-foreign-node'),
        ]);

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$reconciliationId)
            ->assertOk()
            ->json('runtime_reconciliation');

        $this->assertSame($reconciliationId, $detail['id']);
        $this->assertSame($runtimeNodeId, $detail['runtime_node']['id']);
        $this->assertSame($operationId, $detail['runtime_operation']['id']);
        $this->assertFalse($detail['has_drift']);
        $this->assertNull($detail['failure']);

        $serialized = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('runtime-secret', $serialized);
        $this->assertStringNotContainsString('adapter_response', $serialized);
        $this->assertStringNotContainsString('provider secret response', $serialized);
        $this->assertStringNotContainsString('"payload":', $serialized);
        $this->assertStringNotContainsString('audit', $serialized);
        $this->assertStringNotContainsString('outbox', $serialized);
        $this->assertStringNotContainsString('lease_token', $serialized);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$otherReconciliationId)
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$reconciliationId)
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.$reconciliationId)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/'.RuntimeOperationId::new()->value())
            ->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations/not-an-id')
            ->assertNotFound();
    }

    public function test_list_query_count_does_not_scale_with_reconciliation_count(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-query-admin@utcp.local.test', 'runtime-reconciliation-query');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-query-node');
        $this->createReconciliation($tenantId, ['target_id' => $runtimeNodeId]);

        $smallCount = $this->queryCountForList($admin, $tenantId);

        for ($i = 0; $i < 8; $i++) {
            $nodeId = $this->createRuntimeNode($tenantId, 'runtime-reconciliation-query-node-'.$i);
            $this->createReconciliation($tenantId, [
                'target_id' => $nodeId,
                'updated_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z')->addMinutes($i + 1),
            ]);
        }

        $largeCount = $this->queryCountForList($admin, $tenantId);

        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(8, $largeCount);
    }

    private function queryCountForList(User $admin, string $tenantId): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-reconciliations?per_page=5')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'runtime-reconciliation-admin@utcp.local.test', string $slug = 'runtime-reconciliation'): array
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
        $this->attachTenantRole($user->id, $tenantId, 'tenant-admin');

        return [$user, $tenantId];
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Runtime Reconciliation Test User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        if (str_ends_with($roleKey, '-denied')) {
            DB::table('roles')->insert([
                'key' => $roleKey,
                'scope' => 'tenant',
                'display_name' => 'Runtime Reconciliation Denied',
                'built_in' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('role_capabilities')->insert([
                'role_key' => $roleKey,
                'capability_key' => 'tenant.roles.view',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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

    private function createRuntimeNode(string $tenantId, string $slug): string
    {
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime Reconciliation Node',
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
            'labels' => json_encode(['purpose' => 'runtime-reconciliation-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        ]);

        return $runtimeNodeId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOperation(string $tenantId, string $runtimeNodeId, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? RuntimeOperationId::new()->value());
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-07-24T10:00:00Z');
        $status = (string) ($overrides['status'] ?? OperationStatus::Pending->value);
        $completedAt = $overrides['completed_at']
            ?? ($status === OperationStatus::TerminalFailed->value ? CarbonImmutable::parse('2026-07-24T10:20:00Z') : null);

        DB::table('runtime_operations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => $overrides['operation_type'] ?? 'runtime.node.inspect',
            'aggregate_type' => $overrides['aggregate_type'] ?? 'runtime_node',
            'aggregate_id' => $overrides['aggregate_id'] ?? $runtimeNodeId,
            'payload_version' => $overrides['payload_version'] ?? 1,
            'payload' => json_encode($overrides['payload'] ?? ['action' => 'inspect'], JSON_THROW_ON_ERROR),
            'status' => $status,
            'priority' => $overrides['priority'] ?? 100,
            'idempotency_key' => $overrides['idempotency_key'] ?? 'idempotency-'.$id,
            'correlation_id' => $overrides['correlation_id'] ?? RuntimeOperationId::new()->value(),
            'causation_id' => $overrides['causation_id'] ?? null,
            'request_id' => $overrides['request_id'] ?? RuntimeOperationId::new()->value(),
            'attempt_count' => $overrides['attempt_count'] ?? 0,
            'max_attempts' => $overrides['max_attempts'] ?? 3,
            'available_at' => $overrides['available_at'] ?? $createdAt,
            'expires_at' => $overrides['expires_at'] ?? null,
            'lease_owner' => $overrides['lease_owner'] ?? 'worker-secret-name',
            'lease_token' => $overrides['lease_token'] ?? 'ffffffffffffffffffffffffffffffff',
            'lease_expires_at' => $overrides['lease_expires_at'] ?? null,
            'last_failure_class' => $overrides['last_failure_class'] ?? null,
            'last_failure_code' => $overrides['last_failure_code'] ?? null,
            'last_failure_message' => $overrides['last_failure_message'] ?? null,
            'started_at' => $overrides['started_at'] ?? null,
            'completed_at' => $completedAt,
            'cancelled_at' => $overrides['cancelled_at'] ?? null,
            'created_at' => $createdAt,
            'updated_at' => $overrides['updated_at'] ?? $completedAt ?? $createdAt,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReconciliation(string $tenantId, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? RuntimeOperationId::new()->value());
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-07-24T10:05:00Z');

        DB::table('runtime_reconciliation_states')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'target_type' => $overrides['target_type'] ?? 'runtime_node',
            'target_id' => $overrides['target_id'] ?? (string) Str::uuid(),
            'desired_generation' => $overrides['desired_generation'] ?? 1,
            'observed_generation' => $overrides['observed_generation'] ?? null,
            'status' => $overrides['status'] ?? 'waiting',
            'last_checked_at' => $overrides['last_checked_at'] ?? null,
            'next_check_at' => $overrides['next_check_at'] ?? CarbonImmutable::parse('2026-07-24T10:30:00Z'),
            'last_operation_id' => $overrides['last_operation_id'] ?? null,
            'blocked_reason' => $overrides['blocked_reason'] ?? null,
            'attempt_count' => $overrides['attempt_count'] ?? 0,
            'lease_owner' => $overrides['lease_owner'] ?? 'lease-secret-owner',
            'lease_token' => $overrides['lease_token'] ?? 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'lease_expires_at' => $overrides['lease_expires_at'] ?? null,
            'created_at' => $createdAt,
            'updated_at' => $overrides['updated_at'] ?? $createdAt,
        ]);

        return $id;
    }
}
