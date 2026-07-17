<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conference_recovery_metric_events', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('adapter_key', 80)->default('unknown');
            $table->string('resource_type', 64);
            $table->string('result', 64);
            $table->string('failure_class', 80)->default('none');
            $table->string('reason', 120)->default('none');
            $table->timestampsTz();

            $table->index('created_at');
            $table->index(['resource_type', 'result']);
            $table->index(['adapter_key', 'result']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table conference_recovery_metric_events add constraint conference_recovery_metric_events_resource_type_check check (resource_type in ('conference', 'conference_participant'))");
            DB::statement("alter table conference_recovery_metric_events add constraint conference_recovery_metric_events_result_check check (result in ('observed', 'unavailable', 'unsupported', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_recovery_metric_events');
    }
};
