<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\PayloadSafety;
use App\Identity\IdentityIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MediaArchiveTargetAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_catalog_and_credential_authority_are_tenant_scoped_and_safe(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('archive-admin@utcp.local.test', 'archive-tenant');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('archive-other@utcp.local.test', 'archive-other');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $otherSession = ['user_session_version' => 1, 'active_tenant_id' => $otherTenantId];
        $payload = ['name' => 'Primary archive', 'slug' => 'primary-archive', 'description' => null, 'target_kind' => 's3_compatible', 'endpoint_url' => 'https://storage.example.test', 'region' => 'us-east-1', 'bucket' => 'utcp-recordings', 'object_prefix' => 'tenant-a/'];

        $target = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/recording-archive-targets', $payload, ['Idempotency-Key' => 'archive-target-key'])->assertCreated()->assertJsonPath('recording_archive_target.desired_state', 'draft')->assertJsonPath('recording_archive_target.credential_reference', null)->json('recording_archive_target');
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/recording-archive-targets', $payload, ['Idempotency-Key' => 'archive-target-key'])->assertCreated()->assertJsonPath('recording_archive_target.id', $target['id']);

        $credential = $this->actingAs($admin)->withSession($session)->putJson('/api/v1/admin/recording-archive-targets/'.$target['id'].'/credential', ['identifier' => 'ACCESS_KEY_ID', 'secret' => 'archive-secret-value'], ['Idempotency-Key' => 'archive-credential-key'])->assertOk()->assertJsonMissing(['secret' => 'archive-secret-value'])->assertJsonMissing(['encrypted_secret' => true])->json('recording_archive_target.credential_reference');
        $row = DB::table('media_archive_credential_references')->where('id', $credential['id'])->first();
        $this->assertNotSame('archive-secret-value', $row->encrypted_secret);
        $this->assertSame('archive-secret-value', Crypt::decryptString($row->encrypted_secret));
        $this->assertSame(hash('sha256', 'archive-secret-value'), $row->secret_fingerprint);
        $this->assertSame(1, DB::table('media_archive_credential_references')->where('media_archive_target_id', $target['id'])->count());

        $this->actingAs($admin)->withSession($session)->putJson('/api/v1/admin/recording-archive-targets/'.$target['id'].'/credential', ['identifier' => 'NEW_ACCESS_KEY', 'secret' => 'replacement-secret'], ['Idempotency-Key' => 'archive-credential-replacement'])->assertOk()->assertJsonPath('recording_archive_target.credential_reference.id', $credential['id']);
        $this->assertSame(1, DB::table('media_archive_credential_references')->where('media_archive_target_id', $target['id'])->count());
        $this->assertSame('NEW_ACCESS_KEY', DB::table('media_archive_credential_references')->where('id', $credential['id'])->value('identifier'));

        $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/recording-archive-targets/'.$target['id'])->assertOk()->assertJsonPath('recording_archive_target.credential_reference.identifier', 'NEW_ACCESS_KEY');
        $this->actingAs($otherAdmin)->withSession($otherSession)->getJson('/api/v1/admin/recording-archive-targets/'.$target['id'])->assertNotFound();
        $this->actingAs($otherAdmin)->withSession($otherSession)->patchJson('/api/v1/admin/recording-archive-targets/'.$target['id'], ['name' => 'cross-tenant'])->assertNotFound();
        $this->actingAs($otherAdmin)->withSession($otherSession)->putJson('/api/v1/admin/recording-archive-targets/'.$target['id'].'/credential', ['secret' => 'cross-tenant-secret'])->assertNotFound();

        $audit = DB::table('control_plane_audit_records')->where('action', 'media_archive_target.credential_set')->latest('created_at')->value('metadata');
        $outbox = DB::table('control_plane_outbox_messages')->where('event_type', 'media_archive_target.credential_set')->latest('created_at')->value('payload');
        $this->assertStringNotContainsString('replacement-secret', (string) $audit);
        $this->assertStringNotContainsString('replacement-secret', (string) $outbox);
        $this->assertStringContainsString($credential['id'], (string) $outbox);
    }

    public function test_target_lifecycle_requires_credential_and_retirement_is_terminal(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('archive-lifecycle@utcp.local.test', 'archive-lifecycle');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $target = $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/recording-archive-targets', $this->targetPayload('lifecycle'))->assertCreated()->json('recording_archive_target');
        $url = '/api/v1/admin/recording-archive-targets/'.$target['id'];

        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'active'])->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)->putJson($url.'/credential', ['secret' => 'lifecycle-secret'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'disabled'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'active'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'retired'])->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson($url.'/desired-state', ['desired_state' => 'active'])->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)->patchJson($url, ['name' => 'changed-after-retirement'])->assertUnprocessable();
        $this->actingAs($admin)->withSession($session)->putJson($url.'/credential', ['secret' => 'another-secret'])->assertUnprocessable();
    }

    public function test_view_capability_does_not_authorize_mutation_and_missing_tenant_is_conflict(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('archive-view@utcp.local.test', 'archive-view');
        $session = ['user_session_version' => 1, 'active_tenant_id' => $tenantId];
        $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/recording-archive-targets')->assertOk();

        $membership = DB::table('tenant_memberships')->where('user_id', $admin->id)->first();
        DB::table('tenant_role_assignments')->where('membership_id', $membership->id)->update(['role_key' => 'tenant-member']);
        $this->actingAs($admin)->withSession($session)->getJson('/api/v1/admin/recording-archive-targets')->assertForbidden();
        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/admin/recording-archive-targets', $this->targetPayload('denied'))->assertForbidden();
        DB::table('tenant_role_assignments')->where('membership_id', $membership->id)->update(['role_key' => 'tenant-admin']);
        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => null])->getJson('/api/v1/admin/recording-archive-targets')->assertStatus(409);
    }

    public function test_postgresql_schema_has_rma_d_constraints_and_no_future_authority(): void
    {
        $this->assertTrue(Schema::hasTable('media_archive_targets'));
        $this->assertTrue(Schema::hasTable('media_archive_credential_references'));
        foreach (['id', 'tenant_id', 'name', 'slug', 'description', 'target_kind', 'endpoint_url', 'region', 'bucket', 'object_prefix', 'desired_state', 'created_by', 'updated_by', 'created_at', 'updated_at'] as $column) $this->assertTrue(Schema::hasColumn('media_archive_targets', $column), $column);
        foreach (['id', 'tenant_id', 'media_archive_target_id', 'identifier', 'encrypted_secret', 'secret_fingerprint', 'created_at', 'updated_at'] as $column) $this->assertTrue(Schema::hasColumn('media_archive_credential_references', $column), $column);
        $this->assertFalse(Schema::hasColumn('media_archive_targets', 'is_default'));
        $this->assertFalse(Schema::hasColumn('media_archive_targets', 'use_path_style'));
        $this->assertFalse(Schema::hasColumn('media_archive_credential_references', 'version'));
        $this->assertFalse(Schema::hasColumn('media_archive_credential_references', 'status'));
        $this->assertFalse(Schema::hasColumn('media_archive_credential_references', 'rotated_at'));
        $this->assertFalse(Schema::hasColumn('media_archive_credential_references', 'expires_at'));
        $this->assertFalse(Schema::hasColumn('recording_artifacts', 'media_archive_target_id'));
        $this->assertFalse(Schema::hasColumn('recording_sessions', 'media_archive_target_id'));
        $this->assertFalse(Schema::hasColumn('call_legs', 'recording_ref'));
        if (DB::getDriverName() === 'pgsql') {
            $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'media_archive_targets'::regclass");
            $names = array_map(fn (object $row): string => $row->conname, $constraints);
            foreach (['media_archive_targets_kind_check', 'media_archive_targets_state_check', 'media_archive_targets_endpoint_check', 'media_archive_targets_tenant_slug_unique'] as $name) $this->assertContains($name, $names);
        }
    }

    public function test_payload_safety_allows_only_non_secret_credential_reference_metadata(): void
    {
        $safe = PayloadSafety::assertSafe(['credential_reference_id' => '00000000-0000-0000-0000-000000000000']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $safe['credential_reference_id']);
        $this->expectExceptionMessage('payload contains sensitive field: credential');
        PayloadSafety::assertSafe(['credential' => 'secret']);
    }

    private function targetPayload(string $slug): array
    {
        return ['name' => ucfirst($slug), 'slug' => $slug, 'endpoint_url' => 'https://storage.example.test', 'bucket' => 'utcp-'.$slug];
    }

    private function createTenantAdmin(string $email, string $slug): array
    {
        $userId = IdentityIds::new(); $tenantId = IdentityIds::new(); $membershipId = IdentityIds::new();
        DB::table('users')->insert(['id' => $userId, 'email' => $email, 'normalized_email' => strtolower($email), 'display_name' => 'Archive Admin', 'password' => Hash::make('correct-password-123'), 'status' => 'active', 'password_change_required' => false, 'session_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => $slug, 'display_name' => 'Archive Tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_memberships')->insert(['id' => $membershipId, 'user_id' => $userId, 'tenant_id' => $tenantId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_role_assignments')->insert(['id' => IdentityIds::new(), 'membership_id' => $membershipId, 'role_key' => 'tenant-admin', 'assigned_by_user_id' => null, 'created_at' => now()]);
        return [\App\Models\User::findOrFail($userId), $tenantId];
    }
}
