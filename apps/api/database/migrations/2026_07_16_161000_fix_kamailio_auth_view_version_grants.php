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

        DB::statement("insert into version (table_name, table_version) values ('kamailio_signaling_auth_view', 7) on conflict (table_name) do update set table_version = excluded.table_version");
        DB::statement('grant select on version to "'.str_replace('"', '""', env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader')).'"');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('revoke all privileges on version from "'.str_replace('"', '""', env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader')).'"');
        DB::statement("delete from version where table_name = 'kamailio_signaling_auth_view'");
    }
};
