<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create or replace view kamailio_inbound_runtime_target_view as '.$this->viewSql(true));
            $role = (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader');
            if ($role !== '') {
                DB::statement('grant select on kamailio_inbound_runtime_target_view to "'.str_replace('"', '""', $role).'"');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create or replace view kamailio_inbound_runtime_target_view as '.$this->viewSql(false));
            $role = (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader');
            if ($role !== '') {
                DB::statement('grant select on kamailio_inbound_runtime_target_view to "'.str_replace('"', '""', $role).'"');
            }
        }
    }

    private function viewSql(bool $enforceCapacity): string
    {
        $capacityPredicate = $enforceCapacity
            ? "              and (n.capacity_weight = 0 or n.active_telephony_work < n.capacity_weight)\n"
            : '';

        $sql = <<<'SQL'
            with runtime_load as (
                select n.*,
                    (select count(*) from conference_runtime_bindings b where b.tenant_id = n.tenant_id and b.runtime_node_id = n.id and b.status = 'active') +
                    (select count(*) from call_legs l where l.tenant_id = n.tenant_id and l.runtime_node_id = n.id and l.observed_state not in ('completed', 'failed', 'cancelled')) as active_telephony_work
                from runtime_nodes n
            )
            select n.tenant_id, n.id as runtime_node_id,
                'sip:' || e.host || ':' || e.port::text || ';transport=' || e.transport as sip_target,
                n.placement_priority,
                case when n.capacity_weight = 0 then 2147483647 else greatest(0, n.capacity_weight - n.active_telephony_work) end as available_capacity,
                n.active_telephony_work
            from runtime_load n
            join runtime_node_endpoints e on e.runtime_node_id = n.id
            left join runtime_node_k5c_placement_observations p on p.runtime_node_id = n.id and p.tenant_id = n.tenant_id
            where n.desired_state = 'active' and n.observed_state = 'ready'
              and coalesce(n.observed_configuration_version, 0) >= n.configuration_version
              and n.desired_execution_image is not null and n.observed_execution_image is not null
              and n.desired_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'
              and n.observed_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'
              and substring(n.desired_execution_image from '(sha256:[0-9a-f]{64})') = substring(n.observed_execution_image from '(sha256:[0-9a-f]{64})')
              and exists (select 1 from runtime_node_capabilities c where c.runtime_node_id = n.id and c.capability_key = 'call.control')
              and e.purpose = 'sip' and e.enabled = true
              and (n.placement_region is null and n.placement_zone is null or p.status in ('placed', 'ambiguous_multiple_nodes_observed')
                and (n.placement_region is null or p.observed_region = n.placement_region)
                and (n.placement_zone is null or p.observed_zone = n.placement_zone))
            __CAPACITY__
            order by n.placement_priority asc, available_capacity desc, active_telephony_work asc, n.id asc
            SQL;

        return str_replace('__CAPACITY__', rtrim($capacityPredicate), $sql);
    }
};
