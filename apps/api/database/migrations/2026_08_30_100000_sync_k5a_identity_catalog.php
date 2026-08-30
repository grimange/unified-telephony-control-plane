<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $key = 'platform.infrastructure.view';
        $definition = Config::get('identity.capabilities')[$key] ?? null;

        if (! is_array($definition)) {
            throw new RuntimeException("Missing identity capability definition: {$key}");
        }

        DB::table('capabilities')->updateOrInsert(
            ['key' => $key],
            [
                'scope' => $definition['scope'],
                'description' => $definition['description'],
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
