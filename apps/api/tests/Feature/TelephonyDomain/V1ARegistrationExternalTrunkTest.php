<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\TelephonyDomain\C7aService;
use App\TelephonyDomain\ExternalTrunkObservedHealthReconciler;
use App\TelephonyDomain\Projection\ExternalTrunkProjectionService;
use App\TelephonyDomain\Signaling\KamailioExternalTrunkRegistrationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class V1ARegistrationExternalTrunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_and_static_endpoints_remain_static_and_static_rejects_registration_intent(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-static@utcp.local.test', 'v1a-static');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Static', 'slug' => 'static'])->assertCreated()->json('external_trunk');
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:static.example.test'])->assertCreated()->json('endpoint');
        $this->assertSame('static', $endpoint['signaling_mode']);
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:bad.example.test', 'registration_target' => 'sip:registrar.example.test'])->assertUnprocessable();
    }

    public function test_registration_requires_credentials_and_all_registration_intent(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-validation@utcp.local.test', 'v1a-validation');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Registration', 'slug' => 'registration'])->assertCreated()->json('external_trunk');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:registrar.example.test', 'signaling_mode' => 'outbound_registration', 'registration_target' => 'sip:registrar.example.test', 'registration_realm' => 'example.test', 'registration_identity' => 'synthetic-user'])->assertUnprocessable();
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:registrar.example.test', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:registrar.example.test', 'registration_realm' => 'example.test', 'registration_identity' => 'synthetic-user'])->assertCreated()->json('endpoint');
        $this->assertSame('outbound_registration', $endpoint['signaling_mode']);
    }

    public function test_external_trunk_reads_expose_safe_registration_observation_for_registration_endpoints_only(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-observation-read@utcp.local.test', 'v1a-observation-read');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Observation read', 'slug' => 'observation-read'])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'safe-user', 'secret' => 'safe-secret'])->assertCreated()->json('credential_reference');
        $registration = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:registrar.example.test', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:registrar.example.test', 'registration_realm' => 'example.test', 'registration_identity' => 'safe-user', 'priority' => 10])->assertCreated()->json('endpoint');
        $static = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:static.example.test', 'priority' => 20])->assertCreated()->json('endpoint');

        $this->assertNull($registration['registration_observation']);
        $this->assertNull($static['registration_observation']);

        DB::table('external_trunk_registration_observations')->insert([
            'trunk_endpoint_id' => $registration['id'],
            'tenant_id' => $tenant,
            'external_trunk_id' => $trunk['id'],
            'state' => 'registered',
            'failure_category' => null,
            'last_attempt_at' => '2026-08-27 10:00:00+00',
            'last_success_at' => '2026-08-27 10:01:00+00',
            'expires_at' => '2026-08-27 10:31:00+00',
            'contact_fingerprint' => str_repeat('a', 64),
            'observation_version' => 7,
            'desired_generation' => 1,
            'created_at' => now(),
            'updated_at' => '2026-08-27 10:02:00+00',
        ]);

        $response = $this->actingAs($admin)->withSession($session)->getJson("/api/v1/admin/external-trunks/{$trunk['id']}")->assertOk();
        $response->assertJsonPath('external_trunk.endpoints.0.registration_observation.state', 'registered');
        $response->assertJsonPath('external_trunk.endpoints.0.registration_observation.observation_version', 7);
        $response->assertJsonPath('external_trunk.endpoints.0.registration_observation.last_success_at', '2026-08-27 10:01:00+00');
        $response->assertJsonPath('external_trunk.endpoints.0.registration_observation.expires_at', '2026-08-27 10:31:00+00');
        $response->assertJsonPath('external_trunk.endpoints.0.registration_observation.observed_at', '2026-08-27 10:02:00+00');
        $response->assertJsonMissing(['contact_fingerprint' => str_repeat('a', 64)]);
        $response->assertJsonMissing(['secret' => 'safe-secret']);
    }

    public function test_t6_registration_projection_contains_intent_and_ha1_only(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-projection@utcp.local.test', 'v1a-projection');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Projection', 'slug' => 'projection'])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:registrar.example.test', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:registrar.example.test', 'registration_realm' => 'example.test', 'registration_identity' => 'synthetic-user'])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        $provider = DB::table('kamailio_external_trunk_registration_view')->first();
        $this->assertNotNull($provider);
        $this->assertSame(md5('synthetic-user:example.test:synthetic-password-123'), $provider->auth_ha1);
        $this->assertSame('', $provider->auth_password);
        $artifact = json_decode((string) DB::table('external_trunk_projection_artifacts')->where('provider', 'kamailio')->value('artifact'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('outbound_registration', $artifact['endpoints'][0]['signaling_mode']);
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-password-123', $encoded);
        $this->assertStringNotContainsString((string) $provider->auth_ha1, $encoded);
    }

    public function test_registration_provider_row_carries_the_registration_identity_as_the_remote_user(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-remote-user@utcp.local.test', 'v1a-remote-user');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Remote User', 'slug' => 'remote-user'])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:203.0.113.10:5060', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:203.0.113.10:5060', 'registration_realm' => 'example-realm', 'registration_identity' => 'synthetic-user'])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);

        $provider = DB::table('kamailio_external_trunk_registration_view')->first();
        $this->assertNotNull($provider);
        // A host-only registrar URI carries no user part. The REGISTER To/From
        // URI must still identify the registration identity, otherwise the
        // provider emits an unparsable "sip:@host" address.
        $this->assertSame('synthetic-user', $provider->r_username);
        $this->assertSame('203.0.113.10', $provider->r_domain);
    }

    public function test_registration_provider_row_prefers_an_explicit_registrar_user_part(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-remote-user-explicit@utcp.local.test', 'v1a-remote-user-explicit');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Remote User Explicit', 'slug' => 'remote-user-explicit'])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:203.0.113.10:5060', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:provider-account@203.0.113.10:5060', 'registration_realm' => 'example-realm', 'registration_identity' => 'synthetic-user'])->assertCreated();
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);

        $provider = DB::table('kamailio_external_trunk_registration_view')->first();
        $this->assertNotNull($provider);
        $this->assertSame('provider-account', $provider->r_username);
    }

    /**
     * Kamailio `uac.reg_dump` publishes registration progress as the uac_reg
     * flag bitmask; it has no textual state, contact or expiry field.
     *
     * @dataProvider registrationFlagCases
     */
    public function test_observer_derives_canonical_state_from_uac_reg_flags(int $flags, string $expected): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-observer-'.$flags.'@utcp.local.test', 'v1a-observer-'.$flags);
        $endpointId = $this->activeRegistrationEndpoint($admin, $tenant, 'observer-'.$flags);

        config(['telephony.kamailio_registration_control_url' => 'http://kamailio-registration-control.test/rpc']);
        Http::fake(['kamailio-registration-control.test/*' => Http::response(['result' => [[
            'l_uuid' => $endpointId,
            'flags' => $flags,
            'timer_expires' => time() + 90,
            'contact_addr' => 'kamailio-sip-internal.test:5060',
        ]]])]);

        $this->assertSame(1, app(KamailioExternalTrunkRegistrationObserver::class)->pollTenant($tenant));
        $observation = DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpointId)->first();
        $this->assertSame($expected, $observation->state);
        $this->assertSame($expected === 'failed' ? 'unreachable' : null, $observation->failure_category);
        if ($expected === 'registered') {
            $this->assertNotNull($observation->last_success_at);
            $this->assertNotNull($observation->expires_at);
            $this->assertSame(hash('sha256', 'kamailio-sip-internal.test:5060'), $observation->contact_fingerprint);
        } else {
            $this->assertNull($observation->last_success_at);
            $this->assertNull($observation->expires_at);
            $this->assertNull($observation->contact_fingerprint);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function registrationFlagCases(): array
    {
        return [
            'online' => [4 | 16, 'registered'],
            'ongoing' => [2 | 16, 'registering'],
            'auth sent' => [8 | 16, 'registering'],
            'disabled' => [1 | 16, 'disabled'],
            'initialised only' => [16, 'failed'],
        ];
    }

    public function test_observer_reports_expired_when_the_registration_timer_has_elapsed(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-observer-expired@utcp.local.test', 'v1a-observer-expired');
        $endpointId = $this->activeRegistrationEndpoint($admin, $tenant, 'observer-expired');

        config(['telephony.kamailio_registration_control_url' => 'http://kamailio-registration-control.test/rpc']);
        Http::fake(['kamailio-registration-control.test/*' => Http::response(['result' => [[
            'l_uuid' => $endpointId,
            'flags' => 4 | 16,
            'timer_expires' => time() - 5,
        ]]])]);

        app(KamailioExternalTrunkRegistrationObserver::class)->pollTenant($tenant);
        $this->assertSame('expired', DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpointId)->value('state'));
    }

    private function activeRegistrationEndpoint(object $admin, string $tenant, string $slug): string
    {
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => $slug, 'slug' => $slug])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'synthetic-user', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:203.0.113.10:5060', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:203.0.113.10:5060', 'registration_realm' => 'example-realm', 'registration_identity' => 'synthetic-user'])->assertCreated()->json('endpoint');
        $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/desired-state", ['desired_state' => 'active'])->assertOk();
        app(KamailioExternalTrunkRegistrationObserver::class)->ensureObservationRows($tenant);

        return $endpoint['id'];
    }

    public function test_observed_health_maps_registration_states_and_recovers_idempotently(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-health-map@utcp.local.test', 'v1a-health-map');
        $endpointId = $this->activeRegistrationEndpoint($admin, $tenant, 'health-map');
        $trunkId = (string) DB::table('trunk_endpoints')->where('id', $endpointId)->value('external_trunk_id');
        $reconciler = app(ExternalTrunkObservedHealthReconciler::class);

        $this->assertSame('unknown', DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
        foreach ([['registering', 'degraded'], ['registered', 'ready'], ['failed', 'unavailable'], ['expired', 'unavailable'], ['registered', 'ready']] as [$state, $health]) {
            DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpointId)->update(['state' => $state]);
            $reconciler->reconcile($tenant, $trunkId);
            $this->assertSame($health, DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
        }
        $before = DB::table('external_trunks')->where('id', $trunkId)->value('updated_at');
        $reconciler->reconcile($tenant, $trunkId);
        $this->assertSame($before, DB::table('external_trunks')->where('id', $trunkId)->value('updated_at'));
    }

    public function test_missing_registration_observation_never_becomes_ready_and_disabled_trunk_stays_ineligible(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-health-missing@utcp.local.test', 'v1a-health-missing');
        $endpointId = $this->activeRegistrationEndpoint($admin, $tenant, 'health-missing');
        $trunkId = (string) DB::table('trunk_endpoints')->where('id', $endpointId)->value('external_trunk_id');
        DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpointId)->delete();
        app(ExternalTrunkObservedHealthReconciler::class)->reconcile($tenant, $trunkId);
        $this->assertSame('unknown', DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
        $this->assertFalse(app(C7aService::class)->routingEligibility($tenant, $trunkId, 'outbound')['eligible']);

        DB::table('external_trunks')->where('id', $trunkId)->update(['desired_state' => 'disabled']);
        app(ExternalTrunkObservedHealthReconciler::class)->reconcile($tenant, $trunkId);
        $this->assertSame('unavailable', DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
        $this->assertSame('external_trunk_inactive', app(C7aService::class)->routingEligibility($tenant, $trunkId, 'outbound')['code']);
    }

    public function test_one_ready_registration_endpoint_makes_a_multi_endpoint_trunk_ready(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-health-aggregate@utcp.local.test', 'v1a-health-aggregate');
        $first = $this->activeRegistrationEndpoint($admin, $tenant, 'health-first');
        $trunkId = (string) DB::table('trunk_endpoints')->where('id', $first)->value('external_trunk_id');
        $second = $this->addRegistrationEndpoint($admin, $tenant, $trunkId, 'health-second');
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        app(KamailioExternalTrunkRegistrationObserver::class)->ensureObservationRows($tenant);
        DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $first)->update(['state' => 'registered']);
        DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $second)->update(['state' => 'failed']);
        app(ExternalTrunkObservedHealthReconciler::class)->reconcile($tenant, $trunkId);
        $this->assertSame('ready', DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
        DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $first)->update(['state' => 'failed']);
        app(ExternalTrunkObservedHealthReconciler::class)->reconcile($tenant, $trunkId);
        $this->assertSame('unavailable', DB::table('external_trunks')->where('id', $trunkId)->value('observed_health'));
    }

    private function addRegistrationEndpoint(object $admin, string $tenant, string $trunkId, string $slug): string
    {
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunkId}/credentials", ['credential_type' => 'sip', 'identifier' => $slug, 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        return $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunkId}/endpoints", ['endpoint_uri' => "sip:{$slug}.registrar.example.test", 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => "sip:{$slug}.registrar.example.test", 'registration_realm' => 'example.test', 'registration_identity' => $slug])->assertCreated()->json('endpoint')['id'];
    }

    public function test_registration_readiness_requires_registered_observation_but_static_ignores_it(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-readiness@utcp.local.test', 'v1a-readiness');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];
        $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => 'Readiness', 'slug' => 'readiness'])->assertCreated()->json('external_trunk');
        $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => 'u', 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
        $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => 'sip:registrar.example.test', 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => 'sip:registrar.example.test', 'registration_realm' => 'example.test', 'registration_identity' => 'u'])->assertCreated()->json('endpoint');
        DB::table('external_trunks')->where('id', $trunk['id'])->update(['desired_state' => 'active']);
        $this->assertFalse(app(C7aService::class)->routingEligibility($tenant, $trunk['id'], 'outbound')['eligible']);
        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        app(KamailioExternalTrunkRegistrationObserver::class)->ensureObservationRows($tenant);
        DB::table('external_trunk_registration_observations')->where('trunk_endpoint_id', $endpoint['id'])->update(['state' => 'registered']);
        app(ExternalTrunkObservedHealthReconciler::class)->reconcile($tenant, $trunk['id']);
        $this->assertTrue(app(C7aService::class)->routingEligibility($tenant, $trunk['id'], 'outbound')['eligible']);
    }

    public function test_two_registration_endpoints_keep_distinct_provider_and_observation_identity(): void
    {
        [$admin, $tenant] = $this->tenantAdmin('v1a-isolation@utcp.local.test', 'v1a-isolation');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenant];

        $endpoints = [];
        foreach ([['first', 'first-user'], ['second', 'second-user']] as [$slug, $identity]) {
            $trunk = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/external-trunks', ['name' => ucfirst($slug), 'slug' => $slug])->assertCreated()->json('external_trunk');
            $credential = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/credentials", ['credential_type' => 'sip', 'identifier' => $identity, 'secret' => 'synthetic-password-123'])->assertCreated()->json('credential_reference');
            $endpoint = $this->actingAs($admin)->withSession($session)->postJson("/api/v1/admin/external-trunks/{$trunk['id']}/endpoints", ['endpoint_uri' => "sip:{$slug}.registrar.example.test", 'signaling_mode' => 'outbound_registration', 'authentication_mode' => 'credentials', 'credential_reference_id' => $credential['id'], 'registration_target' => "sip:{$slug}.registrar.example.test", 'registration_realm' => 'example.test', 'registration_identity' => $identity])->assertCreated()->json('endpoint');
            DB::table('external_trunks')->where('id', $trunk['id'])->update(['desired_state' => 'active']);
            $endpoints[] = [$trunk['id'], $endpoint['id']];
        }

        app(ExternalTrunkProjectionService::class)->projectTenant($tenant);
        app(KamailioExternalTrunkRegistrationObserver::class)->ensureObservationRows($tenant);

        $endpointIds = array_column($endpoints, 1);
        $this->assertSame(2, DB::table('kamailio_external_trunk_registration_view')->whereIn('trunk_endpoint_id', $endpointIds)->count());
        $this->assertSame(2, DB::table('external_trunk_registration_observations')->whereIn('trunk_endpoint_id', $endpointIds)->distinct('trunk_endpoint_id')->count('trunk_endpoint_id'));
        $this->assertCount(2, collect($endpointIds)->unique());
    }

    public function test_registration_reader_role_is_provisioned_before_provider_grant_with_select_only_access(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL role provisioning is only applicable to PostgreSQL.');
        }

        $role = 'utcp_kamailio_registration_reader';
        $this->assertSame(1, (int) DB::selectOne('select count(*) as count from pg_roles where rolname = ?', [$role])->count);
        $this->assertSame(1, (int) DB::selectOne("select has_table_privilege(?, 'kamailio_external_trunk_registration_view', 'select') as allowed", [$role])->allowed);
        $this->assertSame(0, (int) DB::selectOne("select has_table_privilege(?, 'kamailio_external_trunk_registration_view', 'insert') as allowed", [$role])->allowed);
        $this->assertSame(0, (int) DB::selectOne("select has_table_privilege(?, 'kamailio_external_trunk_registration_view', 'update') as allowed", [$role])->allowed);
        $this->assertSame(0, (int) DB::selectOne("select has_table_privilege(?, 'kamailio_external_trunk_registration_view', 'delete') as allowed", [$role])->allowed);
        $this->assertSame(1, (int) DB::selectOne("select has_table_privilege(?, 'version', 'select') as allowed", [$role])->allowed);
    }

    private function tenantAdmin(string $email, string $slug): array
    {
        $user = IdentityIds::new(); $tenant = IdentityIds::new(); $membership = IdentityIds::new();
        DB::table('users')->insert(['id' => $user, 'email' => $email, 'normalized_email' => $email, 'display_name' => 'V1A Admin', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => $slug, 'display_name' => 'V1A Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => $membership, 'user_id' => $user, 'tenant_id' => $tenant, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membership, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        return [\App\Models\User::findOrFail($user), $tenant];
    }
}
