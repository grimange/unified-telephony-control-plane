<?php

namespace Tests\Feature\Identity;

use App\Identity\Authorization\AuthorizationService;
use App\Identity\IdentityIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RmaAIdentityCatalogUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_migration_repairs_recording_catalog_and_tenant_admin_authority_idempotently(): void
    {
        $keys = ['telephony.recordings.view', 'telephony.recordings.manage'];
        $unrelated = 'telephony.calls.record';

        DB::table('role_capabilities')
            ->where('role_key', 'tenant-admin')
            ->whereIn('capability_key', $keys)
            ->delete();
        DB::table('capabilities')->whereIn('key', $keys)->delete();

        $this->assertDatabaseHas('role_capabilities', [
            'role_key' => 'tenant-admin',
            'capability_key' => $unrelated,
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_31_131000_sync_rma_a_identity_catalog.php';
        $migration->up();
        $migration->up();

        $catalog = Config::get('identity.capabilities', []);

        foreach ($keys as $key) {
            $definition = $catalog[$key];

            $this->assertDatabaseHas('capabilities', [
                'key' => $key,
                'scope' => $definition['scope'],
                'description' => $definition['description'],
            ]);
            $this->assertDatabaseHas('role_capabilities', [
                'role_key' => 'tenant-admin',
                'capability_key' => $key,
            ]);
            $this->assertSame(1, DB::table('capabilities')->where('key', $key)->count());
            $this->assertSame(1, DB::table('role_capabilities')->where([
                'role_key' => 'tenant-admin',
                'capability_key' => $key,
            ])->count());
        }

        $this->assertDatabaseHas('role_capabilities', [
            'role_key' => 'tenant-admin',
            'capability_key' => $unrelated,
        ]);

        [$userId, $tenantId] = $this->tenantAdmin();
        $capabilities = app(AuthorizationService::class)->tenantCapabilities($userId, $tenantId);

        $this->assertContains('telephony.recordings.view', $capabilities);
        $this->assertContains('telephony.recordings.manage', $capabilities);
    }

    /** @return array{string, string} */
    private function tenantAdmin(): array
    {
        $userId = IdentityIds::new();
        $tenantId = IdentityIds::new();
        $membershipId = IdentityIds::new();
        $now = now();

        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'rma-a-catalog-admin@utcp.local.test',
            'normalized_email' => 'rma-a-catalog-admin@utcp.local.test',
            'display_name' => 'RMA-A Catalog Admin',
            'password' => 'not-used',
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'rma-a-catalog-tenant',
            'display_name' => 'RMA-A Catalog Tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => $now,
        ]);

        return [$userId, $tenantId];
    }
}
