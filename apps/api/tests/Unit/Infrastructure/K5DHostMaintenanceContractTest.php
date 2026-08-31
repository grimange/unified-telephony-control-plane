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
        $this->assertStringContainsString('$this->kubernetes->drainablePods($maintenance->node_name, $workloadIdentities)', $service);
        $this->assertStringContainsString("['namespace' => \$identity->namespace, 'deployment' => \$identity->deployment]", $service);
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
        $this->assertStringContainsString('$workloadIdentities', $client);
        $this->assertStringContainsString("app.kubernetes.io/instance", $client);
    }

    public function test_k5d_reconciliation_has_one_mutating_runtime_authority(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 3).'/app/RuntimeEngine/Reconciliation/ReconciliationWorker.php');
        $console = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($worker);
        $this->assertIsString($console);

        $this->assertStringContainsString('bool $includeHostMaintenance = false', $worker);
        $this->assertStringContainsString('if ($includeHostMaintenance)', $worker);
        $this->assertStringContainsString('includeHostMaintenance: true', $console);
        $this->assertStringContainsString("Schedule::call(function (): void", $console);
        $this->assertStringContainsString("name('runtime-engine:reconciler-scheduled')", $console);
        $this->assertStringNotContainsString("Schedule::command('runtime-engine:reconciler --once')", $console);
        $this->assertStringContainsString("':scheduler-reconciler:'", $console);
    }
}
