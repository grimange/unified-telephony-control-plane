<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('k5d_host_maintenances', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('node_uid', 255);
            $table->string('node_name', 255);
            $table->uuid('requested_by')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 40)->default('requested');
            $table->string('phase', 40)->default('requested');
            $table->json('runtime_node_ids')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_details')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('telephony_drained_at')->nullable();
            $table->timestampTz('cordoned_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['node_uid', 'status'], 'k5d_host_maintenance_node_status_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('k5d_host_maintenances'); }
};
