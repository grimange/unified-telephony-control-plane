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

        DB::statement('drop view if exists kamailio_external_trunk_route_view');
        DB::statement(<<<'SQL'
            create view kamailio_external_trunk_route_view as
            select
                projection.external_trunk_id as canonical_external_trunk_id,
                projection.artifact::jsonb ->> 'provider_local_trunk_id' as provider_local_trunk_id,
                projection.artifact::jsonb ->> 'desired_state' as desired_state,
                (projection.artifact::jsonb ->> 'accept_new_calls')::boolean as accept_new_calls,
                route.value ->> 'route_id' as route_id,
                route.value ->> 'direction' as direction,
                route.value ->> 'address_id' as telephony_address_id,
                route.value ->> 'address' as normalized_address,
                route.value ->> 'destination_ref' as destination_ref,
                route.value ->> 'caller_identity_id' as caller_identity_id,
                endpoint.value ->> 'endpoint_id' as endpoint_id,
                endpoint.value ->> 'uri' as endpoint_uri,
                endpoint.value ->> 'transport' as transport,
                projection.tenant_id as tenant_id,
                endpoint.value ->> 'endpoint_id' as trunk_endpoint_id,
                regexp_replace(regexp_replace(regexp_replace(endpoint.value ->> 'uri', '^sips?:', ''), '^.*@', ''), '[:;].*$', '') as provider_host
            from external_trunk_projection_artifacts projection
            cross join lateral jsonb_array_elements(projection.artifact::jsonb -> 'routes') route(value)
            cross join lateral jsonb_array_elements(projection.artifact::jsonb -> 'endpoints') endpoint(value)
            where projection.provider = 'kamailio'
              and projection.desired_state = 'active'
              and projection.observed_state = 'projected'
              and (projection.artifact::jsonb ->> 'accept_new_calls')::boolean = true
            SQL);

        $role = (string) env('KAMAILIO_AUTH_DB_USER', 'utcp_kamailio_auth_reader');
        if ($role !== '') {
            DB::statement('grant select on kamailio_external_trunk_route_view to "'.$this->quoteIdentifier($role).'"');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop view if exists kamailio_external_trunk_route_view');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return str_replace('"', '""', $identifier);
    }
};
