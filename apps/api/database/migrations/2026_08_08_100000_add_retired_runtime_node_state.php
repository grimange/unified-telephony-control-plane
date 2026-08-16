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

        DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT runtime_nodes_desired_state_check');
        DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_desired_state_check CHECK (desired_state IN ('draft', 'active', 'draining', 'disabled', 'retired'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT runtime_nodes_desired_state_check');
        DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_desired_state_check CHECK (desired_state IN ('draft', 'active', 'draining', 'disabled'))");
    }
};
