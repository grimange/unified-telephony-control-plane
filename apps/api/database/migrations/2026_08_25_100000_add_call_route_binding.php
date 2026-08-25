<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->uuid('route_decision_id')->nullable();
            $table->uuid('outbound_route_id')->nullable();
            $table->uuid('external_trunk_id')->nullable();
            $table->uuid('trunk_endpoint_id')->nullable();
            $table->string('destination_ref', 255)->nullable();
            $table->string('caller_identity_ref', 255)->nullable();
            $table->index(['tenant_id', 'external_trunk_id'], 'call_legs_trunk_idx');
        });
    }

    public function down(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropIndex('call_legs_trunk_idx');
            $table->dropColumn(['route_decision_id', 'outbound_route_id', 'external_trunk_id', 'trunk_endpoint_id', 'destination_ref', 'caller_identity_ref']);
        });
    }
};
