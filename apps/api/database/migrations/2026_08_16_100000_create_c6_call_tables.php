<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('direction', 16);
            $table->string('desired_state', 24)->default('active');
            $table->string('observed_state', 32);
            $table->uuid('runtime_node_id')->nullable();
            $table->json('route_decision')->nullable();
            $table->string('route_decision_source', 80)->nullable();
            $table->string('destination_ref', 255)->nullable();
            $table->string('caller_identity_ref', 255)->nullable();
            $table->uuid('requested_by_user_id')->nullable();
            $table->char('correlation_id', 32)->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->string('termination_reason', 80)->nullable();
            $table->string('termination_party', 24)->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'observed_state'], 'calls_tenant_observed_idx');
            $table->index(['tenant_id', 'created_at'], 'calls_tenant_created_idx');
            $table->index(['runtime_node_id'], 'calls_runtime_node_idx');
        });

        Schema::create('call_legs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id');
            $table->uuid('runtime_node_id')->nullable();
            $table->string('runtime_channel_id', 200)->nullable();
            $table->uuid('telephony_session_id')->nullable();
            $table->string('direction', 16);
            $table->string('role', 24);
            $table->string('desired_state', 24)->default('active');
            $table->string('observed_state', 32);
            $table->unsignedBigInteger('observed_generation')->nullable();
            $table->boolean('held')->default(false);
            $table->boolean('muted')->default(false);
            $table->string('remote_identity', 255)->nullable();
            $table->string('media_ref', 255)->nullable();
            $table->string('recording_ref', 255)->nullable();
            $table->uuid('bridged_to_leg_id')->nullable();
            $table->timestampTz('bridged_at')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->string('termination_reason', 80)->nullable();
            $table->string('termination_party', 24)->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->foreign('telephony_session_id')->references('id')->on('telephony_sessions')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'call_id'], 'call_legs_tenant_call_idx');
            $table->index(['tenant_id', 'observed_state'], 'call_legs_tenant_observed_idx');
            $table->index(['telephony_session_id'], 'call_legs_session_idx');
        });

        Schema::table('call_legs', function (Blueprint $table): void {
            $table->foreign('bridged_to_leg_id', 'call_legs_bridged_to_leg_fk')
                ->references('id')
                ->on('call_legs')
                ->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX call_legs_runtime_channel_unique ON call_legs (runtime_node_id, runtime_channel_id) WHERE runtime_channel_id IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE calls ADD CONSTRAINT calls_direction_check CHECK (direction IN ('inbound', 'outbound'))");
            DB::statement("ALTER TABLE calls ADD CONSTRAINT calls_desired_state_check CHECK (desired_state IN ('active', 'terminated'))");
            DB::statement("ALTER TABLE calls ADD CONSTRAINT calls_observed_state_check CHECK (observed_state IN ('requested', 'selecting_route', 'originating', 'offered', 'ringing', 'early_media', 'answered', 'bridged', 'transferring', 'terminating', 'completed', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE call_legs ADD CONSTRAINT call_legs_direction_check CHECK (direction IN ('inbound', 'outbound'))");
            DB::statement("ALTER TABLE call_legs ADD CONSTRAINT call_legs_desired_state_check CHECK (desired_state IN ('active', 'terminated'))");
            DB::statement("ALTER TABLE call_legs ADD CONSTRAINT call_legs_observed_state_check CHECK (observed_state IN ('requested', 'selecting_route', 'originating', 'offered', 'ringing', 'early_media', 'answered', 'bridged', 'held', 'transferring', 'terminating', 'completed', 'failed', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_legs');
        Schema::dropIfExists('calls');
    }
};
