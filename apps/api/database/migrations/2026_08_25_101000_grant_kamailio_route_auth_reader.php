<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = str_replace('"', '""', (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader'));
        DB::statement('grant select on kamailio_external_trunk_registration_view to "'.$role.'"');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = str_replace('"', '""', (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader'));
        DB::statement('revoke select on kamailio_external_trunk_registration_view from "'.$role.'"');
    }
};
