<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $catalog = Config::get('identity.capabilities', []);
        $key = 'platform.memberships.manage';
        $capability = $catalog[$key] ?? null;

        if (! is_array($capability)) {
            throw new RuntimeException("Missing identity capability definition: {$key}");
        }

        DB::table('capabilities')->updateOrInsert(
            ['key' => $key],
            [
                'scope' => $capability['scope'],
                'description' => $capability['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('role_capabilities')->updateOrInsert(
            ['role_key' => 'platform-admin', 'capability_key' => $key],
            ['created_at' => $now, 'updated_at' => $now],
        );
    }

    public function down(): void {}
};
