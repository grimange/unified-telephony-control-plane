<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_projection_checkpoints', function (Blueprint $table): void {
            if (! Schema::hasColumn('runtime_projection_checkpoints', 'checkpoint_payload')) {
                $table->json('checkpoint_payload')->nullable()->after('last_observed_at');
            }
            if (! Schema::hasColumn('runtime_projection_checkpoints', 'checkpoint_hash')) {
                $table->char('checkpoint_hash', 64)->nullable()->after('checkpoint_payload');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_observations ALTER COLUMN runtime_node_id DROP NOT NULL');
        }

        Schema::table('signaling_registration_observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('signaling_registration_observations', 'desired_generation')) {
                $table->unsignedBigInteger('desired_generation')->default(1)->after('desired_state');
            }
            if (! Schema::hasColumn('signaling_registration_observations', 'last_observation_id')) {
                $table->char('last_observation_id', 32)->nullable()->after('last_event_type');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create index if not exists signaling_registration_observations_state_idx on signaling_registration_observations (desired_state, observed_state)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_observations ALTER COLUMN runtime_node_id SET NOT NULL');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists signaling_registration_observations_state_idx');
        }

        Schema::table('signaling_registration_observations', function (Blueprint $table): void {
            if (Schema::hasColumn('signaling_registration_observations', 'last_observation_id')) {
                $table->dropColumn('last_observation_id');
            }
            if (Schema::hasColumn('signaling_registration_observations', 'desired_generation')) {
                $table->dropColumn('desired_generation');
            }
        });

        Schema::table('runtime_projection_checkpoints', function (Blueprint $table): void {
            if (Schema::hasColumn('runtime_projection_checkpoints', 'checkpoint_hash')) {
                $table->dropColumn('checkpoint_hash');
            }
            if (Schema::hasColumn('runtime_projection_checkpoints', 'checkpoint_payload')) {
                $table->dropColumn('checkpoint_payload');
            }
        });
    }
};
