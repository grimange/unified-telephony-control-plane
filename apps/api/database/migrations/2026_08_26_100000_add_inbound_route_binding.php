<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->uuid('inbound_route_id')->nullable()->after('outbound_route_id');
            $table->index(['tenant_id', 'inbound_route_id'], 'call_legs_inbound_route_idx');
        });
    }

    public function down(): void
    {
        Schema::table('call_legs', function (Blueprint $table): void {
            $table->dropIndex('call_legs_inbound_route_idx');
            $table->dropColumn('inbound_route_id');
        });
    }
};
