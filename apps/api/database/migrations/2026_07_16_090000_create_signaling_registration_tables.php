<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_signaling_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('telephony_session_id');
            $table->string('username', 96);
            $table->string('realm', 120);
            $table->string('algorithm', 24)->default('MD5');
            $table->char('ha1', 32);
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('telephony_session_id')->references('id')->on('telephony_sessions')->cascadeOnDelete();
            $table->index(['tenant_id', 'username', 'realm'], 'signaling_credentials_identity_idx');
            $table->index(['revoked_at', 'expires_at'], 'signaling_credentials_active_idx');
        });

        Schema::create('signaling_registration_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('telephony_session_id');
            $table->string('signaling_identity', 96);
            $table->string('desired_state', 24)->default('eligible');
            $table->string('observed_state', 24)->default('unknown');
            $table->string('contact_ruid', 160)->nullable();
            $table->timestampTz('observed_expires_at')->nullable();
            $table->char('source_epoch', 32)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->string('last_event_type', 80)->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('telephony_session_id')->references('id')->on('telephony_sessions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'telephony_session_id'], 'signaling_registration_session_unique');
            $table->index(['tenant_id', 'desired_state']);
            $table->index(['tenant_id', 'observed_state']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create unique index signaling_credentials_one_active_per_session on telephony_signaling_credentials (telephony_session_id) where revoked_at is null');
            DB::statement("alter table telephony_signaling_credentials add constraint signaling_credentials_algorithm_check check (algorithm in ('MD5'))");
            DB::statement("alter table signaling_registration_observations add constraint signaling_registration_desired_state_check check (desired_state in ('eligible', 'removed'))");
            DB::statement("alter table signaling_registration_observations add constraint signaling_registration_observed_state_check check (observed_state in ('unregistered', 'registered', 'pending_removal', 'expired', 'unknown'))");
            DB::statement(<<<'SQL'
                create or replace view kamailio_signaling_auth_view as
                select c.username, c.realm as domain, c.ha1
                from telephony_signaling_credentials c
                join telephony_sessions s on s.id = c.telephony_session_id
                where c.revoked_at is null
                  and c.expires_at > now()
                  and s.status = 'active'
                  and s.expires_at > now()
                SQL);
        }

        $this->syncIdentityCatalog();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop view if exists kamailio_signaling_auth_view');
        }
        Schema::dropIfExists('signaling_registration_observations');
        Schema::dropIfExists('telephony_signaling_credentials');
    }

    private function syncIdentityCatalog(): void
    {
        $now = now();
        foreach (config('identity.capabilities', []) as $key => $definition) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        foreach (config('identity.roles', []) as $key => $definition) {
            DB::table('roles')->updateOrInsert(
                ['key' => $key],
                [
                    'scope' => $definition['scope'],
                    'display_name' => $definition['display_name'],
                    'built_in' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            foreach ($definition['capabilities'] as $capability) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_key' => $key, 'capability_key' => $capability],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
