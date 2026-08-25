<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $user = (string) env('KAMAILIO_REGISTRATION_DB_USER', 'utcp_kamailio_registration_reader');
        $password = env('KAMAILIO_REGISTRATION_DB_PASSWORD');

        if (! is_string($password) || trim($password) === '') {
            if (app()->environment('testing')) {
                $password = bin2hex(random_bytes(32));
            } else {
                throw new RuntimeException('KAMAILIO_REGISTRATION_DB_PASSWORD must be set for Kamailio database role provisioning.');
            }
        }

        $identifier = '"'.str_replace('"', '""', $user).'"';
        $quotedPassword = DB::getPdo()->quote($password);

        DB::statement(sprintf(
            'do $$ begin create role %s login password %s; exception when duplicate_object then alter role %s login password %s; end $$',
            $identifier,
            $quotedPassword,
            $identifier,
            $quotedPassword,
        ));
    }

    public function down(): void
    {
        // Preserve the shared provider role on rollback; the foundation migration
        // intentionally follows the same non-destructive role lifecycle.
    }
};
