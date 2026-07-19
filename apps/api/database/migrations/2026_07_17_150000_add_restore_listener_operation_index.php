<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_operations', function (Blueprint $table): void {
            $table->index(['operation_type', 'status', 'tenant_id', 'runtime_node_id'], 'runtime_ops_type_status_tenant_node_idx');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_operations', function (Blueprint $table): void {
            $table->dropIndex('runtime_ops_type_status_tenant_node_idx');
        });
    }
};
