<?php

namespace Tests\Unit\TelephonyDomain;

use PHPUnit\Framework\TestCase;

final class KamailioExternalTrunkRouteContractTest extends TestCase
{
    public function test_route_view_keeps_uuid_endpoint_identity_and_exposes_a_distinct_sip_destination_user(): void
    {
        $apiRoot = dirname(__DIR__, 3);
        $repositoryRoot = dirname($apiRoot, 1);
        $infrastructureRoot = is_dir($repositoryRoot.'/infrastructure') ? $repositoryRoot.'/infrastructure' : '/var/infrastructure';
        $migration = file_get_contents($apiRoot.'/database/migrations/2026_08_28_120000_repair_kamailio_external_trunk_route_contract.php');
        $config = file_get_contents($infrastructureRoot.'/kubernetes/base/platform/kamailio-configmap.yaml');

        self::assertIsString($migration);
        self::assertIsString($config);
        self::assertStringContainsString("(endpoint.value ->> 'endpoint_id')::uuid as endpoint_id", $migration);
        self::assertStringContainsString("(endpoint.value ->> 'endpoint_id')::uuid as trunk_endpoint_id", $migration);
        self::assertStringContainsString("route.value ->> 'address' as normalized_address", $migration);
        self::assertStringContainsString("route.value ->> 'destination_user' as destination_user", $migration);
        self::assertStringContainsString("r.trunk_endpoint_id = v.trunk_endpoint_id", $config);
        self::assertStringContainsString("v.destination_user = '\$rU'", $config);
        self::assertStringNotContainsString("v.normalized_address = '\$rU'", $config);
    }

    public function test_sip_uri_projection_contract_preserves_full_uri_and_projects_the_user_part(): void
    {
        $service = file_get_contents(dirname(__DIR__, 3).'/app/TelephonyDomain/Projection/ExternalTrunkProjectionService.php');

        self::assertIsString($service);
        self::assertStringContainsString("'address' => \$route->normalized_value", $service);
        self::assertStringContainsString("'destination_user' => \$this->destinationUser", $service);
        self::assertStringContainsString("preg_match('/^sips?:([^@]+)@/i'", $service);
    }
}
