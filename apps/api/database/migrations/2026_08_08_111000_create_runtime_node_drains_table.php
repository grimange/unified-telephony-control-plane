<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_node_drains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('runtime_node_id');
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('initial_work')->default(0);
            $table->unsignedInteger('remaining_work')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('timed_out_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->unique(['tenant_id', 'runtime_node_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_node_drains');
    }
};
