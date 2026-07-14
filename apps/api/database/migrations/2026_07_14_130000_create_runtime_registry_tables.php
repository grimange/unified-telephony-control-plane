<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncIdentityCatalog();

        Schema::create('runtime_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->string('runtime_family', 40);
            $table->string('adapter_key', 80);
            $table->string('desired_state', 32)->default('draft');
            $table->string('observed_state', 32)->default('unobserved');
            $table->timestampTz('observed_at')->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('placement_region', 80)->nullable();
            $table->string('placement_zone', 80)->nullable();
            $table->unsignedInteger('placement_priority')->default(100);
            $table->unsignedInteger('capacity_weight')->default(100);
            $table->json('labels')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'slug'], 'runtime_nodes_tenant_slug_unique');
            $table->index(['tenant_id', 'desired_state'], 'runtime_nodes_tenant_desired_idx');
            $table->index(['tenant_id', 'runtime_family'], 'runtime_nodes_tenant_family_idx');
        });

        Schema::create('runtime_node_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('runtime_node_id');
            $table->string('purpose', 40);
            $table->string('transport', 20);
            $table->string('host', 253);
            $table->unsignedInteger('port');
            $table->string('path', 255)->nullable();
            $table->string('tls_mode', 32)->default('disabled');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'purpose', 'transport', 'host', 'port', 'path'], 'runtime_node_endpoints_unique');
            $table->index(['runtime_node_id', 'purpose', 'enabled'], 'runtime_node_endpoints_node_purpose_idx');
        });

        Schema::create('runtime_node_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('runtime_node_id');
            $table->string('credential_type', 60);
            $table->string('identifier', 160)->nullable();
            $table->text('encrypted_secret');
            $table->string('secret_fingerprint', 64);
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('active');
            $table->timestampTz('rotated_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'credential_type', 'version'], 'runtime_node_credentials_version_unique');
            $table->index(['runtime_node_id', 'status'], 'runtime_node_credentials_node_status_idx');
        });

        Schema::create('runtime_node_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('runtime_node_id');
            $table->string('capability_key', 120);
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['runtime_node_id', 'capability_key'], 'runtime_node_capabilities_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_desired_state_check CHECK (desired_state IN ('draft', 'active', 'draining', 'disabled'))");
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_observed_state_check CHECK (observed_state IN ('unobserved', 'unknown'))");
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_runtime_family_check CHECK (runtime_family IN ('asterisk', 'freeswitch'))");
            DB::statement("ALTER TABLE runtime_nodes ADD CONSTRAINT runtime_nodes_adapter_key_check CHECK (adapter_key IN ('asterisk-ari', 'freeswitch-esl'))");
            DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_purpose_check CHECK (purpose IN ('control', 'events', 'health'))");
            DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_transport_check CHECK (transport IN ('http', 'https', 'tcp', 'tls', 'ws', 'wss'))");
            DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_tls_mode_check CHECK (tls_mode IN ('disabled', 'opportunistic', 'required', 'verify'))");
            DB::statement('ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_port_check CHECK (port BETWEEN 1 AND 65535)');
            DB::statement("ALTER TABLE runtime_node_credentials ADD CONSTRAINT runtime_node_credentials_status_check CHECK (status IN ('active', 'retired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_node_capabilities');
        Schema::dropIfExists('runtime_node_credentials');
        Schema::dropIfExists('runtime_node_endpoints');
        Schema::dropIfExists('runtime_nodes');
    }

    private function syncIdentityCatalog(): void
    {
        $now = now();

        foreach (Config::get('identity.capabilities', []) as $key => $capability) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $capability['scope'],
                    'description' => $capability['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach (Config::get('identity.roles', []) as $key => $role) {
            DB::table('roles')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $role['scope'],
                    'display_name' => $role['display_name'],
                    'built_in' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            foreach ($role['capabilities'] as $capabilityKey) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_key' => $key, 'capability_key' => $capabilityKey],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
