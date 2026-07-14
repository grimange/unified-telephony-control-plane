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
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 320);
            $table->string('normalized_email', 320)->unique();
            $table->string('display_name', 160);
            $table->string('password', 255);
            $table->string('status', 32)->default('active');
            $table->boolean('password_change_required')->default(false);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->index(['status', 'normalized_email'], 'users_status_email_idx');
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('display_name', 160);
            $table->string('status', 32)->default('active');
            $table->timestampsTz();
            $table->index(['status', 'slug'], 'tenants_status_slug_idx');
        });

        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('tenant_id');
            $table->string('status', 32)->default('active');
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['user_id', 'tenant_id'], 'tenant_memberships_user_tenant_unique');
            $table->index(['tenant_id', 'status'], 'tenant_memberships_tenant_status_idx');
            $table->index(['user_id', 'status'], 'tenant_memberships_user_status_idx');
        });

        Schema::create('capabilities', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->string('scope', 32);
            $table->string('description', 240);
            $table->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->string('scope', 32);
            $table->string('display_name', 160);
            $table->boolean('built_in')->default(true);
            $table->timestampsTz();
        });

        Schema::create('role_capabilities', function (Blueprint $table): void {
            $table->string('role_key', 80);
            $table->string('capability_key', 120);
            $table->timestampsTz();
            $table->foreign('role_key')->references('key')->on('roles')->cascadeOnDelete();
            $table->foreign('capability_key')->references('key')->on('capabilities')->cascadeOnDelete();
            $table->primary(['role_key', 'capability_key'], 'role_capabilities_primary');
        });

        Schema::create('platform_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('role_key', 80);
            $table->uuid('assigned_by_user_id')->nullable();
            $table->timestampTz('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_key')->references('key')->on('roles')->restrictOnDelete();
            $table->unique(['user_id', 'role_key'], 'platform_role_assignments_user_role_unique');
        });

        Schema::create('tenant_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('membership_id');
            $table->string('role_key', 80);
            $table->uuid('assigned_by_user_id')->nullable();
            $table->timestampTz('created_at');
            $table->foreign('membership_id')->references('id')->on('tenant_memberships')->cascadeOnDelete();
            $table->foreign('role_key')->references('key')->on('roles')->restrictOnDelete();
            $table->unique(['membership_id', 'role_key'], 'tenant_role_assignments_membership_role_unique');
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $this->syncCatalog();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended'))");
            DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active', 'suspended'))");
            DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active', 'suspended'))");
            DB::statement("ALTER TABLE capabilities ADD CONSTRAINT capabilities_scope_check CHECK (scope IN ('platform', 'tenant'))");
            DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('platform', 'tenant'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('tenant_role_assignments');
        Schema::dropIfExists('platform_role_assignments');
        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('users');
    }

    private function syncCatalog(): void
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
