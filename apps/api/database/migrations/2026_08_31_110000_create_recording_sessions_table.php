<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_sessions', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id')->nullable();
            $table->uuid('call_leg_id')->nullable();
            $table->uuid('conference_id')->nullable();
            $table->char('start_operation_id', 32)->nullable();
            $table->char('stop_operation_id', 32)->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->string('desired_state', 24)->default('recording');
            $table->string('observed_state', 24)->default('requested');
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->string('failure_message', 512)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->restrictOnDelete();
            $table->foreign('call_leg_id')->references('id')->on('call_legs')->restrictOnDelete();
            $table->foreign('conference_id')->references('id')->on('conferences')->restrictOnDelete();
            $table->foreign('start_operation_id')->references('id')->on('runtime_operations')->restrictOnDelete();
            $table->foreign('stop_operation_id')->references('id')->on('runtime_operations')->restrictOnDelete();
            $table->unique(['tenant_id', 'idempotency_key'], 'recording_sessions_tenant_idempotency_unique');
            $table->index(['tenant_id', 'call_id'], 'recording_sessions_tenant_call_idx');
            $table->index(['tenant_id', 'call_leg_id'], 'recording_sessions_tenant_leg_idx');
            $table->index(['tenant_id', 'observed_state'], 'recording_sessions_tenant_observed_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recording_sessions ADD CONSTRAINT recording_sessions_subject_check CHECK (call_id IS NOT NULL OR call_leg_id IS NOT NULL OR conference_id IS NOT NULL)");
            DB::statement("ALTER TABLE recording_sessions ADD CONSTRAINT recording_sessions_desired_state_check CHECK (desired_state IN ('recording', 'stopped'))");
            DB::statement("ALTER TABLE recording_sessions ADD CONSTRAINT recording_sessions_observed_state_check CHECK (observed_state IN ('requested', 'recording', 'stopped', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_sessions');
    }
};
