<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_participants', function (Blueprint $table): void {
            $table->timestampTz('runtime_channel_lost_at')->nullable()->after('runtime_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('conference_participants', function (Blueprint $table): void {
            $table->dropColumn('runtime_channel_lost_at');
        });
    }
};
