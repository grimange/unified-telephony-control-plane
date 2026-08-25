<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_trunk_projection_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_trunk_id');
            $table->string('provider', 32);
            $table->string('projection_key', 160);
            $table->string('desired_state', 32);
            $table->unsignedBigInteger('desired_generation')->default(1);
            $table->json('artifact');
            $table->char('artifact_hash', 64);
            $table->string('observed_state', 32)->default('projected');
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->unique(['external_trunk_id', 'provider', 'projection_key'], 't6_projection_identity_unique');
            $table->index(['tenant_id', 'provider', 'observed_state'], 't6_projection_tenant_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_trunk_projection_artifacts');
    }
};
