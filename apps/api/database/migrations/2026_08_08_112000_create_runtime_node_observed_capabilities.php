<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_node_observed_capability_snapshots', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->uuid('runtime_node_id');
            $table->timestampTz('observed_at');
            $table->char('source_observation_id', 32);
            $table->unsignedBigInteger('configuration_version')->nullable();
            $table->string('adapter_key', 80)->nullable();
            $table->unsignedInteger('capability_count')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['tenant_id', 'runtime_node_id'], 'runtime_node_observed_capability_snapshots_node_unique');
            $table->unique('source_observation_id', 'runtime_node_observed_capability_snapshots_source_unique');
        });

        Schema::create('runtime_node_observed_capabilities', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->uuid('tenant_id');
            $table->uuid('runtime_node_id');
            $table->string('capability_key', 120);
            $table->timestampTz('observed_at');
            $table->char('snapshot_id', 32);
            $table->char('source_observation_id', 32);
            $table->unsignedBigInteger('configuration_version')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->foreign('snapshot_id')->references('id')->on('runtime_node_observed_capability_snapshots')->cascadeOnDelete();
            $table->unique(['tenant_id', 'runtime_node_id', 'capability_key'], 'runtime_node_observed_capabilities_unique');
            $table->index('source_observation_id', 'runtime_node_observed_capabilities_source_idx');
            $table->index(['tenant_id', 'runtime_node_id', 'observed_at'], 'runtime_node_observed_capabilities_freshness_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_node_observed_capabilities');
        Schema::dropIfExists('runtime_node_observed_capability_snapshots');
    }
};
