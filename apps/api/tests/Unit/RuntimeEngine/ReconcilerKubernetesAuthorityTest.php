<?php

namespace Tests\Unit\RuntimeEngine;

use Tests\TestCase;

final class ReconcilerKubernetesAuthorityTest extends TestCase
{
    public function test_runtime_node_reconcilers_do_not_mutate_kubernetes(): void
    {
        foreach (glob(base_path('app/RuntimeAdapters/*/*RuntimeNodeReconciler.php')) ?: [] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression('/\b(?:applyDeployment|applySecret|applyService|deleteDeployment|deleteService|deleteSecret|scaleDeployment)\s*\(/', $source, $path);
            $this->assertStringNotContainsString('listOwnedPods(', $source, $path);
        }
    }
}
