<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_archive_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('target_kind', 32)->default('s3_compatible');
            $table->string('endpoint_url', 255);
            $table->string('region', 64)->nullable();
            $table->string('bucket', 255);
            $table->string('object_prefix', 255)->nullable();
            $table->string('desired_state', 24)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'slug'], 'media_archive_targets_tenant_slug_unique');
            $table->index(['tenant_id', 'desired_state'], 'media_archive_targets_tenant_state_idx');
        });

        Schema::create('media_archive_credential_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('media_archive_target_id');
            $table->string('identifier', 160)->nullable();
            $table->text('encrypted_secret');
            $table->char('secret_fingerprint', 64);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('media_archive_target_id')->references('id')->on('media_archive_targets')->restrictOnDelete();
            $table->unique('media_archive_target_id', 'media_archive_credentials_target_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE media_archive_targets ADD CONSTRAINT media_archive_targets_kind_check CHECK (target_kind IN ('s3_compatible'))");
            DB::statement("ALTER TABLE media_archive_targets ADD CONSTRAINT media_archive_targets_state_check CHECK (desired_state IN ('draft', 'active', 'disabled', 'retired'))");
            DB::statement("ALTER TABLE media_archive_targets ADD CONSTRAINT media_archive_targets_endpoint_check CHECK (endpoint_url ~ '^https?://')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_archive_credential_references');
        Schema::dropIfExists('media_archive_targets');
    }
};
