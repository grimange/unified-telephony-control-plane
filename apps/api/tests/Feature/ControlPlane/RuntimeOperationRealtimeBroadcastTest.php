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
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Outbox\OperationalBroadcastBridge;
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeOperationRealtimeBroadcastTest extends TestCase
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

    public function test_runtime_operation_private_channel_authorization_uses_identity_session_tenant_and_runtime_node_view_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('runtime-operation-realtime-other@utcp.local.test', 'runtime-operation-realtime-other');
        $member = $this->createUser('runtime-operation-realtime-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-runtime-operation-realtime-denied');

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

    public function test_runtime_operation_broadcast_event_is_metadata_only(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-23T01:02:03.000000Z');
        $event = new RuntimeOperationOperationalStateChanged(
            eventType: 'runtime_operation.progressed',
            aggregateId: 'operation-1',
            runtimeOperationId: 'operation-1',
            runtimeNodeId: 'runtime-node-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
        );

        $this->assertSame('runtime-operation.operational-state.changed', $event->broadcastAs());
        $this->assertSame('private-tenant.tenant-1.runtime-operations', $event->broadcastOn()->name);
        $this->assertTrue($event->afterCommit);

        $payload = $event->broadcastWith();
        $this->assertSame([
            'event_type' => 'runtime_operation.progressed',
            'aggregate_type' => 'runtime_operation',
            'aggregate_id' => 'operation-1',
            'runtime_operation_id' => 'operation-1',
            'tenant_id' => 'tenant-1',
            'occurred_at' => $occurredAt->toJSON(),
            'runtime_node_id' => 'runtime-node-1',
        ], $payload);
        $this->assertSame(
            ['aggregate_id', 'aggregate_type', 'event_type', 'occurred_at', 'runtime_node_id', 'runtime_operation_id', 'tenant_id'],
            array_keys(collect($payload)->sortKeys()->all()),
        );

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'payload',
            'status',
            'failure',
            'stack',
            'secret',
            'credential',
            'endpoint',
            'command',
            'provider',
            'result',
            'requested',
            'lease',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_runtime_operation_broadcast_event_omits_runtime_node_when_unavailable(): void
    {
        $event = new RuntimeOperationOperationalStateChanged(
            eventType: 'runtime_operation.status_changed',
            aggregateId: 'operation-1',
            runtimeOperationId: 'operation-1',
            runtimeNodeId: null,
            tenantId: 'tenant-1',
            occurredAt: CarbonImmutable::parse('2026-07-23T01:02:03.000000Z'),
        );

        $this->assertArrayNotHasKey('runtime_node_id', $event->broadcastWith());
    }

    public function test_outbox_dispatcher_bridges_runtime_operation_events_only(): void
    {
        Event::fake([RuntimeOperationOperationalStateChanged::class, RuntimeNodeOperationalStateChanged::class]);
        [$tenantId, $runtimeNodeId, $operationId] = $this->createRuntimeOperationRecord();

        $createdId = $this->appendOutboxEvent($tenantId, $operationId, 'runtime_operation.created', 'runtime_operation', [
            'runtime_node_id' => $runtimeNodeId,
            'status' => 'terminal_failed',
            'failure_message' => 'stack trace marker',
        ]);
        $updatedId = $this->appendOutboxEvent($tenantId, $operationId, 'runtime_operation.status_changed', 'runtime_operation', [
            'runtime_node_id' => $runtimeNodeId,
            'payload' => ['raw_marker' => 'must-not-broadcast'],
        ]);
        $wrongPrefixId = $this->appendOutboxEvent($tenantId, $operationId, 'runtime_node.updated', 'runtime_operation', [
            'runtime_node_id' => $runtimeNodeId,
        ]);
        $otherAggregateId = $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_operation.status_changed', 'runtime_node', []);

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(4, $dispatcher->dispatchOnce('runtime-operation-realtime-worker', 10));

        Event::assertDispatched(RuntimeOperationOperationalStateChanged::class, 2);
        Event::assertDispatched(
            RuntimeOperationOperationalStateChanged::class,
            fn (RuntimeOperationOperationalStateChanged $event): bool => $event->eventType === 'runtime_operation.created'
                && $event->aggregateId === $operationId
                && $event->runtimeOperationId === $operationId
                && $event->runtimeNodeId === $runtimeNodeId
                && $event->tenantId === $tenantId,
        );
        Event::assertDispatched(
            RuntimeOperationOperationalStateChanged::class,
            fn (RuntimeOperationOperationalStateChanged $event): bool => $event->eventType === 'runtime_operation.status_changed'
                && $event->runtimeOperationId === $operationId,
        );
        Event::assertNotDispatched(
            RuntimeOperationOperationalStateChanged::class,
            fn (RuntimeOperationOperationalStateChanged $event): bool => $event->eventType === 'runtime_node.updated',
        );
        Event::assertNotDispatched(RuntimeNodeOperationalStateChanged::class);

        foreach ([$createdId, $updatedId, $wrongPrefixId, $otherAggregateId] as $outboxId) {
            $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $outboxId, 'dispatch_status' => 'dispatched']);
        }
    }

    public function test_rolled_back_runtime_operation_outbox_event_produces_no_broadcast(): void
    {
        Event::fake([RuntimeOperationOperationalStateChanged::class]);
        [$tenantId, $runtimeNodeId, $operationId] = $this->createRuntimeOperationRecord();

        DB::beginTransaction();
        $this->appendOutboxEvent($tenantId, $operationId, 'runtime_operation.status_changed', 'runtime_operation', [
            'runtime_node_id' => $runtimeNodeId,
        ]);
        DB::rollBack();

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(0, $dispatcher->dispatchOnce('runtime-operation-rollback-worker', 10));
        Event::assertNotDispatched(RuntimeOperationOperationalStateChanged::class);
    }

    public function test_broadcast_bridge_failure_does_not_reverse_committed_runtime_operation_mutation(): void
    {
        Queue::fake();
        [$tenantId, $runtimeNodeId, $operationId] = $this->createRuntimeOperationRecord();
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'status' => OperationStatus::Running->value,
            'updated_at' => now(),
        ]);
        $outboxId = $this->appendOutboxEvent($tenantId, $operationId, 'runtime_operation.status_changed', 'runtime_operation', [
            'runtime_node_id' => $runtimeNodeId,
        ]);

        $bridge = new class extends OperationalBroadcastBridge
        {
            public function dispatchForOutboxRow(object $row): bool
            {
                throw new \RuntimeException('synthetic runtime operation broadcast bridge failure');
            }
        };

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository, $bridge);
        $this->assertSame(0, $dispatcher->dispatchOnce('runtime-operation-failure-worker', 10));

        $this->assertDatabaseHas('runtime_operations', [
            'id' => $operationId,
            'status' => OperationStatus::Running->value,
        ]);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'id' => $outboxId,
            'dispatch_status' => 'retry_scheduled',
            'last_failure_class' => 'internal_error',
            'last_failure_code' => 'queue_delivery_failed',
        ]);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createRuntimeOperationRecord(): array
    {
        [$admin, $tenantId] = $this->createTenantAdmin('runtime-operation-record-'.Str::random(6).'@utcp.local.test', 'runtime-operation-record-'.Str::lower(Str::random(6)));
        $runtimeNodeId = $this->createRuntimeNode($tenantId, $admin->id);
        $operationId = RuntimeOperationId::new()->value();
        DB::table('runtime_operations')->insert([
            'id' => $operationId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'operation_type' => 'runtime.node.inspect',
            'aggregate_type' => 'runtime_node',
            'aggregate_id' => $runtimeNodeId,
            'payload_version' => 1,
            'payload' => json_encode(['action' => 'inspect', 'safe_marker' => 'must-not-broadcast'], JSON_THROW_ON_ERROR),
            'status' => OperationStatus::Pending->value,
            'priority' => 100,
            'idempotency_key' => 'idempotency-'.$operationId,
            'correlation_id' => RuntimeOperationId::new()->value(),
            'causation_id' => null,
            'request_id' => RuntimeOperationId::new()->value(),
            'attempt_count' => 0,
            'max_attempts' => 3,
            'available_at' => CarbonImmutable::parse('2026-07-23T10:10:00Z'),
            'expires_at' => null,
            'lease_owner' => 'worker-runtime-operation-test',
            'lease_token' => 'ffffffffffffffffffffffffffffffff',
            'lease_expires_at' => null,
            'last_failure_class' => null,
            'last_failure_code' => null,
            'last_failure_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_at' => CarbonImmutable::parse('2026-07-23T10:10:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-23T10:10:00Z'),
        ]);

        return [$tenantId, $runtimeNodeId, $operationId];
    }

    private function createRuntimeNode(string $tenantId, ?string $actorId): string
    {
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Runtime Operation Realtime Node',
            'slug' => 'runtime-operation-realtime-'.Str::lower(Str::random(6)),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
            'configuration_version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => json_encode(['purpose' => 'runtime-operation-realtime-test'], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-07-23T10:00:00Z'),
        ]);

        return $runtimeNodeId;
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
            context: ExecutionContext::system(tenantId: $tenantId, origin: 'ui-d14-test'),
        ));
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'runtime-operation-realtime-admin@utcp.local.test', string $slug = 'runtime-operation-realtime'): array
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
            'display_name' => 'Runtime Operation Realtime Test User',
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
                'display_name' => 'Runtime Operation Realtime Denied',
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
            'channel_name' => "private-tenant.{$tenantId}.runtime-operations",
        ];
    }
}
