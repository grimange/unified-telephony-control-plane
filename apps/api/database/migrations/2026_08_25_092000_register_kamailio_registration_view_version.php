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

        DB::statement('alter table version alter column table_name type varchar(64)');
        DB::statement("insert into version (table_name, table_version) values ('kamailio_external_trunk_registration_view', 5) on conflict (table_name) do update set table_version = excluded.table_version");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("delete from version where table_name = 'kamailio_external_trunk_registration_view'");
    }
};
