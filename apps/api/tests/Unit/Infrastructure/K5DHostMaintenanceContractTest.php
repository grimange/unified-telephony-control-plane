<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class K5DHostMaintenanceContractTest extends TestCase
{
    public function test_maintenance_workflow_is_drain_first_and_uses_observed_identity(): void
    {
        $service = file_get_contents(dirname(__DIR__, 3).'/app/Infrastructure/Kubernetes/HostMaintenanceService.php');
        $this->assertIsString($service);
        $this->assertStringContainsString("'draining_telephony'", $service);
        $this->assertStringContainsString("'telephony_drained'", $service);
        $this->assertStringContainsString('$this->kubernetes->cordon', $service);
        $this->assertStringContainsString('$this->kubernetes->evict', $service);
        $this->assertStringContainsString("metadata.uid", $service);
        $this->assertStringContainsString('$this->registry->beginDrain', $service);
        $this->assertStringContainsString("\$desiredState !== 'drained'", $service);
        $this->assertStringNotContainsString('$this->registry->completeDrain', $service);

        $cordon = strpos($service, '$this->kubernetes->cordon');
        $drain = strpos($service, '$this->kubernetes->evict');
        $this->assertIsInt($cordon);
        $this->assertIsInt($drain);
        $this->assertLessThan($drain, $cordon);
    }

    public function test_product_uses_api_operations_and_has_no_production_kubectl_path(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/web.php');
        $client = file_get_contents(dirname(__DIR__, 3).'/app/Infrastructure/Kubernetes/HttpKubernetesMaintenanceClient.php');
        $this->assertIsString($routes);
        $this->assertIsString($client);
        $this->assertStringContainsString('maintenance', $routes);
        $this->assertStringContainsString("'/eviction'", $client);
        $this->assertStringNotContainsString('shell_exec', $client);
        $this->assertStringNotContainsString('kubectl', $client);
    }
}
