<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (Config::get('identity.capabilities', []) as $key => $capability) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $capability['scope'],
                    'description' => $capability['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach (Config::get('identity.roles', []) as $key => $role) {
            DB::table('roles')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $role['scope'],
                    'display_name' => $role['display_name'],
                    'built_in' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            foreach ($role['capabilities'] as $capabilityKey) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_key' => $key, 'capability_key' => $capabilityKey],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        // Catalog rows are shared identity state and are intentionally retained.
    }
};
