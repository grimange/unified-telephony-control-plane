<?php

namespace Tests\Feature\Identity;

use App\Identity\Authorization\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class K5AIdentityCatalogUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_k5a_catalog_is_converged_by_the_forward_migration(): void
    {
        DB::table('role_capabilities')
            ->where('role_key', 'platform-admin')
            ->where('capability_key', 'platform.infrastructure.view')
            ->delete();
        DB::table('capabilities')->where('key', 'platform.infrastructure.view')->delete();

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_30_100000_sync_k5a_identity_catalog.php';
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('capabilities', [
            'key' => 'platform.infrastructure.view',
            'scope' => 'platform',
        ]);
        $this->assertDatabaseHas('role_capabilities', [
            'role_key' => 'platform-admin',
            'capability_key' => 'platform.infrastructure.view',
        ]);
        $this->assertContains(
            'platform.infrastructure.view',
            app(AuthorizationService::class)->platformCapabilities($this->platformAdminId()),
        );
    }

    private function platformAdminId(): string
    {
        $id = '00000000-0000-0000-0000-000000000099';
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'k5a-upgrade-admin@utcp.local.test',
            'normalized_email' => 'k5a-upgrade-admin@utcp.local.test',
            'display_name' => 'K5A Upgrade Admin',
            'password' => 'not-used',
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => '00000000-0000-0000-0000-000000000098',
            'user_id' => $id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return $id;
    }
}
