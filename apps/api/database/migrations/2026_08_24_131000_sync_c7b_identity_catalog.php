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
        foreach (['telephony.routing.view', 'telephony.routing.manage'] as $key) {
            $definition = $catalog[$key];
            DB::table('capabilities')->updateOrInsert(['key' => $key], ['scope' => $definition['scope'], 'description' => $definition['description'], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_capabilities')->updateOrInsert(['role_key' => 'tenant-admin', 'capability_key' => $key], ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void {}
};
