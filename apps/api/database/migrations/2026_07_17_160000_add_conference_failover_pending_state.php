<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conferences', function (Blueprint $table): void {
            $table->string('failover_state', 40)->nullable()->after('observed_state');
            $table->uuid('failover_binding_id')->nullable()->after('failover_state');
            $table->unsignedInteger('failover_generation')->nullable()->after('failover_binding_id');
            $table->timestampTz('failover_started_at')->nullable()->after('failover_generation');
            $table->index(['tenant_id', 'failover_state'], 'conferences_failover_state_idx');
            $table->index(['failover_binding_id', 'failover_generation'], 'conferences_failover_authority_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table conferences add constraint conferences_failover_state_check check (failover_state is null or failover_state in ('pending_no_capacity'))");
            DB::statement('alter table conferences add constraint conferences_failover_generation_positive_check check (failover_generation is null or failover_generation > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table conferences drop constraint if exists conferences_failover_generation_positive_check');
            DB::statement('alter table conferences drop constraint if exists conferences_failover_state_check');
        }

        Schema::table('conferences', function (Blueprint $table): void {
            $table->dropIndex('conferences_failover_authority_idx');
            $table->dropIndex('conferences_failover_state_idx');
            $table->dropColumn([
                'failover_state',
                'failover_binding_id',
                'failover_generation',
                'failover_started_at',
            ]);
        });
    }
};
