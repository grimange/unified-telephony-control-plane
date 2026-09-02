<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyCount = (int) DB::table('call_legs')->whereNotNull('recording_ref')->count();
        if ($legacyCount !== 0) {
            throw new RuntimeException("cannot remove call_legs.recording_ref: {$legacyCount} non-null rows remain");
        }

        Schema::create('recording_artifacts', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->char('recording_session_id', 32);
            $table->uuid('call_id');
            $table->uuid('call_leg_id');
            $table->uuid('runtime_node_id');
            $table->string('capture_ref', 64);
            $table->string('state', 24)->default('pending');
            $table->string('media_format', 32)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestampTz('observed_started_at')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('recording_session_id')->references('id')->on('recording_sessions')->restrictOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->restrictOnDelete();
            $table->foreign('call_leg_id')->references('id')->on('call_legs')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->unique('recording_session_id', 'recording_artifacts_session_unique');
            $table->index(['tenant_id', 'state'], 'recording_artifacts_tenant_state_idx');
            $table->index(['tenant_id', 'call_id'], 'recording_artifacts_tenant_call_idx');
            $table->index(['tenant_id', 'call_leg_id'], 'recording_artifacts_tenant_leg_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recording_artifacts ADD CONSTRAINT recording_artifacts_state_check CHECK (state IN ('pending', 'available'))");
            DB::statement("ALTER TABLE recording_artifacts ADD CONSTRAINT recording_artifacts_available_metadata_check CHECK (state != 'available' OR (media_format IS NOT NULL AND available_at IS NOT NULL))");
        }

        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropColumn('recording_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_artifacts');

        if (! Schema::hasColumn('call_legs', 'recording_ref')) {
            Schema::table('call_legs', function (Blueprint $table): void {
                $table->string('recording_ref', 255)->nullable();
            });
        }
    }
};
