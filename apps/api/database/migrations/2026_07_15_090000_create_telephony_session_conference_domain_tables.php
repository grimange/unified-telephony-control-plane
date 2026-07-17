<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('status', 24)->default('pending');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('termination_reason', 80)->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('conferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('slug', 100);
            $table->string('display_name', 160);
            $table->uuid('runtime_node_id')->nullable();
            $table->string('desired_state', 24)->default('draft');
            $table->string('observed_state', 24)->default('unobserved');
            $table->unsignedInteger('configuration_generation')->default(1);
            $table->unsignedInteger('observed_generation')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->uuid('last_observation_id')->nullable();
            $table->uuid('last_evidence_source')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('draining_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'desired_state']);
            $table->index(['tenant_id', 'observed_state']);
            $table->index(['runtime_node_id']);
        });

        Schema::create('conference_runtime_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conference_id');
            $table->uuid('runtime_node_id');
            $table->string('status', 24)->default('active');
            $table->timestampTz('bound_at');
            $table->timestampTz('unbound_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('conference_id')->references('id')->on('conferences')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'runtime_node_id']);
        });

        Schema::create('conference_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conference_id');
            $table->uuid('telephony_session_id');
            $table->uuid('user_id');
            $table->string('desired_state', 24)->default('admitted');
            $table->string('observed_state', 24)->default('unobserved');
            $table->string('role', 24)->default('participant');
            $table->string('admission_reason', 80)->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('conference_id')->references('id')->on('conferences')->restrictOnDelete();
            $table->foreign('telephony_session_id')->references('id')->on('telephony_sessions')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'desired_state']);
            $table->index(['conference_id', 'desired_state']);
            $table->index(['telephony_session_id', 'desired_state']);
        });

        DB::statement("create unique index telephony_sessions_one_active_user_tenant on telephony_sessions (tenant_id, user_id) where status = 'active'");
        DB::statement("create unique index conference_runtime_bindings_one_active on conference_runtime_bindings (conference_id) where status = 'active'");
        DB::statement("create unique index conference_participants_one_admitted_session on conference_participants (conference_id, telephony_session_id) where desired_state = 'admitted'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table telephony_sessions add constraint telephony_sessions_status_check check (status in ('pending', 'active', 'ending', 'ended', 'expired', 'failed'))");
            DB::statement("alter table conferences add constraint conferences_desired_state_check check (desired_state in ('draft', 'open', 'draining', 'closed'))");
            DB::statement("alter table conferences add constraint conferences_observed_state_check check (observed_state in ('unobserved', 'provisioning', 'ready', 'degraded', 'unavailable', 'closed'))");
            DB::statement('alter table conferences add constraint conferences_generation_positive_check check (configuration_generation > 0)');
            DB::statement("alter table conference_runtime_bindings add constraint conference_runtime_bindings_status_check check (status in ('active', 'retired'))");
            DB::statement("alter table conference_participants add constraint conference_participants_desired_state_check check (desired_state in ('admitted', 'removed'))");
            DB::statement("alter table conference_participants add constraint conference_participants_observed_state_check check (observed_state in ('unobserved', 'joining', 'joined', 'leaving', 'left', 'failed'))");
            DB::statement("alter table conference_participants add constraint conference_participants_role_check check (role in ('host', 'participant'))");
        }

        $this->syncIdentityCatalog();
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_participants');
        Schema::dropIfExists('conference_runtime_bindings');
        Schema::dropIfExists('conferences');
        Schema::dropIfExists('telephony_sessions');
    }

    private function syncIdentityCatalog(): void
    {
        $now = now();
        foreach (config('identity.capabilities', []) as $key => $definition) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        foreach (config('identity.roles', []) as $key => $definition) {
            DB::table('roles')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $definition['scope'],
                    'display_name' => $definition['display_name'],
                    'built_in' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            foreach ($definition['capabilities'] as $capability) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_key' => $key, 'capability_key' => $capability],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
