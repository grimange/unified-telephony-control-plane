<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\InboxRepository;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Events\ConferenceOperationalStateChanged;
use App\Events\RuntimeNodeOperationalStateChanged;
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

final class ConferenceRealtimeBroadcastTest extends TestCase
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

    public function test_conference_private_channel_authorization_uses_identity_session_tenant_and_capability(): void
    {
        [$admin, $tenantId] = $this->createTenantUserWithRole('conference-admin@utcp.local.test', 'conference-realtime', 'tenant-admin');
        [$otherAdmin, $otherTenantId] = $this->createTenantUserWithRole('conference-other@utcp.local.test', 'conference-other', 'tenant-admin');
        [$member] = $this->createTenantUserWithRole('conference-denied@utcp.local.test', 'conference-denied-member', 'tenant-conference-denied', $tenantId);

        $this->assertTrue(app(AuthorizationService::class)->hasTenant($admin->id, $tenantId, 'telephony.conferences.view'));
        $this->assertFalse(app(AuthorizationService::class)->hasTenant($member->id, $tenantId, 'telephony.conferences.view'));

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

    public function test_conference_broadcast_event_is_notification_only(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-23T01:02:03.000000Z');
        $event = new ConferenceOperationalStateChanged(
            eventType: 'conference_participant.admitted',
            aggregateType: 'conference_participant',
            aggregateId: 'participant-1',
            conferenceId: 'conference-1',
            tenantId: 'tenant-1',
            occurredAt: $occurredAt,
        );

        $this->assertSame('conference.operational-state.changed', $event->broadcastAs());
        $this->assertSame('private-tenant.tenant-1.conferences', $event->broadcastOn()->name);
        $this->assertTrue($event->afterCommit);

        $payload = $event->broadcastWith();
        $this->assertSame([
            'event_type' => 'conference_participant.admitted',
            'aggregate_type' => 'conference_participant',
            'aggregate_id' => 'participant-1',
            'conference_id' => 'conference-1',
            'tenant_id' => 'tenant-1',
            'occurred_at' => $occurredAt->toJSON(),
        ], $payload);
        $this->assertSame(
            ['aggregate_id', 'aggregate_type', 'conference_id', 'event_type', 'occurred_at', 'tenant_id'],
            array_keys(collect($payload)->sortKeys()->all()),
        );

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['secret', 'credential', 'endpoint', 'participant_name', 'destination', 'desired_state', 'observed_state', 'payload'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_outbox_dispatcher_bridges_conference_and_participant_events_only(): void
    {
        Event::fake([ConferenceOperationalStateChanged::class, RuntimeNodeOperationalStateChanged::class]);
        [$tenantId, $conferenceId, $participantId] = $this->createConferenceRecord();

        $conferenceOutboxId = $this->appendOutboxEvent($tenantId, $conferenceId, 'conference.desired_state_changed', 'conference', [
            'conference_id' => $conferenceId,
        ]);
        $participantOutboxId = $this->appendOutboxEvent($tenantId, $participantId, 'conference_participant.admitted', 'conference_participant', [
            'conference_id' => $conferenceId,
        ]);
        $missingConferenceOutboxId = $this->appendOutboxEvent($tenantId, 'participant-missing', 'conference_participant.removed', 'conference_participant', [
            'summary' => 'missing conference id',
        ]);
        $otherOutboxId = $this->appendOutboxEvent($tenantId, 'runtime-1', 'runtime_node.updated', 'runtime_node', []);

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(4, $dispatcher->dispatchOnce('conference-realtime-worker', 10));

        Event::assertDispatched(ConferenceOperationalStateChanged::class, 2);
        Event::assertDispatched(
            ConferenceOperationalStateChanged::class,
            fn (ConferenceOperationalStateChanged $event): bool => $event->eventType === 'conference.desired_state_changed'
                && $event->aggregateType === 'conference'
                && $event->aggregateId === $conferenceId
                && $event->conferenceId === $conferenceId
                && $event->tenantId === $tenantId,
        );
        Event::assertDispatched(
            ConferenceOperationalStateChanged::class,
            fn (ConferenceOperationalStateChanged $event): bool => $event->eventType === 'conference_participant.admitted'
                && $event->aggregateType === 'conference_participant'
                && $event->aggregateId === $participantId
                && $event->conferenceId === $conferenceId
                && $event->tenantId === $tenantId,
        );
        Event::assertNotDispatched(
            ConferenceOperationalStateChanged::class,
            fn (ConferenceOperationalStateChanged $event): bool => $event->aggregateId === 'participant-missing',
        );
        Event::assertDispatched(RuntimeNodeOperationalStateChanged::class);

        foreach ([$conferenceOutboxId, $participantOutboxId, $missingConferenceOutboxId, $otherOutboxId] as $outboxId) {
            $this->assertDatabaseHas('control_plane_outbox_messages', ['id' => $outboxId, 'dispatch_status' => 'dispatched']);
        }
    }

    public function test_rolled_back_conference_outbox_event_produces_no_broadcast(): void
    {
        Event::fake([ConferenceOperationalStateChanged::class]);
        [$tenantId, $conferenceId] = $this->createConferenceRecord();

        DB::beginTransaction();
        $this->appendOutboxEvent($tenantId, $conferenceId, 'conference.desired_state_changed', 'conference', [
            'conference_id' => $conferenceId,
        ]);
        DB::rollBack();

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository);
        $this->assertSame(0, $dispatcher->dispatchOnce('conference-rollback-worker', 10));
        Event::assertNotDispatched(ConferenceOperationalStateChanged::class);
    }

    public function test_broadcast_bridge_failure_does_not_reverse_committed_conference_mutation(): void
    {
        Queue::fake();
        [$tenantId, $conferenceId] = $this->createConferenceRecord();
        DB::table('conferences')->where('id', $conferenceId)->update(['desired_state' => 'open', 'updated_at' => now()]);
        $outboxId = $this->appendOutboxEvent($tenantId, $conferenceId, 'conference.desired_state_changed', 'conference', [
            'conference_id' => $conferenceId,
        ]);

        $bridge = new class extends OperationalBroadcastBridge
        {
            public function dispatchForOutboxRow(object $row): bool
            {
                throw new \RuntimeException('synthetic conference broadcast bridge failure');
            }
        };

        $dispatcher = new OutboxDispatcher(new OutboxRepository, new InboxRepository, $bridge);
        $this->assertSame(0, $dispatcher->dispatchOnce('conference-failure-worker', 10));

        $this->assertDatabaseHas('conferences', ['id' => $conferenceId, 'desired_state' => 'open']);
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
    private function createConferenceRecord(): array
    {
        [$admin, $tenantId] = $this->createTenantUserWithRole('conference-record-'.Str::random(6).'@utcp.local.test', 'conference-record-'.Str::lower(Str::random(6)), 'tenant-admin');
        $conferenceId = (string) Str::uuid();
        $participantId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'realtime-conference-'.Str::lower(Str::random(6)),
            'display_name' => 'Realtime Conference',
            'runtime_node_id' => null,
            'desired_state' => 'draft',
            'observed_state' => 'unobserved',
            'configuration_generation' => 1,
            'observed_generation' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $admin->id,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $admin->id,
            'desired_state' => 'admitted',
            'observed_state' => 'joined',
            'role' => 'participant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $conferenceId, $participantId];
    }

    private function appendOutboxEvent(string $tenantId, string $aggregateId, string $eventType, string $aggregateType, array $payload): string
    {
        return (new OutboxRepository)->append(EventEnvelope::forAggregate(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            context: ExecutionContext::system(tenantId: $tenantId, origin: 'ui-d9-test'),
        ));
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantUserWithRole(string $email, string $slug, string $roleKey, ?string $tenantId = null): array
    {
        $user = User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Conference Realtime User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
        $tenantId ??= IdentityIds::new();
        DB::table('tenants')->insertOrIgnore([
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
        $this->attachTenantRole($user->id, $tenantId, $roleKey);

        return [$user, $tenantId];
    }

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        if ($roleKey === 'tenant-conference-denied') {
            DB::table('roles')->insert([
                'key' => $roleKey,
                'scope' => 'tenant',
                'display_name' => 'Conference denied tenant role',
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
            'channel_name' => "private-tenant.{$tenantId}.conferences",
        ];
    }
}
