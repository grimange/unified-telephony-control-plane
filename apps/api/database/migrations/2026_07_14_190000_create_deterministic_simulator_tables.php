<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('simulator_scheduled_events');
        Schema::dropIfExists('simulator_states');
        Schema::dropIfExists('simulator_profiles');

        Schema::create('simulator_profiles', function (Blueprint $table): void {
            $table->uuid('runtime_node_id')->primary();
            $table->string('scenario_key', 80);
            $table->unsignedInteger('scenario_version')->default(1);
            $table->string('seed', 120);
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('configuration_generation')->default(1);
            $table->timestamps();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->index(['scenario_key', 'scenario_version'], 'simulator_profiles_scenario_idx');
        });

        Schema::create('simulator_states', function (Blueprint $table): void {
            $table->uuid('runtime_node_id')->primary();
            $table->string('scenario_key', 80);
            $table->unsignedInteger('scenario_version')->default(1);
            $table->string('seed', 120);
            $table->unsignedBigInteger('logical_sequence')->default(0);
            $table->string('current_phase', 80)->default('configured');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->char('active_connection_epoch', 32)->nullable();
            $table->unsignedBigInteger('applied_configuration_generation')->default(0);
            $table->unsignedBigInteger('next_event_sequence')->default(1);
            $table->json('state_payload')->nullable();
            $table->timestamps();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->foreign('active_connection_epoch')->references('id')->on('runtime_event_connection_epochs')->nullOnDelete();
        });

        Schema::create('simulator_scheduled_events', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->uuid('runtime_node_id');
            $table->char('connection_epoch_id', 32);
            $table->unsignedBigInteger('event_sequence');
            $table->string('event_type', 120);
            $table->unsignedInteger('event_version');
            $table->json('payload');
            $table->timestampTz('due_at');
            $table->string('status', 32)->default('pending');
            $table->string('lease_owner', 160)->nullable();
            $table->string('lease_token', 80)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->foreign('connection_epoch_id')->references('id')->on('runtime_event_connection_epochs')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'connection_epoch_id', 'event_sequence'], 'simulator_events_node_epoch_sequence_unique');
            $table->index(['status', 'due_at'], 'simulator_events_due_idx');
            $table->index(['runtime_node_id', 'status'], 'simulator_events_node_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_runtime_family_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_runtime_family_check CHECK (runtime_family IN ('asterisk', 'freeswitch', 'simulator'))");
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_adapter_key_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_adapter_key_check CHECK (adapter_key IN ('asterisk-ari', 'freeswitch-esl', 'simulator-deterministic'))");
            DB::statement("ALTER TABLE simulator_profiles ADD CONSTRAINT simulator_profiles_scenario_key_check CHECK (scenario_key IN ('steady-ready', 'transient-failure-then-ready', 'terminal-failure', 'timeout-then-ready', 'duplicate-observation', 'disconnect-reconnect', 'configuration-drift-then-converge'))");
            DB::statement("ALTER TABLE simulator_scheduled_events ADD CONSTRAINT simulator_scheduled_events_status_check CHECK (status IN ('pending', 'leased', 'published', 'retry_scheduled', 'terminal_failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('simulator_scheduled_events');
        Schema::dropIfExists('simulator_states');
        Schema::dropIfExists('simulator_profiles');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_runtime_family_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_runtime_family_check CHECK (runtime_family IN ('asterisk', 'freeswitch'))");
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_adapter_key_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_adapter_key_check CHECK (adapter_key IN ('asterisk-ari', 'freeswitch-esl'))");
        }
    }
};
