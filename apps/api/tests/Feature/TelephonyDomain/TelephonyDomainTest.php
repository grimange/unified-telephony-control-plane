<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\Simulator\SimulatorEventSourceWorker;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TelephonyDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_open_conference_member_session_and_participation_lifecycle_through_runtime_path(): void
    {
        [$admin, $member, $tenantId, $nodeId] = $this->fixture();

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/admin/conferences', [
                'slug' => 'daily-standup',
                'display_name' => 'Daily Standup',
                'runtime_node_id' => $nodeId,
            ], ['Idempotency-Key' => 'conference-create-key'])
            ->assertCreated()
            ->assertJsonPath('conference.desired_state', 'draft')
            ->json('conference');

        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/admin/conferences', ['slug' => 'denied', 'display_name' => 'Denied'])
            ->assertForbidden();

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'open'])
            ->assertOk()
            ->assertJsonPath('conference.desired_state', 'open')
            ->json('conference');

        $this->runConferenceRuntime($conference['id']);
        $this->assertSame('ready', DB::table('conferences')->where('id', $conference['id'])->value('observed_state'));

        $session = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/telephony/sessions', [], ['Idempotency-Key' => 'session-create-key'])
            ->assertCreated()
            ->assertJsonPath('telephony_session.status', 'active')
            ->json('telephony_session');

        $participant = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/conferences/{$conference['id']}/participants/self", [], ['Idempotency-Key' => 'join-key'])
            ->assertCreated()
            ->assertJsonPath('participant.desired_state', 'admitted')
            ->json('participant');

        $duplicate = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/conferences/{$conference['id']}/participants/self", [], ['Idempotency-Key' => 'join-key'])
            ->assertCreated()
            ->json('participant');
        $this->assertSame($participant['id'], $duplicate['id']);

        $this->runParticipantRuntime($participant['id']);
        $this->assertSame('joined', DB::table('conference_participants')->where('id', $participant['id'])->value('observed_state'));

        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/end")
            ->assertOk()
            ->assertJsonPath('telephony_session.status', 'ended');

        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'conference_participant',
            'target_id' => $participant['id'],
            'status' => 'waiting',
        ]);

        $this->runParticipantRuntime($participant['id']);
        $this->assertSame('removed', DB::table('conference_participants')->where('id', $participant['id'])->value('desired_state'));
        $this->assertSame('left', DB::table('conference_participants')->where('id', $participant['id'])->value('observed_state'));

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'draining'])
            ->assertOk()
            ->json('conference');
        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/conferences/{$conference['id']}/participants/self")
            ->assertUnprocessable();

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'closed'])
            ->assertOk()
            ->json('conference');
        $this->runConferenceRuntime($conference['id']);
        $this->assertSame('closed', DB::table('conferences')->where('id', $conference['id'])->value('observed_state'));
    }

    public function test_session_expiry_removes_participation_and_cross_tenant_access_fails(): void
    {
        [$admin, $member, $tenantId, $nodeId] = $this->fixture('expiry');
        [$otherAdmin, , $otherTenantId] = $this->fixture('other');

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/admin/conferences', [
                'slug' => 'expiry-proof',
                'display_name' => 'Expiry Proof',
                'runtime_node_id' => $nodeId,
            ])
            ->assertCreated()
            ->json('conference');

        $this->actingAs($otherAdmin)->withSession($this->tenantSession($otherTenantId))
            ->getJson("/api/v1/admin/conferences/{$conference['id']}")
            ->assertNotFound();

        $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'open'])
            ->assertOk();

        $session = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/telephony/sessions')
            ->assertCreated()
            ->json('telephony_session');

        $participant = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/conferences/{$conference['id']}/participants/self")
            ->assertCreated()
            ->json('participant');

        DB::table('telephony_sessions')->where('id', $session['id'])->update(['expires_at' => now()->subMinute()]);
        $this->artisan('telephony-domain:expire-sessions')->assertExitCode(0);

        $this->assertSame('expired', DB::table('telephony_sessions')->where('id', $session['id'])->value('status'));
        $this->assertSame('removed', DB::table('conference_participants')->where('id', $participant['id'])->value('desired_state'));
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'conference_participant',
            'target_id' => $participant['id'],
            'status' => 'waiting',
        ]);

        $this->runParticipantRuntime($participant['id']);
        $this->assertSame('left', DB::table('conference_participants')->where('id', $participant['id'])->value('observed_state'));
        $this->assertDatabaseMissing('runtime_operations', [
            'operation_type' => 'conference.participant.remove',
            'aggregate_type' => 'conference_participant',
            'aggregate_id' => $participant['id'],
        ]);
    }

    public function test_session_scoped_signaling_credential_is_issued_once_and_metadata_is_sanitized(): void
    {
        [, $member, $tenantId] = $this->fixture('signaling');

        $session = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/telephony/sessions')
            ->assertCreated()
            ->json('telephony_session');

        $issued = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertCreated()
            ->assertJsonPath('credential.realm', 'sip.utcp.local.test')
            ->assertJsonPath('credential.algorithm', 'MD5')
            ->json('credential');

        $this->assertNotEmpty($issued['sip_secret']);
        $this->assertSame('wss://sip.utcp.local.test/ws', $issued['wss_uri']);
        $this->assertMatchesRegularExpression('/\Ats-[0-9a-f]{32}\z/', $issued['username']);

        $row = DB::table('telephony_signaling_credentials')->where('telephony_session_id', $session['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame(md5($issued['username'].':sip.utcp.local.test:'.$issued['sip_secret']), $row->ha1);
        $this->assertSame('089a9e75129e7076659b3e9800a28908', md5('alice:sip.utcp.local.test:correcthorsebatterystaple'));
        $this->assertDatabaseMissing('telephony_signaling_credentials', ['ha1' => $issued['sip_secret']]);

        $metadata = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->getJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertOk()
            ->assertJsonMissingPath('credential.sip_secret')
            ->assertJsonMissingPath('credential.ha1')
            ->json();
        $this->assertSame($issued['username'], $metadata['credential']['username']);
        $this->assertSame('eligible', $metadata['registration']['desired_state']);

        DB::table('telephony_signaling_credentials')->where('id', $row->id)->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->getJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertOk()
            ->assertJsonPath('credential', null)
            ->assertJsonPath('registration.observed_state', 'unknown');

        $replacement = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertCreated()
            ->json('credential');
        $this->assertNotSame($issued['sip_secret'], $replacement['sip_secret']);
        $this->assertSame(1, DB::table('telephony_signaling_credentials')->where('telephony_session_id', $session['id'])->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('telephony_signaling_credentials')->where('telephony_session_id', $session['id'])->whereNotNull('revoked_at')->count());

        $audit = DB::table('control_plane_audit_records')
            ->where('action', 'telephony.signaling_credential.issued')
            ->orderByDesc('occurred_at')
            ->value('metadata');
        $this->assertIsString($audit);
        $this->assertStringNotContainsString($issued['sip_secret'], $audit);
        $this->assertStringNotContainsString($row->ha1, $audit);
    }

    public function test_signaling_credential_access_is_tenant_scoped_and_session_end_revokes_registration_desire(): void
    {
        [, $member, $tenantId] = $this->fixture('signaling-scope');
        [, $otherMember, $otherTenantId] = $this->fixture('signaling-other');

        $session = $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/telephony/sessions')
            ->assertCreated()
            ->json('telephony_session');

        $this->actingAs($otherMember)->withSession($this->tenantSession($otherTenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertNotFound();

        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/signaling-credential")
            ->assertCreated();

        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/telephony/sessions/{$session['id']}/end")
            ->assertOk();

        $this->assertSame(0, DB::table('telephony_signaling_credentials')->where('telephony_session_id', $session['id'])->whereNull('revoked_at')->count());
        $this->assertSame('removed', DB::table('signaling_registration_observations')->where('telephony_session_id', $session['id'])->value('desired_state'));
    }

    public function test_automatic_conference_runtime_binding_selects_only_the_observed_ready_eligible_node(): void
    {
        [$admin, , $tenantId, $readyNodeId] = $this->fixture('binding-selection');

        $staleNodeId = '00000000-0000-0000-0000-000000000001';
        DB::table('runtime_nodes')->insert([
            'id' => $staleNodeId,
            'tenant_id' => $tenantId,
            'name' => 'Stale Conference Candidate',
            'slug' => 'stale-conference-candidate',
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'stale',
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode(['purpose' => 'stale-binding-regression'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $staleNodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/admin/conferences', [
                'slug' => 'automatic-binding',
                'display_name' => 'Automatic Binding',
            ])
            ->assertCreated()
            ->assertJsonPath('conference.runtime_node_id', null)
            ->json('conference');

        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'open'])
            ->assertOk()
            ->json('conference');

        $this->assertSame($readyNodeId, $conference['runtime_node_id'], 'automatic selection must never bind a desired-active-but-observed-stale node');
        $this->assertSame(
            1,
            DB::table('conference_runtime_bindings')
                ->where('conference_id', $conference['id'])
                ->where('runtime_node_id', $readyNodeId)
                ->where('status', 'active')
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('conference_runtime_bindings')
                ->where('conference_id', $conference['id'])
                ->where('runtime_node_id', $staleNodeId)
                ->count(),
        );
    }

    public function test_open_conference_runtime_rebind_atomically_retires_former_binding_and_wakes_reconciliation(): void
    {
        [$admin, $member, $tenantId, $nodeA] = $this->fixture('rebind-success');
        $nodeB = $this->runtimeNode($tenantId, 'rebind-success-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-success');
        $participant = $this->admitParticipantFor($member, $tenantId, $conference['id']);
        DB::table('runtime_reconciliation_states')->where('target_id', $conference['id'])->update(['status' => 'converged', 'next_check_at' => now()->addHour()]);
        DB::table('runtime_reconciliation_states')->where('target_id', $participant['id'])->update(['status' => 'converged', 'next_check_at' => now()->addHour()]);
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $result = app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 't5 rebind test'),
            $tenantId,
            $conference['id'],
            'test-unavailable',
        );

        $updated = DB::table('conferences')->where('id', $conference['id'])->first();
        $this->assertSame('rebound', $result['status']);
        $this->assertSame($nodeA, $result['previous_runtime_node_id']);
        $this->assertSame($nodeB, $result['runtime_node_id']);
        $this->assertSame($nodeB, $updated->runtime_node_id);
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) $updated->configuration_generation);
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseHas('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeA,
            'status' => 'retired',
        ]);
        $this->assertDatabaseHas('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'conference',
            'target_id' => $conference['id'],
            'status' => 'waiting',
            'desired_generation' => (int) $updated->configuration_generation,
        ]);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'conference_participant',
            'target_id' => $participant['id'],
            'status' => 'waiting',
            'desired_generation' => ((int) $updated->configuration_generation * 2),
        ]);
    }

    public function test_rebind_is_rejected_while_bound_runtime_node_is_still_ready(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('rebind-ready');
        $nodeB = $this->runtimeNode($tenantId, 'rebind-ready-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-ready');

        $result = app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 't5 rebind ready test'),
            $tenantId,
            $conference['id'],
        );

        $this->assertSame(['status' => 'noop', 'reason' => 'bound_runtime_node_ready'], $result);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseMissing('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
        ]);
    }

    public function test_rebind_fails_without_eligible_distinct_replacement_and_preserves_authority(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('rebind-none');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-none');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        try {
            app(TelephonyDomainService::class)->failoverRebindConference(
                ExecutionContext::system(tenantId: $tenantId, reason: 't5 rebind none test'),
                $tenantId,
                $conference['id'],
            );
            $this->fail('Expected rebind to fail without a distinct eligible replacement.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseHas('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeA,
            'status' => 'active',
        ]);
    }

    public function test_rebind_selection_cannot_return_the_current_runtime_node(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('rebind-distinct');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-distinct');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        try {
            app(TelephonyDomainService::class)->failoverRebindConference(
                ExecutionContext::system(tenantId: $tenantId, reason: 't5 distinct test'),
                $tenantId,
                $conference['id'],
            );
            $this->fail('Expected current node exclusion to leave no eligible replacement.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame($nodeA, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->value('runtime_node_id'));
    }

    public function test_rebind_transaction_rolls_back_if_replacement_binding_insert_fails(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('rebind-rollback');
        $this->runtimeNode($tenantId, 'rebind-rollback-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-rollback');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        DB::statement("create trigger fail_replacement_binding_insert before insert on conference_runtime_bindings when new.status = 'active' begin select raise(abort, 'forced replacement binding failure'); end");

        try {
            app(TelephonyDomainService::class)->failoverRebindConference(
                ExecutionContext::system(tenantId: $tenantId, reason: 't5 rollback test'),
                $tenantId,
                $conference['id'],
            );
            $this->fail('Expected replacement binding insert to fail.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseHas('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeA,
            'status' => 'active',
        ]);
    }

    public function test_serialized_rebind_attempts_produce_one_winner_and_one_safe_loser(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('rebind-serialized');
        $nodeB = $this->runtimeNode($tenantId, 'rebind-serialized-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'rebind-serialized');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $first = app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 't5 first serialized test'),
            $tenantId,
            $conference['id'],
        );
        $second = app(TelephonyDomainService::class)->failoverRebindConference(
            ExecutionContext::system(tenantId: $tenantId, reason: 't5 second serialized test'),
            $tenantId,
            $conference['id'],
        );

        $this->assertSame('rebound', $first['status']);
        $this->assertSame(['status' => 'noop', 'reason' => 'bound_runtime_node_ready'], $second);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('runtime_node_id', $nodeB)->where('status', 'active')->count());
    }

    /**
     * @return array{0:User,1:User,2:string,3:string}
     */
    private function fixture(string $slug = 'local'): array
    {
        $tenantId = IdentityIds::new();
        $admin = $this->user('admin-'.$slug.'@utcp.local.test');
        $member = $this->user('member-'.$slug.'@utcp.local.test');
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => ucfirst($slug).' Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->role($admin->id, $tenantId, 'tenant-admin');
        $this->role($member->id, $tenantId, 'tenant-member');

        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Conference Simulator',
            'slug' => 'conference-simulator-'.$slug,
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode(['purpose' => 'c5-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['event.stream', 'runtime.configuration', 'runtime.observation', 'conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('simulator_profiles')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => 'steady-ready',
            'scenario_version' => 1,
            'seed' => 'c5-proof-seed',
            'parameters' => json_encode([], JSON_THROW_ON_ERROR),
            'configuration_generation' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('simulator_states')->insert([
            'runtime_node_id' => $nodeId,
            'scenario_key' => 'steady-ready',
            'scenario_version' => 1,
            'seed' => 'c5-proof-seed',
            'logical_sequence' => 0,
            'current_phase' => 'uninitialized',
            'attempt_count' => 0,
            'active_connection_epoch' => null,
            'applied_configuration_generation' => 0,
            'next_event_sequence' => 1,
            'state_payload' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin, $member, $tenantId, $nodeId];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function runtimeNode(string $tenantId, string $slug, string $observedState = 'ready', string $desiredState = 'active', array $capabilities = ['event.stream', 'runtime.configuration', 'runtime.observation', 'conference.lifecycle', 'conference.participation']): string
    {
        $nodeId = IdentityIds::new();
        DB::table('runtime_nodes')->insert([
            'id' => $nodeId,
            'tenant_id' => $tenantId,
            'name' => 'Conference Runtime '.$slug,
            'slug' => 'conference-runtime-'.$slug,
            'runtime_family' => 'simulator',
            'adapter_key' => 'simulator-deterministic',
            'desired_state' => $desiredState,
            'observed_state' => $observedState,
            'configuration_version' => 1,
            'placement_priority' => 100,
            'capacity_weight' => 1,
            'labels' => json_encode(['purpose' => 't5-rebind-test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($capabilities as $capability) {
            DB::table('runtime_node_capabilities')->insert([
                'id' => IdentityIds::new(),
                'runtime_node_id' => $nodeId,
                'capability_key' => $capability,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $nodeId;
    }

    /**
     * @return array<string, mixed>
     */
    private function openConference(User $admin, string $tenantId, string $nodeId, string $slug): array
    {
        $conference = $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/admin/conferences', [
                'slug' => $slug,
                'display_name' => 'T5 Rebind '.$slug,
                'runtime_node_id' => $nodeId,
            ])
            ->assertCreated()
            ->json('conference');

        return $this->actingAs($admin)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/admin/conferences/{$conference['id']}/desired-state", ['desired_state' => 'open'])
            ->assertOk()
            ->json('conference');
    }

    /**
     * @return array<string, mixed>
     */
    private function admitParticipantFor(User $member, string $tenantId, string $conferenceId): array
    {
        $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson('/api/v1/telephony/sessions')
            ->assertCreated();

        return $this->actingAs($member)->withSession($this->tenantSession($tenantId))
            ->postJson("/api/v1/conferences/{$conferenceId}/participants/self")
            ->assertCreated()
            ->json('participant');
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Telephony User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    private function role(string $userId, string $tenantId, string $roleKey): void
    {
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
     * @return array<string, mixed>
     */
    private function tenantSession(string $tenantId): array
    {
        return ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
    }

    private function runConferenceRuntime(string $conferenceId): void
    {
        $conference = DB::table('conferences')->where('id', $conferenceId)->first();
        app(ReconciliationRepository::class)->ensureTarget((string) $conference->tenant_id, 'conference', $conferenceId, (int) $conference->configuration_generation);
        DB::table('runtime_reconciliation_states')->where('target_type', 'conference')->where('target_id', $conferenceId)->update(['status' => 'waiting', 'next_check_at' => now()->subSecond(), 'lease_owner' => null, 'lease_token' => null, 'lease_expires_at' => null]);
        app(ReconciliationWorker::class)->workOnce('c5-reconcile', 10);
        app(CommandWorker::class)->workOnce('c5-command', 10);
        $this->publishAndNormalize();
        DB::table('runtime_reconciliation_states')->where('target_type', 'conference')->where('target_id', $conferenceId)->update(['status' => 'waiting', 'next_check_at' => now()->subSecond(), 'lease_owner' => null, 'lease_token' => null, 'lease_expires_at' => null]);
        app(ReconciliationWorker::class)->workOnce('c5-reconcile', 10);
    }

    private function runParticipantRuntime(string $participantId): void
    {
        $participant = DB::table('conference_participants')->where('id', $participantId)->first();
        $conference = DB::table('conferences')->where('id', $participant->conference_id)->first();
        $desiredGeneration = ((int) $conference->configuration_generation * 2) + ($participant->desired_state === 'removed' ? 1 : 0);
        app(ReconciliationRepository::class)->ensureTarget((string) $participant->tenant_id, 'conference_participant', $participantId, $desiredGeneration);
        DB::table('runtime_reconciliation_states')->where('target_type', 'conference_participant')->where('target_id', $participantId)->update(['status' => 'waiting', 'next_check_at' => now()->subSecond(), 'lease_owner' => null, 'lease_token' => null, 'lease_expires_at' => null]);
        app(ReconciliationWorker::class)->workOnce('c5-reconcile', 10);
        app(CommandWorker::class)->workOnce('c5-command', 10);
        $this->publishAndNormalize();
    }

    private function publishAndNormalize(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('simulator_scheduled_events')->where('status', 'pending')->update(['due_at' => now()->subSecond()]);
            app(SimulatorEventSourceWorker::class)->workOnce('c5-events', 20);
            app(EventNormalizerWorker::class)->workOnce('c5-normalizer', 20);
        }
    }
}
