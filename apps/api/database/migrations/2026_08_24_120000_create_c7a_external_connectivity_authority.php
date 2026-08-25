<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_trunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->json('supported_directions')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('desired_state', 24)->default('draft');
            $table->string('observed_health', 24)->default('unknown');
            $table->string('observed_health_reason', 512)->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'slug'], 'external_trunks_tenant_slug_unique');
            $table->index(['tenant_id', 'desired_state'], 'external_trunks_tenant_state_idx');
        });

        Schema::create('trunk_credential_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_trunk_id');
            $table->string('credential_type', 60);
            $table->string('identifier', 160)->nullable();
            $table->text('encrypted_secret');
            $table->char('secret_fingerprint', 64);
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('active');
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->unique(['external_trunk_id', 'credential_type', 'version'], 'trunk_credentials_version_unique');
            $table->index(['external_trunk_id', 'credential_type', 'status'], 'trunk_credentials_active_idx');
        });

        Schema::create('trunk_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_trunk_id');
            $table->string('endpoint_uri', 255);
            $table->string('transport', 24)->default('udp');
            $table->string('authentication_mode', 24)->default('none');
            $table->uuid('credential_reference_id')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('desired_state', 24)->default('active');
            $table->unsignedInteger('priority')->default(100);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->foreign('credential_reference_id')->references('id')->on('trunk_credential_references')->restrictOnDelete();
            $table->unique(['external_trunk_id', 'endpoint_uri'], 'trunk_endpoints_uri_unique');
            $table->index(['tenant_id', 'external_trunk_id', 'desired_state'], 'trunk_endpoints_state_idx');
        });

        Schema::create('telephony_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('address_type', 24);
            $table->string('normalized_value', 255);
            $table->string('desired_state', 24)->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'address_type', 'normalized_value'], 'telephony_addresses_value_unique');
            $table->index(['tenant_id', 'desired_state'], 'telephony_addresses_state_idx');
        });

        Schema::create('external_trunk_addresses', function (Blueprint $table): void {
            $table->uuid('external_trunk_id');
            $table->uuid('telephony_address_id');
            $table->string('direction', 16)->default('both');
            $table->timestampsTz();

            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->foreign('telephony_address_id')->references('id')->on('telephony_addresses')->restrictOnDelete();
            $table->primary(['external_trunk_id', 'telephony_address_id']);
        });

        Schema::create('caller_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            $table->uuid('telephony_address_id');
            $table->string('display_name', 160)->nullable();
            $table->string('desired_state', 24)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('telephony_address_id')->references('id')->on('telephony_addresses')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'name'], 'caller_identities_tenant_name_unique');
            $table->index(['tenant_id', 'desired_state'], 'caller_identities_state_idx');
        });

        Schema::create('caller_identity_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('caller_identity_id');
            $table->uuid('external_trunk_id');
            $table->string('desired_state', 24)->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('caller_identity_id')->references('id')->on('caller_identities')->restrictOnDelete();
            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['caller_identity_id', 'external_trunk_id'], 'caller_identity_policy_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE external_trunks ADD CONSTRAINT external_trunks_desired_state_check CHECK (desired_state IN ('draft', 'validating', 'active', 'draining', 'disabled', 'retired'))");
            DB::statement("ALTER TABLE external_trunks ADD CONSTRAINT external_trunks_observed_health_check CHECK (observed_health IN ('unknown', 'ready', 'degraded', 'unavailable'))");
            DB::statement("ALTER TABLE trunk_credential_references ADD CONSTRAINT trunk_credentials_status_check CHECK (status IN ('active', 'retired'))");
            DB::statement("ALTER TABLE trunk_endpoints ADD CONSTRAINT trunk_endpoints_state_check CHECK (desired_state IN ('active', 'disabled', 'retired'))");
            DB::statement("ALTER TABLE telephony_addresses ADD CONSTRAINT telephony_addresses_type_check CHECK (address_type IN ('e164', 'sip_uri'))");
            DB::statement("ALTER TABLE telephony_addresses ADD CONSTRAINT telephony_addresses_state_check CHECK (desired_state IN ('draft', 'active', 'disabled', 'retired'))");
            DB::statement("ALTER TABLE external_trunk_addresses ADD CONSTRAINT external_trunk_addresses_direction_check CHECK (direction IN ('inbound', 'outbound', 'both'))");
            DB::statement("ALTER TABLE caller_identities ADD CONSTRAINT caller_identities_state_check CHECK (desired_state IN ('draft', 'active', 'disabled', 'retired'))");
            DB::statement("ALTER TABLE caller_identity_policies ADD CONSTRAINT caller_identity_policies_state_check CHECK (desired_state IN ('active', 'disabled', 'retired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caller_identity_policies');
        Schema::dropIfExists('caller_identities');
        Schema::dropIfExists('external_trunk_addresses');
        Schema::dropIfExists('telephony_addresses');
        Schema::dropIfExists('trunk_endpoints');
        Schema::dropIfExists('trunk_credential_references');
        Schema::dropIfExists('external_trunks');
    }
};
