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

        DB::statement('ALTER TABLE runtime_node_endpoints DROP CONSTRAINT IF EXISTS runtime_node_endpoints_purpose_check');
        DB::statement('ALTER TABLE runtime_node_endpoints DROP CONSTRAINT IF EXISTS runtime_node_endpoints_transport_check');
        DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_purpose_check CHECK (purpose IN ('control', 'events', 'health', 'sip'))");
        DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_transport_check CHECK (transport IN ('http', 'https', 'tcp', 'tls', 'udp', 'ws', 'wss'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DELETE FROM runtime_node_endpoints WHERE purpose = 'sip' OR transport = 'udp'");
        DB::statement('ALTER TABLE runtime_node_endpoints DROP CONSTRAINT IF EXISTS runtime_node_endpoints_purpose_check');
        DB::statement('ALTER TABLE runtime_node_endpoints DROP CONSTRAINT IF EXISTS runtime_node_endpoints_transport_check');
        DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_purpose_check CHECK (purpose IN ('control', 'events', 'health'))");
        DB::statement("ALTER TABLE runtime_node_endpoints ADD CONSTRAINT runtime_node_endpoints_transport_check CHECK (transport IN ('http', 'https', 'tcp', 'tls', 'ws', 'wss'))");
    }
};
