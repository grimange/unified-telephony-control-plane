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
            create or replace view kamailio_conference_route_view as
            select
                'conf-' || cp.id::text as admission_user,
                credential.username as signaling_identity,
                cp.tenant_id,
                cp.conference_id,
                cp.id as participant_id,
                binding.runtime_node_id,
                'sip:' || endpoint.host || ':' || endpoint.port::text || ';transport=' || endpoint.transport as sip_target
            from conference_participants cp
            join conferences conference_record
              on conference_record.id = cp.conference_id
             and conference_record.tenant_id = cp.tenant_id
            join conference_runtime_bindings binding
              on binding.conference_id = conference_record.id
             and binding.tenant_id = conference_record.tenant_id
             and binding.runtime_node_id = conference_record.runtime_node_id
             and binding.status = 'active'
            join runtime_nodes node
              on node.id = binding.runtime_node_id
             and node.tenant_id = binding.tenant_id
            join runtime_node_endpoints endpoint
              on endpoint.runtime_node_id = node.id
             and endpoint.purpose = 'sip'
             and endpoint.transport = 'udp'
             and endpoint.enabled = true
             and endpoint.port = 5060
            join telephony_sessions session_record
              on session_record.id = cp.telephony_session_id
             and session_record.tenant_id = cp.tenant_id
            join telephony_signaling_credentials credential
              on credential.telephony_session_id = session_record.id
             and credential.tenant_id = session_record.tenant_id
             and credential.revoked_at is null
             and credential.expires_at > now()
             and credential.algorithm = 'MD5'
            where cp.desired_state = 'admitted'
              and cp.admission_reason = 'self_admission'
              and conference_record.desired_state = 'open'
              and session_record.status = 'active'
              and session_record.expires_at > now()
              and node.desired_state = 'active'
              and node.observed_state = 'ready'
            SQL);

        $role = (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader');
        if ($role !== '') {
            DB::statement('grant select on kamailio_conference_route_view to "'.$this->quoteIdentifier($role).'"');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop view if exists kamailio_conference_route_view');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return str_replace('"', '""', $identifier);
    }
};
