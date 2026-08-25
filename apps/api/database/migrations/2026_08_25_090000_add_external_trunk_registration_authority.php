<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trunk_endpoints', function (Blueprint $table): void {
            $table->string('signaling_mode', 32)->default('static')->after('endpoint_uri');
            $table->string('registration_target', 255)->nullable()->after('credential_reference_id');
            $table->string('registration_realm', 255)->nullable()->after('registration_target');
            $table->string('registration_identity', 160)->nullable()->after('registration_realm');
            $table->index(['external_trunk_id', 'signaling_mode', 'desired_state'], 'trunk_endpoints_registration_idx');
        });

        Schema::create('kamailio_external_trunk_registration_view', function (Blueprint $table): void {
            $table->uuid('trunk_endpoint_id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_trunk_id');
            $table->string('l_uuid', 64);
            $table->string('l_username', 160);
            $table->string('l_domain', 255);
            $table->string('r_username', 160)->nullable();
            $table->string('r_domain', 255);
            $table->string('realm', 255);
            $table->string('auth_username', 160);
            $table->string('auth_password', 1)->default('');
            $table->char('auth_ha1', 32);
            $table->string('auth_proxy', 255);
            $table->unsignedInteger('expires')->default(120);
            $table->unsignedInteger('flags')->default(0);
            $table->unsignedInteger('reg_delay')->default(0);
            $table->string('contact_addr', 255)->nullable();
            $table->string('socket', 255)->nullable();
            $table->uuid('credential_reference_id');
            $table->unsignedInteger('credential_version');
            $table->unsignedBigInteger('desired_generation');
            $table->string('desired_state', 24);
            $table->timestampsTz();
            $table->unique(['external_trunk_id', 'trunk_endpoint_id'], 'kamailio_registration_endpoint_unique');
            $table->index(['tenant_id', 'desired_state'], 'kamailio_registration_tenant_state_idx');
        });

        Schema::create('external_trunk_registration_observations', function (Blueprint $table): void {
            $table->uuid('trunk_endpoint_id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_trunk_id');
            $table->string('state', 24)->default('not_configured');
            $table->string('failure_category', 24)->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->char('contact_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('observation_version')->default(0);
            $table->unsignedBigInteger('desired_generation')->default(0);
            $table->timestampsTz();
            $table->foreign('trunk_endpoint_id')->references('id')->on('trunk_endpoints')->restrictOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
            $table->index(['tenant_id', 'state'], 'external_registration_observation_state_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $role = str_replace('"', '""', (string) env('KAMAILIO_REGISTRATION_DB_USER', 'utcp_kamailio_registration_reader'));
            DB::statement('grant select on kamailio_external_trunk_registration_view to "'.$role.'"');
            DB::statement('grant select on version to "'.$role.'"');
            DB::statement("ALTER TABLE trunk_endpoints ADD CONSTRAINT trunk_endpoints_signaling_mode_check CHECK (signaling_mode IN ('static', 'outbound_registration'))");
            DB::statement("ALTER TABLE trunk_endpoints ADD CONSTRAINT trunk_endpoints_registration_shape_check CHECK ((signaling_mode = 'static' AND registration_target IS NULL AND registration_realm IS NULL AND registration_identity IS NULL) OR (signaling_mode = 'outbound_registration' AND registration_target IS NOT NULL AND registration_realm IS NOT NULL AND registration_identity IS NOT NULL))");
            DB::statement("ALTER TABLE external_trunk_registration_observations ADD CONSTRAINT external_registration_observation_state_check CHECK (state IN ('not_configured', 'registering', 'registered', 'failed', 'expired', 'disabled'))");
            DB::statement("ALTER TABLE external_trunk_registration_observations ADD CONSTRAINT external_registration_failure_category_check CHECK (failure_category IS NULL OR failure_category IN ('auth_rejected', 'not_found', 'timeout', 'unreachable', 'expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_trunk_registration_observations');
        Schema::dropIfExists('kamailio_external_trunk_registration_view');
        Schema::table('trunk_endpoints', function (Blueprint $table): void {
            $table->dropIndex('trunk_endpoints_registration_idx');
            $table->dropColumn(['signaling_mode', 'registration_target', 'registration_realm', 'registration_identity']);
        });
    }
};
