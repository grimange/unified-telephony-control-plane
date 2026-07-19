<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\Models\User;
use App\RuntimeEngine\Commands\CommandWorker;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\RuntimeEngine\Sources\EventSourceRepository;
use App\Simulator\SimulatorEventSourceWorker;
use App\TelephonyDomain\Failover\ConferenceFailoverCoordinator;
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

    public function test_distinct_replacement_query_returns_false_with_only_former_node(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('replacement-query-single');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'replacement-query-single');

        $this->assertFalse(app(TelephonyDomainService::class)->hasDistinctEligibleReplacement($tenantId, $conference['id'], $nodeA));
    }

    public function test_distinct_replacement_query_requires_active_ready_dual_capability_node(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('replacement-query-true');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'replacement-query-true');
        $this->runtimeNode($tenantId, 'replacement-query-b');

        $this->assertTrue(app(TelephonyDomainService::class)->hasDistinctEligibleReplacement($tenantId, $conference['id'], $nodeA));
    }

    public function test_distinct_replacement_query_rejects_ineligible_candidates(): void
    {
        $cases = [
            'draining' => ['ready', 'draining', ['conference.lifecycle', 'conference.participation']],
            'disabled' => ['ready', 'disabled', ['conference.lifecycle', 'conference.participation']],
            'degraded' => ['degraded', 'active', ['conference.lifecycle', 'conference.participation']],
            'unavailable' => ['unavailable', 'active', ['conference.lifecycle', 'conference.participation']],
            'stale' => ['stale', 'active', ['conference.lifecycle', 'conference.participation']],
            'missing-lifecycle' => ['ready', 'active', ['conference.participation']],
            'missing-participation' => ['ready', 'active', ['conference.lifecycle']],
        ];

        foreach ($cases as $slug => [$observedState, $desiredState, $capabilities]) {
            [$admin, , $tenantId, $nodeA] = $this->fixture('replacement-query-'.$slug);
            $conference = $this->openConference($admin, $tenantId, $nodeA, 'replacement-query-'.$slug);
            $this->runtimeNode($tenantId, 'replacement-query-'.$slug.'-b', $observedState, $desiredState, $capabilities);

            $this->assertFalse(app(TelephonyDomainService::class)->hasDistinctEligibleReplacement($tenantId, $conference['id'], $nodeA), $slug);
        }

        [$adminA, , $tenantA, $nodeA] = $this->fixture('replacement-query-wrong-tenant-a');
        [, , $tenantB] = $this->fixture('replacement-query-wrong-tenant-b');
        $conference = $this->openConference($adminA, $tenantA, $nodeA, 'replacement-query-wrong-tenant');
        $this->runtimeNode($tenantB, 'replacement-query-wrong-tenant-b');

        $this->assertFalse(app(TelephonyDomainService::class)->hasDistinctEligibleReplacement($tenantA, $conference['id'], $nodeA), 'wrong-tenant');
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

    public function test_failover_coordinator_rebinds_sustained_unavailable_open_conference_and_wakes_reconciliation(): void
    {
        [$admin, $member, $tenantId, $nodeA] = $this->fixture('coordinator-success');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-success-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-success');
        $participant = $this->admitParticipantFor($member, $tenantId, $conference['id']);
        DB::table('runtime_reconciliation_states')->where('target_id', $conference['id'])->update(['status' => 'converged', 'next_check_at' => now()->addHour()]);
        DB::table('runtime_reconciliation_states')->where('target_id', $participant['id'])->update(['status' => 'converged', 'next_check_at' => now()->addHour()]);
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(301), 'coordinator-success-old-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $requested = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-success-worker', 10);
        $this->assertSame(1, $requested['verification_requested']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-success-worker-2', 10);

        $updated = DB::table('conferences')->where('id', $conference['id'])->first();
        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(1, $summary['eligible']);
        $this->assertSame(1, $summary['rebound']);
        $this->assertSame($nodeB, $updated->runtime_node_id);
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) $updated->configuration_generation);
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseHas('conference_runtime_bindings', ['conference_id' => $conference['id'], 'runtime_node_id' => $nodeA, 'status' => 'retired']);
        $this->assertDatabaseHas('conference_runtime_bindings', ['conference_id' => $conference['id'], 'runtime_node_id' => $nodeB, 'status' => 'active']);
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
        $this->assertDatabaseHas('control_plane_audit_records', ['action' => 'conference.failover_coordinator.verification_requested']);
        $this->assertDatabaseHas('control_plane_audit_records', ['action' => 'conference.failover_coordinator.rebound']);
        $this->assertDatabaseHas('control_plane_audit_records', ['action' => 'conference.runtime_binding_replaced']);
    }

    public function test_failover_coordinator_does_not_rebind_unavailable_node_within_grace(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-grace');
        $this->runtimeNode($tenantId, 'coordinator-grace-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-grace');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(299), 'coordinator-grace-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-grace-worker', 10);

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
    }

    public function test_failover_coordinator_excludes_degraded_and_connecting_bound_nodes(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-degraded');
        $this->runtimeNode($tenantId, 'coordinator-degraded-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-degraded');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-degraded-ready');

        foreach (['degraded', 'connecting'] as $state) {
            DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => $state, 'updated_at' => now()]);
            $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-'.$state.'-worker', 10);
            $this->assertSame(0, $summary['candidates']);
            $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        }
    }

    public function test_failover_coordinator_maps_no_replacement_safely_and_continues_sweep(): void
    {
        [$adminA, , $tenantA, $nodeA] = $this->fixture('coordinator-no-replacement-a');
        $conferenceA = $this->openConference($adminA, $tenantA, $nodeA, 'coordinator-no-replacement-a');
        $this->readyObservation($tenantA, $nodeA, now()->subSeconds(600), 'coordinator-no-replacement-ready-a');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        [$adminB, , $tenantB, $nodeB] = $this->fixture('coordinator-no-replacement-b');
        $replacementB = $this->runtimeNode($tenantB, 'coordinator-no-replacement-b2');
        $conferenceB = $this->openConference($adminB, $tenantB, $nodeB, 'coordinator-no-replacement-b');
        $this->readyObservation($tenantB, $nodeB, now()->subSeconds(600), 'coordinator-no-replacement-ready-b');
        DB::table('runtime_nodes')->where('id', $nodeB)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $requested = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-no-replacement-worker', 10);
        $this->assertSame(2, $requested['verification_requested']);
        $this->completeFenceOperationForConference($tenantA, $conferenceA['id'], 'absent');
        $this->completeFenceOperationForConference($tenantB, $conferenceB['id'], 'absent');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-no-replacement-worker-2', 10);

        $this->assertSame(2, $summary['candidates']);
        $this->assertSame(1, $summary['no_replacement']);
        $this->assertSame(1, $summary['rebound']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conferenceA['id'])->value('runtime_node_id'));
        $this->assertSame($replacementB, DB::table('conferences')->where('id', $conferenceB['id'])->value('runtime_node_id'));
        $this->assertDatabaseHas('control_plane_audit_records', ['action' => 'conference.failover_coordinator.no_replacement']);
    }

    public function test_failover_coordinator_second_sweep_is_idempotent_after_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-idempotent');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-idempotent-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-idempotent');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-idempotent-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $requested = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-idempotent-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');
        $first = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-idempotent-second', 10);
        $generationAfterFirst = (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation');
        $second = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-idempotent-third', 10);

        $this->assertSame(1, $requested['verification_requested']);
        $this->assertSame(1, $first['rebound']);
        $this->assertSame(0, $second['candidates']);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame($generationAfterFirst, (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
    }

    public function test_failover_coordinator_revalidates_ready_recovery_before_cutoff(): void
    {
        $this->assertFailoverCoordinatorRevalidatesRecoveredStateBeforeCutoff('ready');
    }

    public function test_failover_coordinator_revalidates_degraded_recovery_before_cutoff(): void
    {
        $this->assertFailoverCoordinatorRevalidatesRecoveredStateBeforeCutoff('degraded');
    }

    public function test_failover_coordinator_excludes_draining_replacements(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-draining');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-draining-b', 'ready', 'draining');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-draining');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-draining-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        $requested = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-draining-worker', 10);
        $this->assertSame(1, $requested['verification_requested']);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-draining-worker-2', 10);

        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(1, $summary['no_replacement']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertDatabaseMissing('conference_runtime_bindings', [
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
        ]);
    }

    public function test_failover_coordinator_does_not_duplicate_pending_absence_verification(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-pending-fence');
        $this->runtimeNode($tenantId, 'coordinator-pending-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-pending-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-pending-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $first = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-pending-fence-first', 10);
        $second = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-pending-fence-second', 10);

        $this->assertSame(1, $first['verification_requested']);
        $this->assertSame(1, $second['verification_waiting']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.verify_conference_absent')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertDatabaseMissing('control_plane_audit_records', ['action' => 'conference.failover_coordinator.rebound']);
    }

    public function test_present_absence_verification_requests_external_fence_without_rebinding(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-present-fence');
        $this->runtimeNode($tenantId, 'coordinator-present-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-present-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-present-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-present-fence-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present', ['bridge_present' => true]);
        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-present-fence-second', 10);

        $this->assertSame(1, $summary['runtime_fence_requested']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertDatabaseMissing('control_plane_audit_records', ['action' => 'conference.runtime_binding_replaced']);
    }

    public function test_unavailable_absence_verification_requests_external_fence_without_rebinding(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-unavailable-fence');
        $this->runtimeNode($tenantId, 'coordinator-unavailable-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-unavailable-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-unavailable-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-unavailable-fence-first', 10);
        DB::table('runtime_operations')
            ->where('operation_type', 'runtime.node.verify_conference_absent')
            ->where('aggregate_id', $conference['id'])
            ->update([
                'status' => 'retry_scheduled',
                'last_failure_class' => 'runtime_unavailable',
                'last_failure_code' => 'ari_unreachable',
                'available_at' => now()->addMinute(),
                'updated_at' => now(),
            ]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-unavailable-fence-second', 10);

        $this->assertSame(1, $summary['runtime_fence_requested']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertDatabaseMissing('control_plane_audit_records', ['action' => 'conference.runtime_binding_replaced']);
    }

    public function test_terminal_retry_exhausted_absence_verification_requests_external_fence_on_next_sweep(): void
    {
        [$tenantId, $nodeA, , $conference] = $this->terminalVerificationFixture('coordinator-terminal-fence-real');
        $this->setSimulatorScenario($nodeA, 'transient-failure-then-ready', ['transient_attempts' => 3]);

        $first = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fence-real-first', 10);
        $this->assertSame(1, $first['verification_requested']);
        $verification = $this->exhaustVerificationOperation($conference['id']);
        $this->assertSame('terminal_failed', (string) $verification->status);
        $this->assertSame(3, (int) $verification->attempt_count);
        $this->assertSame('transient_transport', (string) $verification->last_failure_class);
        $this->assertSame('simulator_transient_failure', (string) $verification->last_failure_code);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fence-real-second', 10);

        $this->assertSame(1, $summary['runtime_fence_requested']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.verify_conference_absent')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $fence = DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->first();
        $this->assertNotNull($fence);
        $payload = json_decode((string) $fence->payload, true, 512, JSON_THROW_ON_ERROR);
        $bindingId = (string) DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->value('id');
        $this->assertSame('verification_unavailable', $payload['fence_reason']);
        $this->assertSame((string) $verification->id, $payload['verification_operation_id']);
        $this->assertSame($conference['id'], $payload['conference_id']);
        $this->assertSame($nodeA, $payload['former_runtime_node_id']);
        $this->assertSame($bindingId, $payload['former_runtime_binding_id']);
        $this->assertSame((int) $conference['configuration_generation'], $payload['configuration_generation']);
        $this->assertDatabaseHas('runtime_operations', [
            'id' => (string) $verification->id,
            'status' => 'terminal_failed',
            'last_failure_class' => 'transient_transport',
            'last_failure_code' => 'simulator_transient_failure',
        ]);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertDatabaseMissing('control_plane_audit_records', ['action' => 'conference.runtime_binding_replaced']);
        unset($tenantId);
    }

    public function test_terminal_retry_exhausted_absence_verification_sweeps_are_idempotent(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-fence-idempotent');
        $this->forceTerminalVerificationFailure($conference['id'], 'runtime_unavailable', 'ari_http_transport_failed');

        $first = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fence-idempotent-second', 10);
        $second = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fence-idempotent-third', 10);
        $third = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fence-idempotent-fourth', 10);

        $this->assertSame(1, $first['runtime_fence_requested']);
        $this->assertSame(1, $second['runtime_fence_waiting']);
        $this->assertSame(1, $third['runtime_fence_waiting']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.verify_conference_absent')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->distinct()->count('idempotency_key'));
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('aggregate_id', $conference['id'])->where('event_type', 'conference.failover_coordinator.runtime_fence_requested')->count());
    }

    public function test_terminal_non_retryable_absence_verification_remains_verification_failed(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-invalid');
        $this->forceTerminalVerificationFailure($conference['id'], 'invalid_request', 'absence_verification_context_not_found');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-invalid-second', 10);

        $this->assertSame(1, $summary['verification_failed']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_unknown_absence_verification_failure_does_not_fence(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-unknown');
        $this->forceTerminalVerificationFailure($conference['id'], 'future_retryable_transport', 'future_retryable_transport');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-unknown-second', 10);

        $this->assertSame(1, $summary['verification_failed']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_internal_error_absence_verification_failure_does_not_fence_without_unavailability_classification(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-internal');
        $this->forceTerminalVerificationFailure($conference['id'], 'internal_error', 'malformed_verification_evidence');

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-internal-second', 10);

        $this->assertSame(1, $summary['verification_failed']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_retry_exhausted_absence_verification_cannot_fence_after_conference_closes(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-closed');
        $this->forceTerminalVerificationFailure($conference['id'], 'runtime_unavailable', 'ari_http_unavailable');
        DB::table('conferences')->where('id', $conference['id'])->update(['desired_state' => 'closed', 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-closed-second', 10);

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_retry_exhausted_absence_verification_cannot_fence_after_binding_replacement(): void
    {
        [$tenantId, , $nodeB, $conference] = $this->terminalVerificationFixture('coordinator-terminal-rebound');
        $this->forceTerminalVerificationFailure($conference['id'], 'runtime_unavailable', 'ari_connection_failed');
        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->update(['status' => 'retired', 'unbound_at' => now(), 'updated_at' => now()]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->where('id', $conference['id'])->update(['runtime_node_id' => $nodeB, 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-rebound-second', 10);

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_retry_exhausted_absence_verification_cannot_fence_after_generation_advances(): void
    {
        [, , , $conference] = $this->terminalVerificationFixture('coordinator-terminal-generation');
        $this->forceTerminalVerificationFailure($conference['id'], 'timeout', 'ari_connection_timeout');
        DB::table('conferences')->where('id', $conference['id'])->update(['configuration_generation' => DB::raw('configuration_generation + 1'), 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-generation-second', 10);

        $this->assertSame(1, $summary['verification_requested']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_retry_exhausted_absence_verification_cannot_fence_after_runtime_recovers(): void
    {
        [, $nodeA, , $conference] = $this->terminalVerificationFixture('coordinator-terminal-recovered');
        $this->forceTerminalVerificationFailure($conference['id'], 'runtime_unavailable', 'ari_http_transport_failed');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'ready', 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-recovered-second', 10);

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
    }

    public function test_terminal_retry_exhausted_absence_verification_without_replacement_does_not_fence(): void
    {
        [, , $nodeB, $conference] = $this->terminalVerificationFixture('coordinator-terminal-no-replacement');
        $this->forceTerminalVerificationFailure($conference['id'], 'runtime_unavailable', 'ari_http_unavailable');
        DB::table('runtime_nodes')->where('id', $nodeB)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-no-replacement-second', 10);

        $this->assertSame(1, $summary['no_replacement']);
        $this->assertSame(0, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $this->assertDatabaseMissing('control_plane_audit_records', ['action' => 'conference.runtime_binding_replaced']);
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
    }

    public function test_external_runtime_fenced_evidence_authorizes_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-external-fence');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-external-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-external-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-external-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-external-fence-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present', ['bridge_present' => true]);
        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-external-fence-second', 10);
        $runtimeFenceOperationId = $this->completeRuntimeFenceOperationForConference($tenantId, $conference['id'], 'fenced');

        $result = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'external fence evidence test'),
            $tenantId,
            $conference['id'],
            $runtimeFenceOperationId,
            'external_runtime_fenced',
        );

        $this->assertSame('rebound', $result['status']);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
    }

    public function test_external_runtime_fence_sweeps_are_idempotent_before_completion(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-runtime-fence-idempotent');
        $this->runtimeNode($tenantId, 'coordinator-runtime-fence-idempotent-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-runtime-fence-idempotent');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-runtime-fence-idempotent-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-idempotent-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present');
        $first = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-idempotent-second', 10);
        $second = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-idempotent-third', 10);

        $this->assertSame(1, $first['runtime_fence_requested']);
        $this->assertSame(1, $second['runtime_fence_waiting']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.runtime.fence')->where('aggregate_id', $conference['id'])->count());
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
    }

    public function test_coordinator_maps_runtime_fence_no_replacement_without_fence_evidence_or_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-runtime-fence-no-replacement');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-runtime-fence-no-replacement-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-runtime-fence-no-replacement');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-runtime-fence-no-replacement-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-no-replacement-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present');
        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-no-replacement-second', 10);
        DB::table('runtime_nodes')->where('id', $nodeB)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);
        DB::table('runtime_operations')
            ->where('operation_type', 'runtime.node.runtime.fence')
            ->where('aggregate_id', $conference['id'])
            ->update([
                'status' => 'retry_scheduled',
                'last_failure_class' => 'runtime_unavailable',
                'last_failure_code' => 'no_replacement_available',
                'available_at' => now()->addMinute(),
                'updated_at' => now(),
            ]);

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-runtime-fence-no-replacement-third', 10);

        $this->assertSame(1, $summary['no_replacement']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
        $this->assertDatabaseMissing('control_plane_outbox_messages', [
            'aggregate_id' => $conference['id'],
            'event_type' => 'conference.runtime_fence_terminated',
        ]);
    }

    public function test_stale_external_runtime_fence_binding_evidence_is_rejected(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-stale-external-fence');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-stale-external-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-stale-external-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-stale-external-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-stale-external-fence-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present');
        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-stale-external-fence-second', 10);
        $operationId = $this->completeRuntimeFenceOperationForConference($tenantId, $conference['id'], 'fenced');
        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->update(['status' => 'retired', 'unbound_at' => now(), 'updated_at' => now()]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => $currentBindingId = IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->where('id', $conference['id'])->update(['runtime_node_id' => $nodeB, 'updated_at' => now()]);
        $this->readyObservation($tenantId, $nodeB, now()->subSeconds(600), 'coordinator-stale-external-fence-ready-b');
        DB::table('runtime_nodes')->where('id', $nodeB)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $result = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'stale external fence test'),
            $tenantId,
            $conference['id'],
            $operationId,
            'stale_external_runtime_fenced',
            [
                'expected_binding_id' => $currentBindingId,
                'expected_runtime_node_id' => $nodeB,
                'qualifying_bound_states' => ['unavailable', 'stale'],
                'ready_observation_grace_seconds' => 300,
            ],
        );

        $this->assertSame(['status' => 'noop', 'reason' => 'fence_evidence_stale'], $result);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
    }

    public function test_only_verified_absence_or_external_fenced_evidence_authorizes_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-dual-evidence');
        $this->runtimeNode($tenantId, 'coordinator-dual-evidence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-dual-evidence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-dual-evidence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'stale', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-dual-evidence-first', 10);
        $this->completeFenceOperationForConference($tenantId, $conference['id'], 'present');
        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-dual-evidence-second', 10);
        $operationId = $this->completeRuntimeFenceOperationForConference($tenantId, $conference['id'], 'fence_in_progress');

        $result = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'dual evidence negative test'),
            $tenantId,
            $conference['id'],
            $operationId,
        );

        $this->assertSame(['status' => 'noop', 'reason' => 'fence_evidence_not_authoritative'], $result);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
    }

    public function test_stale_binding_absence_evidence_is_rejected_before_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-stale-binding-fence');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-stale-binding-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-stale-binding-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-stale-binding-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-stale-binding-fence-first', 10);
        $operationId = $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');
        DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->update(['status' => 'retired', 'unbound_at' => now(), 'updated_at' => now()]);
        DB::table('conference_runtime_bindings')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'conference_id' => $conference['id'],
            'runtime_node_id' => $nodeB,
            'status' => 'active',
            'bound_at' => now(),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('conferences')->where('id', $conference['id'])->update(['runtime_node_id' => $nodeB, 'updated_at' => now()]);
        $this->readyObservation($tenantId, $nodeB, now()->subSeconds(600), 'coordinator-stale-binding-fence-ready-b');
        DB::table('runtime_nodes')->where('id', $nodeB)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        $result = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'stale binding fence test'),
            $tenantId,
            $conference['id'],
            $operationId,
            options: ['expected_binding_id' => DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->value('id')],
        );

        $this->assertSame(['status' => 'noop', 'reason' => 'fence_evidence_stale'], $result);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
    }

    public function test_stale_generation_absence_evidence_is_rejected_before_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-stale-generation-fence');
        $this->runtimeNode($tenantId, 'coordinator-stale-generation-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-stale-generation-fence');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-stale-generation-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-stale-generation-fence-first', 10);
        $operationId = $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');
        DB::table('conferences')->where('id', $conference['id'])->update(['configuration_generation' => DB::raw('configuration_generation + 1'), 'updated_at' => now()]);

        $result = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'stale generation fence test'),
            $tenantId,
            $conference['id'],
            $operationId,
        );

        $this->assertSame(['status' => 'noop', 'reason' => 'fence_evidence_stale'], $result);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
    }

    public function test_same_absent_evidence_produces_one_authoritative_rebind(): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-concurrent-fence');
        $nodeB = $this->runtimeNode($tenantId, 'coordinator-concurrent-fence-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-concurrent-fence');
        $bindingId = (string) DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->value('id');
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-concurrent-fence-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-concurrent-fence-request', 10);
        $operationId = $this->completeFenceOperationForConference($tenantId, $conference['id'], 'absent');
        $first = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'first fence consumption'),
            $tenantId,
            $conference['id'],
            $operationId,
            options: ['expected_binding_id' => $bindingId, 'expected_runtime_node_id' => $nodeA],
        );
        $second = app(TelephonyDomainService::class)->failoverRebindConferenceAfterFence(
            ExecutionContext::system(tenantId: $tenantId, reason: 'second fence consumption'),
            $tenantId,
            $conference['id'],
            $operationId,
            options: ['expected_binding_id' => $bindingId, 'expected_runtime_node_id' => $nodeA],
        );

        $this->assertSame('rebound', $first['status']);
        $this->assertSame(['status' => 'noop', 'reason' => 'active_binding_changed'], $second);
        $this->assertSame($nodeB, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'] + 1, (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
        $this->assertSame(1, DB::table('conference_runtime_bindings')->where('conference_id', $conference['id'])->where('status', 'active')->count());
    }

    public function test_failover_coordinator_has_no_direct_rebind_path(): void
    {
        $source = file_get_contents(app_path('TelephonyDomain/Failover/ConferenceFailoverCoordinator.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('RuntimeFencingCoordinator', $source);
        $this->assertStringNotContainsString('failoverRebindConference(', $source);
        $this->assertStringNotContainsString('failoverRebindConferenceAfterFence(', $source);
    }

    public function test_failover_coordinator_command_and_scheduler_registration_are_bounded(): void
    {
        $this->artisan('telephony-domain:failover-coordinator', ['--once' => true])
            ->expectsOutput('telephony_domain_failover_candidates=0')
            ->assertExitCode(0);

        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($console);
        $this->assertStringContainsString('telephony-domain:failover-coordinator {--once', $console);
        $this->assertStringContainsString("Schedule::command('telephony-domain:failover-coordinator --once')->everyMinute()->withoutOverlapping()", $console);
        $this->assertStringNotContainsString('--conference', $console);
        $this->assertStringNotContainsString('--runtime-node', $console);
        $this->assertStringNotContainsString('--replacement', $console);
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

    private function assertFailoverCoordinatorRevalidatesRecoveredStateBeforeCutoff(string $recoveredState): void
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture('coordinator-recovered-'.$recoveredState);
        $this->runtimeNode($tenantId, 'coordinator-recovered-'.$recoveredState.'-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, 'coordinator-recovered-'.$recoveredState);
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), 'coordinator-recovered-'.$recoveredState.'-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);
        $changed = false;
        DB::listen(function (object $query) use (&$changed, $nodeA, $recoveredState): void {
            if ($changed || ! str_contains($query->sql, 'conference_runtime_bindings') || ! str_contains($query->sql, 'runtime_observations')) {
                return;
            }
            $changed = true;
            DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => $recoveredState, 'updated_at' => now()]);
        });

        $summary = app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-recovered-'.$recoveredState, 10);

        $this->assertTrue($changed);
        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(1, $summary['recovered_before_cutoff']);
        $this->assertSame($nodeA, DB::table('conferences')->where('id', $conference['id'])->value('runtime_node_id'));
        $this->assertSame((int) $conference['configuration_generation'], (int) DB::table('conferences')->where('id', $conference['id'])->value('configuration_generation'));
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    private function terminalVerificationFixture(string $slug): array
    {
        [$admin, , $tenantId, $nodeA] = $this->fixture($slug);
        $nodeB = $this->runtimeNode($tenantId, $slug.'-b');
        $conference = $this->openConference($admin, $tenantId, $nodeA, $slug);
        $this->readyObservation($tenantId, $nodeA, now()->subSeconds(600), $slug.'-ready');
        DB::table('runtime_nodes')->where('id', $nodeA)->update(['observed_state' => 'unavailable', 'updated_at' => now()]);

        return [$tenantId, $nodeA, $nodeB, $conference];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    private function setSimulatorScenario(string $runtimeNodeId, string $scenario, array $parameters = []): void
    {
        DB::table('simulator_profiles')->where('runtime_node_id', $runtimeNodeId)->update([
            'scenario_key' => $scenario,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        DB::table('simulator_states')->where('runtime_node_id', $runtimeNodeId)->update([
            'attempt_count' => 0,
            'updated_at' => now(),
        ]);
    }

    private function exhaustVerificationOperation(string $conferenceId): object
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(CommandWorker::class)->workOnce(
                'coordinator-terminal-fence-command-'.$attempt,
                1,
                60,
                ['runtime.node.verify_conference_absent'],
            );

            if ($attempt < 3) {
                $this->travel($attempt === 1 ? 16 : 31)->seconds();
            }
        }

        $operation = DB::table('runtime_operations')
            ->where('operation_type', 'runtime.node.verify_conference_absent')
            ->where('aggregate_id', $conferenceId)
            ->first();
        $this->assertNotNull($operation);

        return $operation;
    }

    private function forceTerminalVerificationFailure(string $conferenceId, string $failureClass, string $failureCode): object
    {
        app(ConferenceFailoverCoordinator::class)->sweepOnce('coordinator-terminal-fixture-first', 10);
        $operation = DB::table('runtime_operations')
            ->where('operation_type', 'runtime.node.verify_conference_absent')
            ->where('aggregate_id', $conferenceId)
            ->first();
        $this->assertNotNull($operation);

        DB::table('runtime_operations')->where('id', $operation->id)->update([
            'status' => 'terminal_failed',
            'attempt_count' => 3,
            'last_failure_class' => $failureClass,
            'last_failure_code' => $failureCode,
            'last_failure_message' => 'terminal verification fixture failure',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('runtime_operations')->where('id', $operation->id)->first();
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

    private function readyObservation(string $tenantId, string $runtimeNodeId, mixed $receivedAt, string $externalKey): void
    {
        $source = app(EventSourceRepository::class)->ensureRuntimeNodeSource($tenantId, $runtimeNodeId, 'simulator-deterministic');
        $epochId = EngineIds::new();
        $receiptId = EngineIds::new();
        DB::table('runtime_event_connection_epochs')->insert([
            'id' => $epochId,
            'event_source_id' => $source->id,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'adapter_key' => 'simulator-deterministic',
            'status' => 'closed',
            'owner' => 'coordinator-test',
            'fencing_token' => EngineIds::token(),
            'opened_at' => $receivedAt,
            'closed_at' => $receivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_event_receipts')->insert([
            'id' => $receiptId,
            'event_source_id' => $source->id,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'adapter_key' => 'simulator-deterministic',
            'connection_epoch_id' => $epochId,
            'external_event_key' => $externalKey,
            'event_type' => 'test.runtime.ready',
            'event_version' => 1,
            'payload_hash' => hash('sha256', $externalKey),
            'sanitized_payload' => json_encode(['state' => 'ready'], JSON_THROW_ON_ERROR),
            'occurred_at' => $receivedAt,
            'received_at' => $receivedAt,
            'status' => 'processed',
            'available_at' => $receivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_observations')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'runtime_node_id' => $runtimeNodeId,
            'observation_type' => 'runtime.node.readiness',
            'observation_version' => 1,
            'subject_type' => 'runtime_node',
            'subject_id' => $runtimeNodeId,
            'observed_state' => 'ready',
            'source_event_id' => $receiptId,
            'source_connection_epoch' => $epochId,
            'configuration_version' => 1,
            'observed_at' => $receivedAt,
            'received_at' => $receivedAt,
            'payload' => json_encode(['state' => 'ready'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function completeFenceOperationForConference(string $tenantId, string $conferenceId, string $result, array $overrides = []): string
    {
        $operation = DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'conference')
            ->where('aggregate_id', $conferenceId)
            ->where('operation_type', 'runtime.node.verify_conference_absent')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($operation, 'Expected a pending conference absence verification operation.');
        $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $evidence = array_merge([
            'operation_id' => (string) $operation->id,
            'adapter_key' => 'simulator-deterministic',
            'operation_type' => 'runtime.node.verify_conference_absent',
            'conference_id' => (string) $payload['conference_id'],
            'former_runtime_binding_id' => (string) $payload['former_runtime_binding_id'],
            'former_runtime_node_id' => (string) $payload['former_runtime_node_id'],
            'configuration_generation' => (int) $payload['configuration_generation'],
            'verification_result' => $result,
            'runtime_reference_present' => $result === 'present',
            'bridge_present' => (bool) ($overrides['bridge_present'] ?? false),
            'participant_channel_present' => (bool) ($overrides['participant_channel_present'] ?? false),
        ], $overrides);

        DB::table('runtime_operations')->where('id', $operation->id)->update([
            'status' => 'succeeded',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('control_plane_outbox_messages')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'aggregate_type' => 'conference',
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_verified',
            'event_version' => 1,
            'payload' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'correlation_id' => (string) $operation->correlation_id,
            'causation_id' => $operation->causation_id,
            'request_id' => (string) $operation->request_id,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'dispatch_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $operation->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function completeRuntimeFenceOperationForConference(string $tenantId, string $conferenceId, string $result, array $overrides = []): string
    {
        $operation = DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'conference')
            ->where('aggregate_id', $conferenceId)
            ->where('operation_type', 'runtime.node.runtime.fence')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($operation, 'Expected a pending external runtime fence operation.');
        $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $evidence = array_merge([
            'operation_id' => (string) $operation->id,
            'operation_type' => 'runtime.node.runtime.fence',
            'conference_id' => (string) $payload['conference_id'],
            'former_runtime_binding_id' => (string) $payload['former_runtime_binding_id'],
            'former_runtime_node_id' => (string) $payload['former_runtime_node_id'],
            'configuration_generation' => (int) $payload['configuration_generation'],
            'fence_result' => $result,
            'infrastructure_adapter' => 'kubernetes',
            'desired_replicas' => 0,
            'status_replicas' => 0,
            'available_replicas' => 0,
            'owned_pods_remaining' => 0,
        ], $overrides);

        DB::table('runtime_operations')->where('id', $operation->id)->update([
            'status' => 'succeeded',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('control_plane_outbox_messages')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'aggregate_type' => 'conference',
            'aggregate_id' => $conferenceId,
            'event_type' => 'conference.runtime_fence_terminated',
            'event_version' => 1,
            'payload' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'correlation_id' => (string) $operation->correlation_id,
            'causation_id' => $operation->causation_id,
            'request_id' => (string) $operation->request_id,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempt_count' => 0,
            'dispatch_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $operation->id;
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
