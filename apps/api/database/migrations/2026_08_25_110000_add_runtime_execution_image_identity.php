<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_nodes', function (Blueprint $table): void {
            $table->string('desired_execution_image', 512)->nullable()->after('configuration_version');
            $table->string('observed_execution_image', 512)->nullable()->after('desired_execution_image');
            $table->index(['adapter_key', 'desired_execution_image'], 'runtime_nodes_execution_image_idx');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_nodes', function (Blueprint $table): void {
            $table->dropIndex('runtime_nodes_execution_image_idx');
            $table->dropColumn(['desired_execution_image', 'observed_execution_image']);
        });
    }
};
