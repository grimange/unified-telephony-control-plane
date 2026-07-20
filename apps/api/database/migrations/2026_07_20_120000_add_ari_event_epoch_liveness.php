<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_event_connection_epochs', function (Blueprint $table): void {
            $table->timestampTz('last_authoritative_signal_at')->nullable()->after('opened_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_observed_state_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_observed_state_check CHECK (observed_state IN ('unobserved', 'unknown', 'connecting', 'ready', 'degraded', 'events_degraded', 'unavailable', 'stale'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_observed_state_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_observed_state_check CHECK (observed_state IN ('unobserved', 'unknown', 'connecting', 'ready', 'degraded', 'unavailable', 'stale'))");
        }

        Schema::table('runtime_event_connection_epochs', function (Blueprint $table): void {
            $table->dropColumn('last_authoritative_signal_at');
        });
    }
};
