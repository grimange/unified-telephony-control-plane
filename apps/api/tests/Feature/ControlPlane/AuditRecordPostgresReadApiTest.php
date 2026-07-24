<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Shared\CorrelationId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RequestId;
use App\Identity\IdentityIds;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuditRecordPostgresReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_list_and_detail_execute_against_postgres_with_safe_contract(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL audit read proof runs only on the PostgreSQL integration target.');
        }

        $this->assertSame('character', $this->postgresColumnType('control_plane_audit_records', 'id'));
        $this->assertSame('character varying', $this->postgresColumnType('control_plane_audit_records', 'tenant_id'));
        $this->assertSame('json', $this->postgresColumnType('control_plane_audit_records', 'metadata'));

        [$admin, $tenantId] = $this->createTenantAdmin('audit-pg-admin@utcp.local.test', 'audit-pg');
        [, $otherTenantId] = $this->createTenantAdmin('audit-pg-other@utcp.local.test', 'audit-pg-other');
        $correlationId = CorrelationId::new()->value();
        $requestId = RequestId::new()->value();
        $sameTimestamp = CarbonImmutable::parse('2026-07-24T09:00:00Z');

        $olderId = $this->appendAudit($tenantId, 'runtime_node.created', 'runtime_node', 'runtime-node-pg-a', [
            'result' => 'succeeded',
            'password' => 'pg-password',
        ], actorId: $admin->id, correlationId: $correlationId, requestId: $requestId, occurredAt: $sameTimestamp);
        $sameTimeId = $this->appendAudit($tenantId, 'runtime_node.updated', 'runtime_node', 'runtime-node-pg-b', [
            'status' => 'failed',
            'failure_code' => 'invalid_state',
            'request_body' => ['token' => 'pg-body-token'],
            'stack_trace' => 'pg stack trace',
            'safe' => 'visible',
        ], actorId: $admin->id, occurredAt: $sameTimestamp);
        $newestId = $this->appendAudit($tenantId, 'runtime_operation.completed', 'runtime_operation', 'runtime-operation-pg', [
            'result' => 'completed',
        ], occurredAt: CarbonImmutable::parse('2026-07-24T10:00:00Z'));
        $foreignId = $this->appendAudit($otherTenantId, 'runtime_node.created', 'runtime_node', 'foreign-runtime-node-pg');

        $filtered = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?'.http_build_query([
                'actor_type' => 'user',
                'actor_id' => $admin->id,
                'action' => 'runtime_node.created',
                'subject_type' => 'runtime_node',
                'subject_id' => 'runtime-node-pg-a',
                'correlation_id' => strtoupper($correlationId),
                'request_id' => strtoupper($requestId),
                'occurred_from' => '2026-07-24T08:00:00Z',
                'occurred_to' => '2026-07-24T09:30:00Z',
                'per_page' => 1,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame([$olderId], array_column($filtered['audit_records'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 1, 'total' => 1, 'has_more' => false], $filtered['pagination']);
        $this->assertSame(['type' => 'user', 'id' => $admin->id], $filtered['audit_records'][0]['actor']);
        $this->assertSame(['type' => 'runtime_node', 'id' => 'runtime-node-pg-a'], $filtered['audit_records'][0]['subject']);

        $paginated = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records?per_page=2')
            ->assertOk()
            ->json();

        $this->assertSame(3, $paginated['pagination']['total']);
        $this->assertTrue($paginated['pagination']['has_more']);
        $this->assertSame($newestId, $paginated['audit_records'][0]['id']);
        $equalTimestampIds = [$olderId, $sameTimeId];
        rsort($equalTimestampIds, SORT_STRING);
        $this->assertSame($equalTimestampIds[0], $paginated['audit_records'][1]['id']);

        $detail = $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$sameTimeId)
            ->assertOk()
            ->json('audit_record');

        $this->assertSame($sameTimeId, $detail['id']);
        $this->assertSame(['type' => 'runtime_node', 'id' => 'runtime-node-pg-b'], $detail['subject']);
        $this->assertSame(['status' => 'failed', 'code' => 'invalid_state', 'summary' => 'failed:invalid_state'], $detail['outcome']);
        $this->assertSame('visible', $detail['metadata']['safe']);

        $serialized = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['pg-password', 'pg-body-token', 'pg stack trace', 'foreign-runtime-node-pg'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }

        $this->actingAs($admin)
            ->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/audit-records/'.$foreignId)
            ->assertNotFound();

        $this->expectException(QueryException::class);
        DB::table('control_plane_audit_records')->where('id', $sameTimeId)->delete();
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
     * @param  array<string, mixed>  $metadata
     */
    private function appendAudit(
        string $tenantId,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
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
            reason: 'postgres audit read proof',
            origin: 'audit-postgres-read-test',
            occurredAt: $occurredAt ?? CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        );

        return app(AuditRepository::class)->append($context, $action, $subjectType, $subjectId, $metadata);
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
            'display_name' => 'Audit PostgreSQL Test User',
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
}
