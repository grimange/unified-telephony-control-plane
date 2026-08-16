<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_participants', function (Blueprint $table): void {
            $table->string('runtime_channel_id', 255)->nullable()->after('admission_reason');
            $table->index('runtime_channel_id', 'conference_participants_runtime_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conference_participants', function (Blueprint $table): void {
            $table->dropIndex('conference_participants_runtime_channel_idx');
            $table->dropColumn('runtime_channel_id');
        });
    }
};
