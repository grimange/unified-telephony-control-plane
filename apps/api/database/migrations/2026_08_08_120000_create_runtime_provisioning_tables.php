<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->string('kind', 40);
            $table->json('configuration')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'slug'], 'deployment_targets_tenant_slug_unique');
            $table->unique(['tenant_id', 'kind'], 'deployment_targets_tenant_kind_unique');
            $table->index(['tenant_id', 'kind'], 'deployment_targets_tenant_kind_idx');
        });

        Schema::create('runtime_provisioning_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deployment_target_id');
            $table->uuid('runtime_node_id');
            $table->string('runtime_family', 40);
            $table->string('adapter_key', 80);
            $table->string('requested_name', 160);
            $table->string('requested_slug', 100);
            $table->string('idempotency_key', 160);
            $table->char('request_fingerprint', 64);
            $table->string('status', 32)->default('requested');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('deployment_target_id')->references('id')->on('deployment_targets')->restrictOnDelete();
            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $table->unique(['tenant_id', 'idempotency_key'], 'runtime_provisioning_tenant_idempotency_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'runtime_provisioning_tenant_status_idx');
            $table->index(['tenant_id', 'runtime_node_id'], 'runtime_provisioning_tenant_node_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_provisioning_requests');
        Schema::dropIfExists('deployment_targets');
    }
};
