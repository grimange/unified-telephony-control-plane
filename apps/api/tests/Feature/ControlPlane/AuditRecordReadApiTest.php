<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Shared\AuditRecordId;
use App\ControlPlane\Shared\CorrelationId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RequestId;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuditRecordReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_requires_identity_session_active_tenant_and_tenant_admin_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-list-admin@utcp.local.test', 'audit-list');
        $member = $this->createUser('audit-list-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-audit-denied');
        $this->appendAudit($tenantId, 'runtime_node.created', 'runtime_node', 'node-1');

        $this->assertTrue(app(AuthorizationService::class)->hasTenant($admin->id, $tenantId, 'tenant.memberships.manage'));
        $this->assertFalse(app(AuthorizationService::class)->hasTenant($member->id, $tenantId, 'tenant.memberships.manage'));

        $this->getJson('/api/v1/admin/audit-records')->assertUnauthorized();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1])
            ->getJson('/api/v1/admin/audit-records')
            ->assertStatus(409);

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records')
            ->assertForbidden();

        $admin->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records')
            ->assertUnauthorized();
    }

    public function test_list_contract_pagination_ordering_and_sensitive_exclusions(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-contract-admin@utcp.local.test', 'audit-contract');
        [, $otherTenantId] = $this->createTenantAdmin('audit-contract-other@utcp.local.test', 'audit-contract-other');
        $sameTimestamp = CarbonImmutable::parse('2026-07-24T10:00:00Z');
        $firstId = $this->appendAudit($tenantId, 'runtime_node.created', 'runtime_node', 'node-a', [
            'result' => 'succeeded',
            'password' => 'list-password',
            'authorization_header' => 'Bearer list-token',
            'request_body' => ['token' => 'body-token'],
        ], occurredAt: $sameTimestamp);
        $secondId = $this->appendAudit($tenantId, 'runtime_node.updated', 'runtime_node', 'node-b', [
            'status' => 'failed',
            'failure_code' => 'blocked',
            'cookie' => 'session-cookie',
        ], occurredAt: $sameTimestamp);
        $newestId = $this->appendAudit($tenantId, 'telephony.conference.created', 'conference', 'conference-1', [
            'result' => 'accepted',
            'safe' => 'visible',
        ], occurredAt: CarbonImmutable::parse('2026-07-24T11:00:00Z'));
        $this->appendAudit($otherTenantId, 'runtime_node.created', 'runtime_node', 'foreign-node');

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?per_page=2')
            ->assertOk()
            ->json();

        $this->assertSame([
            'page' => 1,
            'per_page' => 2,
            'total' => 3,
            'has_more' => true,
        ], $response['pagination']);
        $this->assertSame($newestId, $response['audit_records'][0]['id']);
        $equalTimestampIds = [$firstId, $secondId];
        rsort($equalTimestampIds, SORT_STRING);
        $this->assertSame($equalTimestampIds[0], $response['audit_records'][1]['id']);
        $this->assertSame([
            'id',
            'action',
            'actor',
            'subject',
            'outcome',
            'correlation_id',
            'request_id',
            'occurred_at',
            'created_at',
        ], array_keys($response['audit_records'][0]));

        $serialized = json_encode($response, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('list-password', $serialized);
        $this->assertStringNotContainsString('Bearer list-token', $serialized);
        $this->assertStringNotContainsString('body-token', $serialized);
        $this->assertStringNotContainsString('session-cookie', $serialized);
        $this->assertStringNotContainsString('foreign-node', $serialized);

        $empty = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?action=runtime_node.deleted')
            ->assertOk()
            ->json();

        $this->assertSame([], $empty['audit_records']);
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 0, 'has_more' => false], $empty['pagination']);
    }

    public function test_filters_are_validated_combined_and_tenant_scoped(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-filter-admin@utcp.local.test', 'audit-filter');
        [, $otherTenantId] = $this->createTenantAdmin('audit-filter-other@utcp.local.test', 'audit-filter-other');
        $actorId = IdentityIds::new();
        $correlationId = CorrelationId::new()->value();
        $requestId = RequestId::new()->value();
        $expectedId = $this->appendAudit($tenantId, 'runtime_node.created', 'runtime_node', 'node-filter', [
            'result' => 'succeeded',
        ], actorId: $actorId, correlationId: $correlationId, requestId: $requestId, occurredAt: CarbonImmutable::parse('2026-07-24T12:00:00Z'));
        $this->appendAudit($tenantId, 'runtime_node.updated', 'runtime_node', 'node-filter', occurredAt: CarbonImmutable::parse('2026-07-24T13:00:00Z'));
        $this->appendAudit($otherTenantId, 'runtime_node.created', 'runtime_node', 'node-filter', actorId: $actorId, correlationId: $correlationId, requestId: $requestId, occurredAt: CarbonImmutable::parse('2026-07-24T12:00:00Z'));

        $query = http_build_query([
            'actor_type' => 'user',
            'actor_id' => $actorId,
            'action' => 'runtime_node.created',
            'subject_type' => 'runtime_node',
            'subject_id' => 'node-filter',
            'correlation_id' => strtoupper($correlationId),
            'request_id' => strtoupper($requestId),
            'occurred_from' => '2026-07-24T11:00:00Z',
            'occurred_to' => '2026-07-24T12:30:00Z',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame([$expectedId], array_column($response['audit_records'], 'id'));

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?actor_type=bad actor')
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?correlation_id=not-a-correlation')
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?occurred_from=2026-07-25T00:00:00Z&occurred_to=2026-07-24T00:00:00Z')
            ->assertUnprocessable();
    }

    public function test_detail_is_tenant_scoped_bounded_and_metadata_safe(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-detail-admin@utcp.local.test', 'audit-detail');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('audit-detail-other@utcp.local.test', 'audit-detail-other');
        $member = $this->createUser('audit-detail-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-audit-detail-denied');
        $recordId = $this->appendAudit($tenantId, 'runtime_operation.failed', 'runtime_operation', 'operation-1', [
            'result' => 'failed',
            'failure_code' => 'provider_error',
            'password' => 'detail-password',
            'api_token' => 'detail-token',
            'cookie' => 'detail-cookie',
            'authorization' => 'Bearer detail-token',
            'stack_trace' => 'Stack trace line',
            'outbox_body' => ['secret' => 'outbox-secret'],
            'desired_state' => ['credential' => 'desired-secret'],
            'observed_state' => ['token' => 'observed-secret'],
            'safe' => 'visible',
        ], reason: 'routine operator review', actorId: $admin->id);
        $otherRecordId = $this->appendAudit($otherTenantId, 'runtime_operation.failed', 'runtime_operation', 'operation-foreign');

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$recordId)
            ->assertOk()
            ->json('audit_record');

        $this->assertSame($recordId, $detail['id']);
        $this->assertSame(['type' => 'user', 'id' => $admin->id], $detail['actor']);
        $this->assertSame(['type' => 'runtime_operation', 'id' => 'operation-1'], $detail['subject']);
        $this->assertSame(['status' => 'failed', 'code' => 'provider_error', 'summary' => 'failed:provider_error'], $detail['outcome']);
        $this->assertSame('routine operator review', $detail['reason']);
        $this->assertSame('visible', $detail['metadata']['safe']);

        $serialized = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['detail-password', 'detail-token', 'detail-cookie', 'Stack trace line', 'outbox-secret', 'desired-secret', 'observed-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        foreach (['password', 'api_token', 'cookie', 'authorization', 'stack_trace', 'outbox_body', 'desired_state', 'observed_state'] as $key) {
            $this->assertSame('[redacted]', $detail['metadata'][$key]);
        }

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$otherRecordId)
            ->assertNotFound();

        $this->actingAs($otherAdmin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson('/api/v1/admin/audit-records/'.$recordId)
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$recordId)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.AuditRecordId::new()->value())
            ->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/not-an-id')
            ->assertNotFound();
    }

    public function test_list_query_count_does_not_scale_with_audit_record_count(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-query-admin@utcp.local.test', 'audit-query');
        $this->appendAudit($tenantId, 'runtime_node.created', 'runtime_node', 'node-query-1');

        $smallCount = $this->queryCountForList($admin, $tenantId);

        for ($i = 0; $i < 8; $i++) {
            $this->appendAudit($tenantId, 'runtime_node.updated', 'runtime_node', 'node-query-'.$i);
        }

        $largeCount = $this->queryCountForList($admin, $tenantId);

        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(8, $largeCount);
    }

    public function test_audit_read_api_is_read_only_and_preserves_historical_references(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('audit-immutable-admin@utcp.local.test', 'audit-immutable');
        $recordId = $this->appendAudit($tenantId, 'runtime_node.deleted', 'runtime_node', 'deleted-node-reference', actorId: $admin->id);
        $before = (array) DB::table('control_plane_audit_records')->where('id', $recordId)->first();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records')
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$recordId)
            ->assertOk()
            ->assertJsonPath('audit_record.actor.id', $admin->id)
            ->assertJsonPath('audit_record.subject.id', 'deleted-node-reference');

        $this->assertSame($before, (array) DB::table('control_plane_audit_records')->where('id', $recordId)->first());

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/audit-records', ['action' => 'audit.created'])
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->deleteJson('/api/v1/admin/audit-records/'.$recordId)
            ->assertMethodNotAllowed();
    }

    private function queryCountForList(User $admin, string $tenantId): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?per_page=5')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function appendAudit(
        string $tenantId,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
        ?string $reason = null,
        ?string $actorId = null,
        ?string $correlationId = null,
        ?string $requestId = null,
        ?CarbonImmutable $occurredAt = null,
    ): string {
        $context = new ExecutionContext(
            requestId: $requestId !== null ? RequestId::fromString($requestId) : RequestId::new(),
            correlationId: $correlationId !== null ? CorrelationId::fromString($correlationId) : CorrelationId::new(),
            causationId: null,
            actorType: $actorId !== null ? 'user' : 'system',
            actorId: $actorId,
            tenantId: $tenantId,
            reason: $reason,
            origin: 'audit-read-test',
            occurredAt: $occurredAt ?? CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        );

        return app(AuditRepository::class)->append($context, $action, $subjectType, $subjectId, $metadata, $reason);
    }

    /**
     * @return array{0: User, 1: string}
     */
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
        $this->attachTenantRole($user->id, $tenantId, 'tenant-admin');

        return [$user, $tenantId];
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Audit Read Test User',
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
                'display_name' => 'Audit Read Denied',
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
}
