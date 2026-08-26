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

        DB::statement(<<<'SQL'
            create or replace view kamailio_inbound_runtime_target_view as
            select
                n.tenant_id,
                n.id as runtime_node_id,
                'sip:' || e.host || ':' || e.port::text || ';transport=' || e.transport as sip_target,
                n.placement_priority
            from runtime_nodes n
            join runtime_node_endpoints e on e.runtime_node_id = n.id
            where n.desired_state = 'active'
              and n.observed_state = 'ready'
              and coalesce(n.observed_configuration_version, 0) >= n.configuration_version
              and n.desired_execution_image is not null
              and n.observed_execution_image is not null
              and n.desired_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'
              and n.observed_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'
              and substring(n.desired_execution_image from '(sha256:[0-9a-f]{64})') = substring(n.observed_execution_image from '(sha256:[0-9a-f]{64})')
              and exists (
                  select 1 from runtime_node_capabilities c
                  where c.runtime_node_id = n.id and c.capability_key = 'call.control'
              )
              and e.purpose = 'sip'
              and e.enabled = true
            SQL);

        $role = (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader');
        if ($role !== '') {
            DB::statement('grant select on kamailio_inbound_runtime_target_view to "'.$this->quoteIdentifier($role).'"');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop view if exists kamailio_inbound_runtime_target_view');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return str_replace('"', '""', $identifier);
    }
};
