<?php

namespace Tests\Feature\ControlPlane;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RuntimeOperationId;
use App\Events\RuntimeNodeOperationalStateChanged;
use App\Events\RuntimeOperationOperationalStateChanged;
use App\Events\RuntimeReconciliationOperationalStateChanged;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Outbox\OperationalBroadcastBridge;
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeReconciliationRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
        ]);
        require base_path('routes/channels.php');
    }

    public function test_runtime_reconciliation_private_channel_authorization_uses_identity_session_tenant_and_runtime_node_view_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('runtime-reconciliation-realtime-other@utcp.local.test', 'runtime-reconciliation-realtime-other');
        $member = $this->createUser('runtime-reconciliation-realtime-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-reconciliation-realtime-denied');

        $this->assertTrue(app(AuthorizationService::class)->hasTenant($admin->id, $tenantId, 'runtime.nodes.view'));
        $this->assertFalse(app(AuthorizationService::class)->hasTenant($member->id, $tenantId, 'runtime.nodes.view'));

        $this->post('/api/broadcasting/auth', $this->authPayload($tenantId), ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($tenantId), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($otherTenantId), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($member)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($tenantId), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($admin)->withSession(['user_session_version' => 0, 'active_tenant_id' => $tenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($tenantId), ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $otherAdmin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($otherTenantId), ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_runtime_reconciliation_broadcast_event_is_metadata_only(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-24T01:02:03.000000Z');
        $event = new RuntimeReconciliationOperationalStateChanged(
            eventType: 'runtime_reconciliation.converged',
            aggregateId: 'reconciliation-1',
            runtimeReconciliationId: 'reconciliation-1',
            runtimeNodeId: 'runtime-node-1',
            runtimeOperationId: 'operation-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
        );

        $this->assertSame('runtime-reconciliation.operational-state.changed', $event->broadcastAs());
        $this->assertSame('private-tenant.tenant-1.runtime-reconciliations', $event->broadcastOn()->name);
        $this->assertTrue($event->afterCommit);

        $payload = $event->broadcastWith();
        $this->assertSame([
            'event_type' => 'runtime_reconciliation.converged',
            'aggregate_type' => 'runtime_reconciliation',
            'aggregate_id' => 'reconciliation-1',
            'runtime_reconciliation_id' => 'reconciliation-1',
            'tenant_id' => 'tenant-1',
            'occurred_at' => $occurredAt->toJSON(),
            'runtime_node_id' => 'runtime-node-1',
            'runtime_operation_id' => 'operation-1',
        ], $payload);
        $this->assertSame(
            ['aggregate_id', 'aggregate_type', 'event_type', 'occurred_at', 'runtime_node_id', 'runtime_operation_id', 'runtime_reconciliation_id', 'tenant_id'],
            array_keys(collect($payload)->sortKeys()->all()),
        );

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'desired',
            'observed',
            'generation',
            'status',
            'failure',
            'stack',
            'secret',
            'credential',
            'endpoint',
            'command',
            'provider',
            'payload',
            'outbox',
            'audit',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_repository_transition_produces_reconciliation_outbox_and_dispatcher_bridges_it(): void
    {
        Event::fake([
            RuntimeReconciliationOperationalStateChanged::class,
            RuntimeNodeOperationalStateChanged::class,
            RuntimeOperationOperationalStateChanged::class,
        ]);
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-producer-admin@utcp.local.test', 'runtime-reconciliation-producer');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $operationId = $this->createRuntimeOperation($tenantId, $runtimeNodeId);
        $repository = new ReconciliationRepository;

        $reconciliationId = $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);
        $claim = $repository->claimDue('runtime-reconciliation-producer-worker')[0];
        $this->assertTrue($repository->markResult(
            (string) $claim->id,
            (string) $claim->lease_token,
            ReconciliationResult::operationRequired('runtime.node.inspect', ['action' => 'inspect'], runtimeNodeId: $runtimeNodeId),
            $operationId,
        ));

        $rows = DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $reconciliationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['runtime_reconciliation.created', 'runtime_reconciliation.operation_required'],
            $rows->pluck('event_type')->sort()->values()->all(),
        );
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($reconciliationId, $payload['runtime_reconciliation_id']);
            $this->assertSame($runtimeNodeId, $payload['runtime_node_id']);
            $this->assertSame($tenantId, $row->tenant_id);
            $this->assertSame('pending', $row->dispatch_status);
            $this->assertArrayNotHasKey('status', $payload);
            $this->assertArrayNotHasKey('desired_generation', $payload);
            $this->assertArrayNotHasKey('observed_generation', $payload);
            if ($row->event_type === 'runtime_reconciliation.operation_required') {
                $this->assertSame($operationId, $payload['runtime_operation_id']);
            }
        }

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(2, $dispatcher->dispatchOnce('runtime-reconciliation-producer-dispatcher', 10));

        Event::assertDispatched(RuntimeReconciliationOperationalStateChanged::class, 2);
        Event::assertDispatched(
            RuntimeReconciliationOperationalStateChanged::class,
            fn (RuntimeReconciliationOperationalStateChanged $event): bool => $event->eventType === 'runtime_reconciliation.operation_required'
                && $event->aggregateId === $reconciliationId
                && $event->runtimeReconciliationId === $reconciliationId
                && $event->runtimeNodeId === $runtimeNodeId
                && $event->runtimeOperationId === $operationId
                && $event->tenantId === $tenantId,
        );
        Event::assertNotDispatched(RuntimeNodeOperationalStateChanged::class);
    }

    public function test_outbox_bridge_rejects_non_reconciliation_shapes(): void
    {
        Event::fake([RuntimeReconciliationOperationalStateChanged::class, RuntimeNodeOperationalStateChanged::class]);
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-reject-admin@utcp.local.test', 'runtime-reconciliation-reject');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $reconciliationId = RuntimeOperationId::new()->value();

        $wrongPrefixId = $this->appendOutboxEvent($tenantId, $reconciliationId, 'runtime_operation.status_changed', 'runtime_reconciliation', [
            'runtime_reconciliation_id' => $reconciliationId,
            'runtime_node_id' => $runtimeNodeId,
        ]);
        $wrongAggregateId = $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_reconciliation.converged', 'runtime_node', [
            'runtime_reconciliation_id' => $reconciliationId,
        ]);
        $mismatchId = $this->appendOutboxEvent($tenantId, $reconciliationId, 'runtime_reconciliation.converged', 'runtime_reconciliation', [
            'runtime_reconciliation_id' => RuntimeOperationId::new()->value(),
            'runtime_node_id' => $runtimeNodeId,
        ]);

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(3, $dispatcher->dispatchOnce('runtime-reconciliation-reject-worker', 10));

        Event::assertNotDispatched(RuntimeReconciliationOperationalStateChanged::class);
        Event::assertNotDispatched(RuntimeNodeOperationalStateChanged::class);
        foreach ([$wrongPrefixId, $wrongAggregateId, $mismatchId] as $outboxId) {
            $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $outboxId, 'dispatch_status' => 'dispatched']);
        }
    }

    public function test_rolled_back_reconciliation_transition_produces_no_broadcast(): void
    {
        Event::fake([RuntimeReconciliationOperationalStateChanged::class]);
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-rollback-admin@utcp.local.test', 'runtime-reconciliation-rollback');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $repository = new ReconciliationRepository;

        DB::beginTransaction();
        $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);
        DB::rollBack();

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(0, $dispatcher->dispatchOnce('runtime-reconciliation-rollback-worker', 10));
        Event::assertNotDispatched(RuntimeReconciliationOperationalStateChanged::class);
    }

    public function test_broadcast_failure_does_not_reverse_committed_reconciliation_transition(): void
    {
        Queue::fake();
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-failure-admin@utcp.local.test', 'runtime-reconciliation-failure');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $repository = new ReconciliationRepository;
        $reconciliationId = $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);
        $claim = $repository->claimDue('runtime-reconciliation-failure-worker')[0];
        $outboxId = DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $reconciliationId)
            ->where('event_type', 'runtime_reconciliation.created')
            ->value('id');

        $bridge = new class extends OperationalBroadcastBridge
        {
            public function dispatchForOutboxRow(object $row): bool
            {
                throw new \RuntimeException('synthetic runtime reconciliation broadcast bridge failure');
            }
        };

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository, $bridge);
        $this->assertSame(0, $dispatcher->dispatchOnce('runtime-reconciliation-failure-worker', 10));

        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'id' => $reconciliationId,
            'status' => 'waiting',
            'lease_token' => $claim->lease_token,
        ]);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'id' => $outboxId,
            'dispatch_status' => 'retry_scheduled',
            'last_failure_class' => 'internal_error',
            'last_failure_code' => 'queue_delivery_failed',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_idempotent_ensure_target_does_not_duplicate_reconciliation_outbox(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-reconciliation-idempotent-admin@utcp.local.test', 'runtime-reconciliation-idempotent');
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $repository = new ReconciliationRepository;

        $first = $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);
        $second = $repository->ensureTarget($tenantId, 'runtime_node', $runtimeNodeId, 1);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $first)
            ->where('event_type', 'runtime_reconciliation.created')
            ->count());
        $this->assertSame(0, DB::table('control_plane_outbox_messages')
            ->where('aggregate_type', 'runtime_reconciliation')
            ->where('aggregate_id', $first)
            ->where('event_type', 'runtime_reconciliation.drift_detected')
            ->count());
    }

    private function createRuntimeNode(string $tenantId, ?string $actorId): string
    {
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime Reconciliation Realtime Node',
            'slug' => 'runtime-reconciliation-realtime-'.Str::lower(Str::random(6)),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'configuration_version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => json_encode(['purpose' => 'runtime-reconciliation-realtime-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:00:00Z'),
        ]);

        return $runtimeNodeId;
    }

    private function createRuntimeOperation(string $tenantId, string $runtimeNodeId): string
    {
        $operationId = RuntimeOperationId::new()->value();
        DB::table('runtime_operations')->insert([
            'id' => $operationId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => 'runtime.node.inspect',
            'aggregate_type' => 'runtime_node',
            'aggregate_id' => $runtimeNodeId,
            'payload_version' => 1,
            'payload' => json_encode(['action' => 'inspect', 'secret' => 'must-not-broadcast'], JSON_THROW_ON_ERROR),
            'status' => OperationStatus::Pending->value,
            'priority' => 100,
            'idempotency_key' => 'idempotency-'.$operationId,
            'correlation_id' => RuntimeOperationId::new()->value(),
            'causation_id' => null,
            'request_id' => RuntimeOperationId::new()->value(),
            'attempt_count' => 0,
            'max_attempts' => 3,
            'available_at' => CarbonImmutable::parse('2026-07-24T10:10:00Z'),
            'expires_at' => null,
            'lease_owner' => 'worker-runtime-reconciliation-test',
            'lease_token' => 'ffffffffffffffffffffffffffffffff',
            'lease_expires_at' => null,
            'last_failure_class' => null,
            'last_failure_code' => null,
            'last_failure_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_at' => CarbonImmutable::parse('2026-07-24T10:10:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-24T10:10:00Z'),
        ]);

        return $operationId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendOutboxEvent(string $tenantId, string $aggregateId, string $eventType, string $aggregateType, array $payload): string
    {
        return (new OutboxRepository)->append(EventEnvelope::forAggregate(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            context: ExecutionContext::system(tenantId: $tenantId, origin: 'ui-d19-test'),
        ));
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'runtime-reconciliation-realtime-admin@utcp.local.test', string $slug = 'runtime-reconciliation-realtime'): array
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
            'display_name' => 'Runtime Reconciliation Realtime Test User',
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
                'display_name' => 'Runtime Reconciliation Realtime Denied',
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

    /**
     * @return array<string, string>
     */
    private function authPayload(string $tenantId): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => "private-tenant.{$tenantId}.runtime-reconciliations",
        ];
    }
}
