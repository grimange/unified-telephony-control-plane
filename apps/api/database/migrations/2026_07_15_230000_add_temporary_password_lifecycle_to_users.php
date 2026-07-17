<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('temporary_password_issued_at')->nullable()->after('password_change_required');
            $table->timestampTz('temporary_password_expires_at')->nullable()->after('temporary_password_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['temporary_password_issued_at', 'temporary_password_expires_at']);
        });
    }
};
