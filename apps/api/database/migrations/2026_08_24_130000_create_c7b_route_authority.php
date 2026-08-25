<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inbound_routes', 'outbound_routes'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name', 160);
                $table->string('slug', 100);
                $table->uuid('external_trunk_id');
                $table->uuid('telephony_address_id');
                $table->string('destination_ref', 255)->nullable();
                $table->uuid('caller_identity_id')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->string('desired_state', 24)->default('draft');
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();

                $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
                $table->foreign('external_trunk_id')->references('id')->on('external_trunks')->restrictOnDelete();
                $table->foreign('telephony_address_id')->references('id')->on('telephony_addresses')->restrictOnDelete();
                $table->foreign('caller_identity_id')->references('id')->on('caller_identities')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['tenant_id', 'slug'], $tableName.'_tenant_slug_unique');
                $table->index(['tenant_id', 'desired_state', 'priority'], $tableName.'_selection_idx');
                $table->index(['tenant_id', 'external_trunk_id', 'telephony_address_id'], $tableName.'_match_idx');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['inbound_routes', 'outbound_routes'] as $tableName) {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_state_check CHECK (desired_state IN ('draft', 'active', 'disabled', 'retired'))");
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_priority_check CHECK (priority > 0)");
            }
            DB::statement('ALTER TABLE inbound_routes ADD CONSTRAINT inbound_routes_destination_check CHECK (destination_ref IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_routes');
        Schema::dropIfExists('inbound_routes');
    }
};
