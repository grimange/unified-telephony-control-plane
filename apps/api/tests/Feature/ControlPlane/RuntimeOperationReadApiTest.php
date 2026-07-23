<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\RuntimeOperations\FailureClass;
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

final class RuntimeOperationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_requires_identity_session_active_tenant_and_runtime_node_view_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $member = $this->createUser('runtime-operation-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-operation-denied');

        $this->assertTrue(app(AuthorizationService::class)->hasTenant($admin->id, $tenantId, 'runtime.nodes.view'));
        $this->assertFalse(app(AuthorizationService::class)->hasTenant($member->id, $tenantId, 'runtime.nodes.view'));

        $this->getJson('/api/v1/admin/runtime-operations')->assertUnauthorized();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->getJson('/api/v1/admin/runtime-operations')
            ->assertStatus(409);

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations')
            ->assertForbidden();

        $admin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations')
            ->assertUnauthorized();
    }

    public function test_list_is_paginated_ordered_and_excludes_other_tenant_records(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-order-admin@utcp.local.test', 'runtime-operation-order');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-operation-other-admin@utcp.local.test', 'runtime-operation-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId);
        $sameTimestamp = CarbonImmutable::parse('2026-07-23T10:00:00Z');

        $old = $this->createOperation($tenantId, [
            'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'runtime_node_id' => $runtimeNodeId,
            'created_at' => CarbonImmutable::parse('2026-07-23T09:00:00Z'),
        ]);
        $tieLow = $this->createOperation($tenantId, [
            'id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'runtime_node_id' => $runtimeNodeId,
            'created_at' => $sameTimestamp,
        ]);
        $tieHigh = $this->createOperation($tenantId, [
            'id' => 'cccccccccccccccccccccccccccccccc',
            'runtime_node_id' => $runtimeNodeId,
            'created_at' => $sameTimestamp,
        ]);
        $this->createOperation($otherTenantId, [
            'id' => 'dddddddddddddddddddddddddddddddd',
            'created_at' => CarbonImmutable::parse('2026-07-23T11:00:00Z'),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations?per_page=2')
            ->assertOk()
            ->json();

        $this->assertSame([$tieHigh, $tieLow], array_column($response['runtime_operations'], 'id'));
        $this->assertSame([
            'page' => 1,
            'per_page' => 2,
            'total' => 3,
            'has_more' => true,
        ], $response['pagination']);

        $secondPage = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations?per_page=2&page=2')
            ->assertOk()
            ->json();

        $this->assertSame([$old], array_column($secondPage['runtime_operations'], 'id'));
    }

    public function test_list_response_contract_is_explicit_and_omits_sensitive_fields(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-contract-admin@utcp.local.test', 'runtime-operation-contract');
        $runtimeNodeId = $this->createRuntimeNode($tenantId);
        $operationId = $this->createOperation($tenantId, [
            'runtime_node_id' => $runtimeNodeId,
            'status' => OperationStatus::TerminalFailed->value,
            'last_failure_class' => FailureClass::AuthorizationFailed->value,
            'last_failure_code' => 'adapter_denied',
            'last_failure_message' => 'stack trace /srv/app/.env SIP_PASSWORD=super-secret',
            'payload' => ['secret' => 'must-not-leak', 'raw_command' => 'provider secret command'],
        ]);

        $operation = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations')
            ->assertOk()
            ->json('runtime_operations.0');

        $this->assertSame([
            'id',
            'runtime_node_id',
            'runtime_node',
            'operation_type',
            'aggregate',
            'status',
            'attempt',
            'priority',
            'correlation_id',
            'failure',
            'available_at',
            'started_at',
            'completed_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ], array_keys($operation));
        $this->assertSame($operationId, $operation['id']);
        $this->assertSame('runtime.node.inspect', $operation['operation_type']);
        $this->assertSame(OperationStatus::TerminalFailed->value, $operation['status']);
        $this->assertSame([
            'class' => FailureClass::AuthorizationFailed->value,
            'code' => 'adapter_denied',
            'summary' => 'authorization_failed:adapter_denied',
            'occurred_at' => '2026-07-23T10:20:00.000000Z',
        ], $operation['failure']);
        $this->assertSame([
            'id' => $runtimeNodeId,
            'name' => 'Runtime Operations Node',
            'slug' => 'runtime-operations-node',
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
        ], $operation['runtime_node']);

        $serialized = json_encode($operation, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-leak', $serialized);
        $this->assertStringNotContainsString('raw_command', $serialized);
        $this->assertStringNotContainsString('super-secret', $serialized);
        $this->assertStringNotContainsString('stack trace', $serialized);
        $this->assertStringNotContainsString('lease_token', $serialized);
        $this->assertStringNotContainsString('idempotency_key', $serialized);
        $this->assertStringNotContainsString('"payload":', $serialized);
    }

    public function test_list_filters_are_validated_tenant_scoped_and_composable(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-filter-admin@utcp.local.test', 'runtime-operation-filter');
        [, $otherTenantId] = $this->createTenantAdmin('runtime-operation-filter-other@utcp.local.test', 'runtime-operation-filter-other');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'filter-node');
        $otherRuntimeNodeId = $this->createRuntimeNode($tenantId, 'filter-other-node');
        $correlationId = '11111111111111111111111111111111';
        $matching = $this->createOperation($tenantId, [
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => 'runtime.node.restore',
            'status' => OperationStatus::Running->value,
            'correlation_id' => $correlationId,
            'created_at' => CarbonImmutable::parse('2026-07-23T10:30:00Z'),
        ]);
        $this->createOperation($tenantId, [
            'runtime_node_id' => $otherRuntimeNodeId,
            'operation_type' => 'runtime.node.inspect',
            'status' => OperationStatus::Pending->value,
            'correlation_id' => '22222222222222222222222222222222',
            'created_at' => CarbonImmutable::parse('2026-07-23T11:00:00Z'),
        ]);
        $this->createOperation($otherTenantId, [
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => 'runtime.node.restore',
            'status' => OperationStatus::Running->value,
            'correlation_id' => $correlationId,
            'created_at' => CarbonImmutable::parse('2026-07-23T10:30:00Z'),
        ]);

        $query = http_build_query([
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => 'runtime.node.restore',
            'status' => OperationStatus::Running->value,
            'correlation_id' => strtoupper($correlationId),
            'created_from' => '2026-07-23T10:30:00Z',
            'created_to' => '2026-07-23T10:31:00Z',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame([$matching], array_column($response['runtime_operations'], 'id'));
        $this->assertSame(1, $response['pagination']['total']);

        foreach ([
            'runtime_node_id=not-a-uuid',
            'operation_type=test.operation.not-supported',
            'status=not_a_status',
            'correlation_id=not-opaque',
            'created_from=not-a-date',
            'created_from=2026-07-23T10%3A31%3A00Z&created_to=2026-07-23T10%3A30%3A00Z',
        ] as $invalidQuery) {
            $this->actingAs($admin)
                ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
                ->getJson('/api/v1/admin/runtime-operations?'.$invalidQuery)
                ->assertUnprocessable();
        }
    }

    public function test_detail_is_tenant_scoped_authorized_and_explicitly_safe(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-detail-admin@utcp.local.test', 'runtime-operation-detail');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('runtime-operation-detail-other@utcp.local.test', 'runtime-operation-detail-other');
        $member = $this->createUser('runtime-operation-detail-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-operation-detail-denied');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'detail-node');
        $operationId = $this->createOperation($tenantId, [
            'runtime_node_id' => $runtimeNodeId,
            'causation_id' => '33333333333333333333333333333333',
            'request_id' => '44444444444444444444444444444444',
            'expires_at' => CarbonImmutable::parse('2026-07-23T12:00:00Z'),
            'payload' => ['credential' => 'runtime-secret', 'adapter_response' => ['private' => true]],
            'last_failure_message' => 'provider secret response',
        ]);
        $otherOperationId = $this->createOperation($otherTenantId);
        $reconciliationId = RuntimeOperationId::new()->value();
        DB::table('runtime_reconciliation_states')->insert([
            'id' => $reconciliationId,
            'tenant_id' => $tenantId,
            'target_type' => 'runtime_node',
            'target_id' => $runtimeNodeId,
            'desired_generation' => 1,
            'observed_generation' => null,
            'status' => 'waiting',
            'next_check_at' => CarbonImmutable::parse('2026-07-23T12:30:00Z'),
            'last_operation_id' => $operationId,
            'attempt_count' => 0,
            'created_at' => CarbonImmutable::parse('2026-07-23T10:20:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-23T10:20:00Z'),
        ]);

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$operationId)
            ->assertOk()
            ->json('runtime_operation');

        $this->assertSame([
            'id',
            'runtime_node_id',
            'runtime_node',
            'operation_type',
            'aggregate',
            'status',
            'attempt',
            'priority',
            'correlation_id',
            'failure',
            'available_at',
            'started_at',
            'completed_at',
            'cancelled_at',
            'created_at',
            'updated_at',
            'payload_version',
            'causation_id',
            'request_id',
            'expires_at',
            'reconciliation',
        ], array_keys($detail));
        $this->assertSame($operationId, $detail['id']);
        $this->assertSame('33333333333333333333333333333333', $detail['causation_id']);
        $this->assertSame('44444444444444444444444444444444', $detail['request_id']);
        $this->assertSame([
            'id' => $reconciliationId,
            'target_type' => 'runtime_node',
            'target_id' => $runtimeNodeId,
            'status' => 'waiting',
        ], $detail['reconciliation']);

        $serialized = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('runtime-secret', $serialized);
        $this->assertStringNotContainsString('adapter_response', $serialized);
        $this->assertStringNotContainsString('provider secret response', $serialized);
        $this->assertStringNotContainsString('"payload":', $serialized);
        $this->assertStringNotContainsString('outbox', $serialized);
        $this->assertStringNotContainsString('lease_token', $serialized);

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$otherOperationId)
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$operationId)
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.$operationId)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-operations/'.RuntimeOperationId::new()->value())
            ->assertNotFound();
    }

    public function test_list_query_count_does_not_scale_with_operation_count(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-query-admin@utcp.local.test', 'runtime-operation-query');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, 'query-node');
        $this->createOperation($tenantId, ['runtime_node_id' => $runtimeNodeId]);

        $smallCount = $this->queryCountForList($admin, $tenantId);

        for ($i = 0; $i < 8; $i++) {
            $this->createOperation($tenantId, [
                'runtime_node_id' => $runtimeNodeId,
                'created_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z')->addMinutes($i + 1),
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
            ->getJson('/api/v1/admin/runtime-operations?per_page=5')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'runtime-operation-admin@utcp.local.test', string $slug = 'runtime-operation'): array
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
            'display_name' => 'Runtime Operation Test User',
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
                'display_name' => 'Runtime Operation Denied',
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

    private function createRuntimeNode(string $tenantId, string $slug = 'runtime-operations-node'): string
    {
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime Operations Node',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
            'configuration_version' => 1,
            'created_by' => null,
            'updated_by' => null,
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => json_encode(['purpose' => 'runtime-operation-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
        ]);

        return $runtimeNodeId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOperation(string $tenantId, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? RuntimeOperationId::new()->value());
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-07-23T10:10:00Z');
        $runtimeNodeId = $overrides['runtime_node_id'] ?? null;
        $status = (string) ($overrides['status'] ?? OperationStatus::Pending->value);
        $completedAt = $overrides['completed_at']
            ?? ($status === OperationStatus::TerminalFailed->value ? CarbonImmutable::parse('2026-07-23T10:20:00Z') : null);

        DB::table('runtime_operations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => $overrides['operation_type'] ?? 'runtime.node.inspect',
            'aggregate_type' => $overrides['aggregate_type'] ?? 'runtime_node',
            'aggregate_id' => $overrides['aggregate_id'] ?? ($runtimeNodeId ?? IdentityIds::new()),
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
}
