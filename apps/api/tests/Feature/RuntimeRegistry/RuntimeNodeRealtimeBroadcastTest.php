<?php

namespace Tests\Feature\RuntimeRegistry;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Events\RuntimeNodeOperationalStateChanged;
use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Outbox\OutboxDispatcher;
use App\RuntimeEngine\Outbox\RuntimeNodeBroadcastBridge;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeNodeRealtimeBroadcastTest extends TestCase
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

    public function test_runtime_node_private_channel_authorization_uses_identity_session_tenant_and_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-realtime-admin@utcp.local.test', 'other-realtime');
        $member = $this->createUser('realtime-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-realtime-denied');

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

        $otherAdmin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->post('/api/broadcasting/auth', $this->authPayload($otherTenantId), ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_runtime_node_broadcast_event_is_notification_only(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-23T01:02:03.000000Z');
        $event = new RuntimeNodeOperationalStateChanged(
            eventType: 'runtime_node.observed_state_changed',
            runtimeNodeId: 'runtime-node-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
        );

        $this->assertSame('runtime-node.operational-state.changed', $event->broadcastAs());
        $this->assertSame('private-tenant.tenant-1.runtime-nodes', $event->broadcastOn()->name);
        $this->assertTrue($event->afterCommit);

        $payload = $event->broadcastWith();
        $this->assertSame([
            'event_type' => 'runtime_node.observed_state_changed',
            'aggregate_type' => 'runtime_node',
            'runtime_node_id' => 'runtime-node-1',
            'tenant_id' => 'tenant-1',
            'occurred_at' => $occurredAt->toJSON(),
        ], $payload);
        $this->assertSame(
            ['aggregate_type', 'event_type', 'occurred_at', 'runtime_node_id', 'tenant_id'],
            array_keys(collect($payload)->sortKeys()->all()),
        );
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString('credential', $serialized);
        $this->assertStringNotContainsString('endpoint', $serialized);
        $this->assertStringNotContainsString('desired_state', $serialized);
    }

    public function test_outbox_dispatcher_bridges_committed_runtime_node_events_and_ignores_other_aggregates(): void
    {
        Event::fake([RuntimeNodeOperationalStateChanged::class]);
        [$tenantId, $runtimeNodeId] = $this->createRuntimeNodeRecord();

        $createdId = $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_node.created', 'runtime_node');
        $observedId = $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_node.event_listener_degraded', 'runtime_node');
        $otherId = $this->appendOutboxEvent($tenantId, 'tenant-1', 'tenant.updated', 'tenant');

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(3, $dispatcher->dispatchOnce('realtime-test-worker', 10));

        Event::assertDispatched(RuntimeNodeOperationalStateChanged::class, 2);
        Event::assertDispatched(
            RuntimeNodeOperationalStateChanged::class,
            fn (RuntimeNodeOperationalStateChanged $event): bool => $event->eventType === 'runtime_node.created'
                && $event->runtimeNodeId === $runtimeNodeId
                && $event->tenantId === $tenantId,
        );
        Event::assertDispatched(
            RuntimeNodeOperationalStateChanged::class,
            fn (RuntimeNodeOperationalStateChanged $event): bool => $event->eventType === 'runtime_node.event_listener_degraded'
                && $event->runtimeNodeId === $runtimeNodeId
                && $event->tenantId === $tenantId,
        );
        Event::assertNotDispatched(
            RuntimeNodeOperationalStateChanged::class,
            fn (RuntimeNodeOperationalStateChanged $event): bool => $event->runtimeNodeId === 'conference-1',
        );

        $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $createdId, 'dispatch_status' => 'dispatched']);
        $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $observedId, 'dispatch_status' => 'dispatched']);
        $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $otherId, 'dispatch_status' => 'dispatched']);
    }

    public function test_rolled_back_runtime_node_outbox_event_produces_no_broadcast(): void
    {
        Event::fake([RuntimeNodeOperationalStateChanged::class]);
        [$tenantId, $runtimeNodeId] = $this->createRuntimeNodeRecord();

        DB::beginTransaction();
        $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_node.updated', 'runtime_node');
        DB::rollBack();

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(0, $dispatcher->dispatchOnce('rollback-worker', 10));
        Event::assertNotDispatched(RuntimeNodeOperationalStateChanged::class);
    }

    public function test_broadcast_bridge_failure_does_not_reverse_committed_runtime_node_mutation(): void
    {
        Queue::fake();
        [$tenantId, $runtimeNodeId] = $this->createRuntimeNodeRecord();
        $outboxId = $this->appendOutboxEvent($tenantId, $runtimeNodeId, 'runtime_node.updated', 'runtime_node');

        $bridge = new class extends RuntimeNodeBroadcastBridge
        {
            public function dispatchForOutboxRow(object $row): bool
            {
                throw new \RuntimeException('synthetic broadcast bridge failure');
            }
        };

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository, $bridge);
        $this->assertSame(0, $dispatcher->dispatchOnce('failure-worker', 10));

        $this->assertDatabaseHas('runtime_nodes', ['id' => $runtimeNodeId, 'observed_state' => 'ready']);
        $this->assertDatabaseHas('control_plane_outbox_messages', [
            'id' => $outboxId,
            'dispatch_status' => 'retry_scheduled',
            'last_failure_class' => 'internal_error',
            'last_failure_code' => 'queue_delivery_failed',
        ]);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createRuntimeNodeRecord(): array
    {
        [$admin, $tenantId] = $this->createTenantAdmin('node-realtime-admin-'.Str::random(6).'@utcp.local.test', 'node-realtime-'.Str::lower(Str::random(6)));
        $runtimeNodeId = (string) Str::uuid();
        DB::table('runtime_nodes')->insert([
            'id' => $runtimeNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Realtime Runtime',
            'slug' => 'realtime-runtime-'.Str::lower(Str::random(6)),
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'observed_at' => now(),
            'configuration_version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => json_encode(['purpose' => 'realtime-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $runtimeNodeId];
    }

    private function appendOutboxEvent(string $tenantId, string $aggregateId, string $eventType, string $aggregateType): string
    {
        return (new OutboxRepository)->append(EventEnvelope::forAggregate(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: ['summary' => 'notification-only test payload'],
            context: ExecutionContext::system(tenantId: $tenantId, origin: 'ui-d1-test'),
        ));
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'realtime-admin@utcp.local.test', string $slug = 'realtime'): array
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

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        if ($roleKey === 'tenant-realtime-denied') {
            DB::table('roles')->insert([
                'key' => $roleKey,
                'scope' => 'tenant',
                'display_name' => 'Realtime denied tenant role',
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

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Realtime Test User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authPayload(string $tenantId): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => "private-tenant.{$tenantId}.runtime-nodes",
        ];
    }
}
