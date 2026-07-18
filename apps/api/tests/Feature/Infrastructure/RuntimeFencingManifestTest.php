<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RuntimeFencingManifestTest extends TestCase
{
    public function test_runtime_fence_worker_uses_canonical_application_runtime_identity(): void
    {
        $objects = $this->runtimeFencingObjects();
        $deployment = $objects['Deployment/utcp-platform/utcp-runtime-fence-worker'];
        $podSpec = $deployment['spec']['template']['spec'];
        $podSecurity = $podSpec['securityContext'];
        $containerSecurity = $podSpec['containers'][0]['securityContext'];

        $this->assertTrue($podSecurity['runAsNonRoot']);
        $this->assertSame(33, $podSecurity['runAsUser']);
        $this->assertSame(33, $podSecurity['runAsGroup']);
        $this->assertSame('RuntimeDefault', $podSecurity['seccompProfile']['type']);
        $this->assertArrayNotHasKey('fsGroup', $podSecurity);
        $this->assertArrayNotHasKey('fsGroupChangePolicy', $podSecurity);
        $this->assertArrayNotHasKey('initContainers', $podSpec);

        $this->assertFalse($containerSecurity['allowPrivilegeEscalation']);
        $this->assertSame(['ALL'], $containerSecurity['capabilities']['drop']);
        $this->assertArrayNotHasKey('privileged', $containerSecurity);
    }

    public function test_runtime_fence_worker_keeps_namespace_config_token_and_rbac_boundaries(): void
    {
        $objects = $this->runtimeFencingObjects();
        $this->assertSame([
            'Deployment/utcp-platform/utcp-runtime-fence-worker',
            'Role/utcp-runtime/utcp-runtime-fencer',
            'RoleBinding/utcp-runtime/utcp-runtime-fencer',
            'ServiceAccount/utcp-platform/utcp-runtime-fencer',
        ], array_keys($objects));

        $deployment = $objects['Deployment/utcp-platform/utcp-runtime-fence-worker'];
        $podSpec = $deployment['spec']['template']['spec'];
        $container = $podSpec['containers'][0];

        $this->assertSame('utcp-runtime-fencer', $podSpec['serviceAccountName']);
        $this->assertFalse($podSpec['automountServiceAccountToken']);
        $this->assertSame(['telephony-infrastructure-worker'], $container['args']);
        $this->assertSame('utcp-application-config', $container['envFrom'][0]['configMapRef']['name']);
        $this->assertSame('utcp-local-data-credentials', $container['envFrom'][1]['secretRef']['name']);

        $tokenVolume = collect($podSpec['volumes'])
            ->firstWhere('name', 'kubernetes-api-credentials');
        $this->assertNotNull($tokenVolume);
        $this->assertSame(3600, $tokenVolume['projected']['sources'][0]['serviceAccountToken']['expirationSeconds']);
        $this->assertSame('https://kubernetes.default.svc', $tokenVolume['projected']['sources'][0]['serviceAccountToken']['audience']);

        $role = $objects['Role/utcp-runtime/utcp-runtime-fencer'];
        $this->assertSame([
            ['apiGroups' => ['apps'], 'resources' => ['deployments'], 'verbs' => ['get', 'list']],
            ['apiGroups' => ['apps'], 'resources' => ['deployments/scale'], 'verbs' => ['get', 'patch']],
            ['apiGroups' => [''], 'resources' => ['pods'], 'verbs' => ['get', 'list']],
        ], $role['rules']);

        $roleBinding = $objects['RoleBinding/utcp-runtime/utcp-runtime-fencer'];
        $this->assertSame([
            ['kind' => 'ServiceAccount', 'name' => 'utcp-runtime-fencer', 'namespace' => 'utcp-platform'],
        ], $roleBinding['subjects']);
        $this->assertSame('Role', $roleBinding['roleRef']['kind']);
        $this->assertSame('utcp-runtime-fencer', $roleBinding['roleRef']['name']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runtimeFencingObjects(): array
    {
        $root = dirname(base_path(), 2);
        $render = new Process(['kubectl', 'kustomize', 'infrastructure/kubernetes/components/runtime-fencing'], $root);
        $render->run();
        $this->assertSame(0, $render->getExitCode(), $render->getErrorOutput());

        $parse = new Process(['python3', '-c', <<<'PY'
import json
import sys
import yaml

items = {}
for doc in yaml.safe_load_all(sys.stdin.read()):
    if not doc:
        continue
    metadata = doc.get("metadata", {})
    key = f"{doc.get('kind')}/{metadata.get('namespace')}/{metadata.get('name')}"
    items[key] = doc
print(json.dumps({key: items[key] for key in sorted(items)}))
PY], $root);
        $parse->setInput($render->getOutput());
        $parse->run();
        $this->assertSame(0, $parse->getExitCode(), $parse->getErrorOutput());

        $objects = json_decode($parse->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($objects);

        return $objects;
    }
}
