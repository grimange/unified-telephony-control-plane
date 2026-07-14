<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_operations', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('tenant_id')->nullable();
            $table->string('operation_type', 160);
            $table->string('aggregate_type', 160);
            $table->string('aggregate_id', 160);
            $table->string('runtime_node_id')->nullable();
            $table->unsignedSmallInteger('payload_version');
            $table->json('payload');
            $table->string('status', 40);
            $table->integer('priority')->default(100);
            $table->string('idempotency_key', 160)->nullable();
            $table->char('correlation_id', 32);
            $table->char('causation_id', 32)->nullable();
            $table->char('request_id', 32);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestampTz('available_at');
            $table->timestampTz('expires_at')->nullable();
            $table->string('lease_owner')->nullable();
            $table->char('lease_token', 32)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('last_failure_class', 80)->nullable();
            $table->string('last_failure_code', 120)->nullable();
            $table->string('last_failure_message', 512)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'priority', 'available_at', 'created_at'], 'runtime_ops_claim_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'runtime_ops_aggregate_idx');
            $table->unique(['operation_type', 'idempotency_key'], 'runtime_ops_idempotency_unique');
        });

        Schema::create('control_plane_outbox_messages', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('tenant_id')->nullable();
            $table->string('aggregate_type', 160);
            $table->string('aggregate_id', 160);
            $table->string('event_type', 160);
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->char('correlation_id', 32);
            $table->char('causation_id', 32)->nullable();
            $table->char('request_id', 32);
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('dispatched_at')->nullable();
            $table->string('last_failure', 512)->nullable();
            $table->timestampsTz();

            $table->index(['dispatched_at', 'available_at', 'created_at'], 'outbox_dispatch_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
        });

        Schema::create('control_plane_inbox_messages', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('consumer', 160);
            $table->string('message_key', 200);
            $table->string('message_type', 160);
            $table->char('payload_hash', 64);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();

            $table->unique(['consumer', 'message_key'], 'inbox_consumer_key_unique');
            $table->index(['consumer', 'processed_at', 'failed_at'], 'inbox_processing_idx');
        });

        Schema::create('control_plane_idempotency_records', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('scope', 160);
            $table->string('idempotency_key', 160);
            $table->char('request_fingerprint', 64);
            $table->string('status', 40);
            $table->json('result')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['scope', 'idempotency_key'], 'idempotency_scope_key_unique');
            $table->index(['expires_at'], 'idempotency_expires_idx');
        });

        Schema::create('control_plane_audit_records', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('tenant_id')->nullable();
            $table->string('actor_type', 80);
            $table->string('actor_id')->nullable();
            $table->string('action', 160);
            $table->string('subject_type', 160);
            $table->string('subject_id', 160);
            $table->string('reason', 512)->nullable();
            $table->char('request_id', 32);
            $table->char('correlation_id', 32);
            $table->json('metadata');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'audit_subject_idx');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'audit_actor_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_control_plane_audit_mutation()
RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'control_plane_audit_records is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER control_plane_audit_no_update
BEFORE UPDATE OR DELETE ON control_plane_audit_records
FOR EACH ROW EXECUTE FUNCTION reject_control_plane_audit_mutation();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS control_plane_audit_no_update ON control_plane_audit_records');
            DB::statement('DROP FUNCTION IF EXISTS reject_control_plane_audit_mutation()');
        }

        Schema::dropIfExists('control_plane_audit_records');
        Schema::dropIfExists('control_plane_idempotency_records');
        Schema::dropIfExists('control_plane_inbox_messages');
        Schema::dropIfExists('control_plane_outbox_messages');
        Schema::dropIfExists('runtime_operations');
    }
};
