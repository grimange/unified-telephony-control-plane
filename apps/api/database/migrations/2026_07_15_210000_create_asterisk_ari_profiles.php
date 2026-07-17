<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asterisk_ari_profiles', function (Blueprint $table): void {
            $table->uuid('runtime_node_id')->primary();
            $table->unsignedBigInteger('configuration_version');
            $table->string('application_name', 80);
            $table->unsignedInteger('connect_timeout_ms');
            $table->unsignedInteger('request_timeout_ms');
            $table->unsignedInteger('websocket_handshake_timeout_ms');
            $table->unsignedInteger('heartbeat_interval_ms');
            $table->unsignedInteger('reconnect_min_delay_ms');
            $table->unsignedInteger('reconnect_max_delay_ms');
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asterisk_ari_profiles');
    }
};
