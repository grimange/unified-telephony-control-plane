<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kamailio_registration_poll_health', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('poll_success_count')->default(0);
            $table->unsignedBigInteger('poll_failure_count')->default(0);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kamailio_registration_poll_health');
    }
};
