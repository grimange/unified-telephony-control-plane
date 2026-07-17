<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_listener_leases', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('runtime_node_id')->nullable();
            $table->string('listener_kind', 80);
            $table->string('status', 40)->default('released');
            $table->string('owner', 160)->nullable();
            $table->char('fencing_token', 32)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'listener_kind'], 'runtime_listener_node_kind_unique');
            $table->index(['listener_kind', 'status', 'lease_expires_at'], 'runtime_listener_claim_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE runtime_listener_leases ADD CONSTRAINT runtime_listener_status_check CHECK (status IN ('claimed', 'released', 'expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_listener_leases');
    }
};
