<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_plane_outbox_messages', function (Blueprint $table): void {
            $table->string('dispatch_status', 40)->default('pending')->after('attempt_count');
            $table->string('lease_owner')->nullable()->after('dispatch_status');
            $table->char('lease_token', 32)->nullable()->after('lease_owner');
            $table->timestampTz('lease_expires_at')->nullable()->after('lease_token');
            $table->string('last_failure_class', 80)->nullable()->after('last_failure');
            $table->string('last_failure_code', 120)->nullable()->after('last_failure_class');
            $table->index(['dispatch_status', 'available_at', 'created_at'], 'outbox_c3_claim_idx');
        });

        Schema::table('runtime_nodes', function (Blueprint $table): void {
            $table->string('last_evidence_source', 160)->nullable()->after('observed_at');
            $table->char('last_observation_id', 32)->nullable()->after('last_evidence_source');
            $table->unsignedBigInteger('observed_configuration_version')->nullable()->after('last_observation_id');
        });

        Schema::create('runtime_event_connection_epochs', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('runtime_node_id')->nullable();
            $table->string('adapter_key', 80);
            $table->string('status', 40)->default('open');
            $table->string('owner')->nullable();
            $table->char('fencing_token', 32)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->index(['runtime_node_id', 'status'], 'runtime_event_epochs_node_status_idx');
        });

        Schema::create('runtime_event_receipts', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('runtime_node_id')->nullable();
            $table->string('adapter_key', 80);
            $table->char('connection_epoch_id', 32);
            $table->string('external_event_key', 200);
            $table->string('event_type', 160);
            $table->unsignedSmallInteger('event_version');
            $table->char('payload_hash', 64);
            $table->json('sanitized_payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->string('lease_owner')->nullable();
            $table->char('lease_token', 32)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->foreign('connection_epoch_id')->references('id')->on('runtime_event_connection_epochs')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'connection_epoch_id', 'external_event_key'], 'runtime_event_receipts_dedupe_unique');
            $table->index(['status', 'available_at', 'created_at'], 'runtime_event_receipts_claim_idx');
        });

        Schema::create('runtime_observations', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->uuid('runtime_node_id')->nullable();
            $table->string('observation_type', 160);
            $table->unsignedSmallInteger('observation_version');
            $table->string('subject_type', 160);
            $table->string('subject_id', 160);
            $table->string('observed_state', 80);
            $table->char('source_event_id', 32);
            $table->char('source_connection_epoch', 32);
            $table->unsignedBigInteger('configuration_version')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('received_at');
            $table->json('payload');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->foreign('source_event_id')->references('id')->on('runtime_event_receipts')->cascadeOnDelete();
            $table->unique(['source_event_id', 'observation_type', 'subject_type', 'subject_id'], 'runtime_observations_source_unique');
            $table->index(['runtime_node_id', 'observation_type', 'observed_at'], 'runtime_observations_node_type_idx');
        });

        Schema::create('runtime_projection_checkpoints', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('projector', 160);
            $table->string('partition_key', 200);
            $table->uuid('runtime_node_id')->nullable();
            $table->char('last_source_event_id', 32)->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('lease_owner')->nullable();
            $table->char('lease_token', 32)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['projector', 'partition_key', 'runtime_node_id'], 'runtime_projection_checkpoint_unique');
        });

        Schema::create('runtime_reconciliation_states', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->string('target_type', 160);
            $table->string('target_id', 160);
            $table->unsignedBigInteger('desired_generation');
            $table->unsignedBigInteger('observed_generation')->nullable();
            $table->string('status', 40)->default('waiting');
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('next_check_at');
            $table->char('last_operation_id', 32)->nullable();
            $table->string('blocked_reason', 160)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('lease_owner')->nullable();
            $table->char('lease_token', 32)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['target_type', 'target_id', 'desired_generation'], 'runtime_reconciliation_target_unique');
            $table->index(['status', 'next_check_at', 'created_at'], 'runtime_reconciliation_due_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE control_plane_outbox_messages ADD CONSTRAINT outbox_dispatch_status_check CHECK (dispatch_status IN ('pending', 'leased', 'dispatched', 'retry_scheduled', 'terminal_failed'))");
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_observed_state_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_observed_state_check CHECK (observed_state IN ('unobserved', 'unknown', 'connecting', 'ready', 'degraded', 'unavailable', 'stale'))");
            DB::statement("ALTER TABLE runtime_event_connection_epochs ADD CONSTRAINT runtime_event_epochs_status_check CHECK (status IN ('open', 'closed', 'expired'))");
            DB::statement("ALTER TABLE runtime_event_receipts ADD CONSTRAINT runtime_event_receipts_status_check CHECK (status IN ('pending', 'leased', 'processed', 'retry_scheduled', 'terminal_failed', 'unsupported', 'conflict'))");
            DB::statement("ALTER TABLE runtime_reconciliation_states ADD CONSTRAINT runtime_reconciliation_status_check CHECK (status IN ('waiting', 'leased', 'converged', 'operation_required', 'blocked', 'unsupported', 'retry_scheduled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_reconciliation_states');
        Schema::dropIfExists('runtime_projection_checkpoints');
        Schema::dropIfExists('runtime_observations');
        Schema::dropIfExists('runtime_event_receipts');
        Schema::dropIfExists('runtime_event_connection_epochs');

        if (Schema::hasTable('runtime_nodes')) {
            Schema::table('runtime_nodes', function (Blueprint $table): void {
                $table->dropColumn(['last_evidence_source', 'last_observation_id', 'observed_configuration_version']);
            });
        }

        Schema::table('control_plane_outbox_messages', function (Blueprint $table): void {
            $table->dropIndex('outbox_c3_claim_idx');
            $table->dropColumn([
                'dispatch_status',
                'lease_owner',
                'lease_token',
                'lease_expires_at',
                'last_failure_class',
                'last_failure_code',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE runtime_nodes DROP CONSTRAINT IF EXISTS runtime_nodes_observed_state_check');
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_observed_state_check CHECK (observed_state IN ('unobserved', 'unknown'))");
        }
    }
};
