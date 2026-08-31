<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RuntimeFencingManifestTest extends TestCase
{
    public function test_runtime_fence_worker_uses_worker_and_api_client_network_labels(): void
    {
        $objects = $this->runtimeFencingObjects();
        $deployment = $objects['Deployment/utcp-platform/utcp-runtime-fence-worker'];
        $labels = $deployment['spec']['template']['metadata']['labels'];

        $this->assertSame('worker', $labels['utcp.io/network-role']);
        $this->assertSame('true', $labels['utcp.io/kubernetes-api-client']);
        $this->assertSame('runtime-fence-worker', $labels['app.kubernetes.io/component']);
    }

    public function test_only_observer_workloads_carry_kubernetes_api_client_label(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/base');
        $ordinaryApiClients = [];

        foreach ($objects as $key => $object) {
            if (($object['kind'] ?? null) !== 'Deployment') {
                continue;
            }

            $labels = $object['spec']['template']['metadata']['labels'] ?? [];
            if (($labels['utcp.io/kubernetes-api-client'] ?? null) === 'true') {
                $ordinaryApiClients[] = $key;
            }
        }

        $this->assertSame([
            'Deployment/utcp-platform/api',
            'Deployment/utcp-platform/scheduler',
            'Deployment/utcp-platform/telephony-reconciler',
        ], $ordinaryApiClients);
    }

    public function test_canonical_local_overlay_activates_only_the_dedicated_infrastructure_identity(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local');
        $worker = $objects['Deployment/utcp-platform/utcp-runtime-fence-worker'];

        $this->assertSame('utcp-runtime-fencer', $worker['spec']['template']['spec']['serviceAccountName']);

        foreach ($objects as $key => $object) {
            if (($object['kind'] ?? null) !== 'Deployment' || in_array($key, [
                'Deployment/utcp-platform/utcp-runtime-fence-worker',
                'Deployment/utcp-platform/telephony-reconciler',
            ], true)) {
                continue;
            }

            $this->assertNotSame(
                'utcp-runtime-fencer',
                $object['spec']['template']['spec']['serviceAccountName'] ?? null,
                $key.' must not use the infrastructure ServiceAccount',
            );
        }

        $reconciler = $objects['Deployment/utcp-platform/telephony-reconciler'];
        $this->assertTrue($reconciler['spec']['template']['spec']['automountServiceAccountToken']);
        $this->assertSame('utcp-kubernetes-maintenance', $reconciler['spec']['template']['spec']['serviceAccountName']);
        $this->assertArrayNotHasKey('volumeMounts', $reconciler['spec']['template']['spec']['containers'][0]);
        $this->assertArrayNotHasKey('volumes', $reconciler['spec']['template']['spec']);
    }

    public function test_k5d_maintenance_identity_is_separate_and_least_privileged(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/base/platform');
        $role = $objects['ClusterRole/None/utcp-kubernetes-maintenance'];
        $binding = $objects['ClusterRoleBinding/None/utcp-kubernetes-maintenance'];

        $this->assertSame([
            ['apiGroups' => [''], 'resources' => ['nodes'], 'verbs' => ['get', 'list', 'patch']],
            ['apiGroups' => [''], 'resources' => ['pods'], 'verbs' => ['get', 'list']],
            ['apiGroups' => [''], 'resources' => ['pods/eviction'], 'verbs' => ['create']],
        ], $role['rules']);
        $this->assertNotContains(
            ['apiGroups' => ['policy'], 'resources' => ['pods/eviction'], 'verbs' => ['create']],
            $role['rules'],
        );
        $this->assertSame('utcp-kubernetes-maintenance', $binding['subjects'][0]['name']);
        $this->assertSame('utcp-kubernetes-maintenance', $binding['roleRef']['name']);

        $observer = $objects['ClusterRoleBinding/None/utcp-kubernetes-observer-infrastructure-reader'];
        $this->assertSame('utcp-kubernetes-observer', $observer['subjects'][0]['name']);
        $this->assertNotContains('secrets', array_merge(...array_map(static fn (array $rule): array => $rule['resources'] ?? [], $role['rules'])));
    }

    public function test_common_worker_egress_is_reused_without_duplicate_data_service_policy(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/security');
        $workerPolicies = [];

        foreach ($objects as $key => $object) {
            if (($object['kind'] ?? null) !== 'NetworkPolicy') {
                continue;
            }

            $selector = $object['spec']['podSelector'] ?? [];
            if (($selector['matchLabels']['utcp.io/network-role'] ?? null) === 'worker') {
                $workerPolicies[$key] = $object;
            }
        }

        $this->assertArrayHasKey('NetworkPolicy/utcp-platform/allow-worker-required-egress', $workerPolicies);
        $this->assertArrayHasKey('NetworkPolicy/utcp-platform/allow-command-worker-to-asterisk-ari', $workerPolicies);
        $this->assertArrayHasKey('NetworkPolicy/utcp-platform/allow-command-worker-to-freeswitch-esl', $workerPolicies);
        $this->assertCount(3, $workerPolicies);

        $ports = $this->networkPolicyPorts($workerPolicies['NetworkPolicy/utcp-platform/allow-worker-required-egress']);
        sort($ports);
        $this->assertSame(['TCP:53', 'TCP:5432', 'TCP:6379', 'TCP:8080', 'UDP:53'], $ports);

        $allPolicies = $this->kustomizeObjects('infrastructure/kubernetes/security');
        $registrationPolicy = $allPolicies['NetworkPolicy/utcp-platform/allow-kamailio-registration-observer-required-egress'];
        $this->assertSame(
            ['app.kubernetes.io/component' => 'kamailio-registration-observer'],
            $registrationPolicy['spec']['podSelector']['matchLabels'],
        );
        $registrationRules = array_values(array_filter(
            $registrationPolicy['spec']['egress'],
            static fn (array $rule): bool => collect($rule['ports'] ?? [])->contains(
                static fn (array $port): bool => ($port['protocol'] ?? 'TCP') === 'TCP' && (int) $port['port'] === 8090,
            ),
        ));
        $this->assertCount(1, $registrationRules);
        $this->assertSame(
            [['podSelector' => ['matchLabels' => ['app.kubernetes.io/component' => 'kamailio']]]],
            $registrationRules[0]['to'],
        );
        $this->assertSame([['port' => 8090, 'protocol' => 'TCP']], $registrationRules[0]['ports']);

        $freeswitchPolicy = $workerPolicies['NetworkPolicy/utcp-platform/allow-command-worker-to-freeswitch-esl'];
        $this->assertSame(['Egress'], $freeswitchPolicy['spec']['policyTypes']);
        $this->assertSame(['utcp.io/network-role' => 'worker'], $freeswitchPolicy['spec']['podSelector']['matchLabels']);
        $this->assertSame(
            ['TCP:8021'],
            $this->networkPolicyPorts($freeswitchPolicy),
        );
        $this->assertSame(
            [
                [
                    'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'utcp-runtime']],
                    'podSelector' => ['matchLabels' => ['utcp.io/network-role' => 'freeswitch-esl']],
                ],
            ],
            $freeswitchPolicy['spec']['egress'][0]['to'],
        );
    }

    public function test_fencer_api_policy_is_endpoint_only_and_fencer_selected(): void
    {
        $policy = $this->yamlFile('infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml')[0];

        $this->assertSame('allow-runtime-fencer-kubernetes-api', $policy['metadata']['name']);
        $this->assertSame('utcp-platform', $policy['metadata']['namespace']);
        $this->assertSame(['utcp.io/kubernetes-api-client' => 'true'], $policy['spec']['podSelector']['matchLabels']);
        $this->assertSame(['Egress'], $policy['spec']['policyTypes']);

        $cidrs = $this->networkPolicyCidrs($policy);
        $ports = $this->networkPolicyPorts($policy);

        $this->assertSame(['__KUBERNETES_API_ENDPOINT_CIDR__'], $cidrs);
        $this->assertSame(['TCP:__KUBERNETES_API_ENDPOINT_PORT__'], $ports);
        $this->assertStringNotContainsString('__KUBERNETES_API_CIDR__', file_get_contents(base_path('../../infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml')));
    }

    public function test_endpoint_drift_check_accepts_current_endpoint_only_policy(): void
    {
        $path = $this->renderFencerPolicy('172.24.0.5', '6443');

        $this->assertDriftCheckPasses([$path], '172.24.0.5', '6443', '10.43.0.1');
    }

    public function test_endpoint_drift_check_accepts_all_endpoint_targeted_policy_fixtures(): void
    {
        $paths = [
            $this->renderTemplate('infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml', '172.24.0.5', '6443'),
            $this->renderTemplate('infrastructure/kubernetes/security/traefik/allow-apiserver-egress.template.yaml', '172.24.0.5', '6443'),
            $this->renderTemplate('infrastructure/kubernetes/observability/network-policies/allow-apiserver-egress.template.yaml', '172.24.0.5', '6443'),
        ];

        $this->assertDriftCheckPasses($paths, '172.24.0.5', '6443', '10.43.0.1');
    }

    public function test_endpoint_drift_check_rejects_stale_ip(): void
    {
        $path = $this->renderFencerPolicy('172.24.0.4', '6443');

        $this->assertDriftCheckFails([$path], '172.24.0.5', '6443', '10.43.0.1', 'stale endpoint destination');
    }

    public function test_endpoint_drift_check_rejects_stale_port(): void
    {
        $path = $this->renderFencerPolicy('172.24.0.5', '443');

        $this->assertDriftCheckFails([$path], '172.24.0.5', '6443', '10.43.0.1', 'stale endpoint port');
    }

    public function test_endpoint_drift_check_rejects_missing_policy(): void
    {
        $this->assertDriftCheckFails(['/tmp/utcp-missing-policy.yaml'], '172.24.0.5', '6443', '10.43.0.1', 'missing policy file');
    }

    public function test_endpoint_drift_check_rejects_service_clusterip_broad_cidr_and_fallbacks(): void
    {
        $clusterIp = $this->writePolicyFixture('clusterip', ['10.43.0.1/32'], [['TCP', 443]]);
        $broad = $this->writePolicyFixture('broad', ['0.0.0.0/0'], [['TCP', 6443]]);
        $fallback = $this->writePolicyFixture('fallback', ['172.24.0.5/32', '10.43.0.1/32'], [['TCP', 6443], ['TCP', 443]]);

        $this->assertDriftCheckFails([$clusterIp], '172.24.0.5', '6443', '10.43.0.1', 'Service ClusterIP destination is forbidden');
        $this->assertDriftCheckFails([$broad], '172.24.0.5', '6443', '10.43.0.1', 'broad all-destination CIDR is forbidden');
        $this->assertDriftCheckFails([$fallback], '172.24.0.5', '6443', '10.43.0.1', 'expected one destination');
    }

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

    public function test_runtime_fence_worker_projects_readable_ca_without_broadening_token_permissions(): void
    {
        $objects = $this->runtimeFencingObjects();
        $deployment = $objects['Deployment/utcp-platform/utcp-runtime-fence-worker'];
        $podSpec = $deployment['spec']['template']['spec'];
        $container = $podSpec['containers'][0];

        $volumeMount = collect($container['volumeMounts'])
            ->firstWhere('name', 'kubernetes-api-credentials');
        $this->assertNotNull($volumeMount);
        $this->assertSame('/var/run/secrets/kubernetes.io/serviceaccount', $volumeMount['mountPath']);
        $this->assertTrue($volumeMount['readOnly']);

        $projected = collect($podSpec['volumes'])
            ->firstWhere('name', 'kubernetes-api-credentials')['projected'];
        $this->assertSame(288, $projected['defaultMode']);
        $this->assertNotSame(292, $projected['defaultMode']);

        $tokenSources = collect($projected['sources'])
            ->filter(fn (array $source): bool => array_key_exists('serviceAccountToken', $source))
            ->values();
        $this->assertCount(1, $tokenSources);
        $token = $tokenSources[0]['serviceAccountToken'];
        $this->assertSame('token', $token['path']);
        $this->assertArrayNotHasKey('audience', $token);
        $this->assertSame(3600, $token['expirationSeconds']);
        $this->assertArrayNotHasKey('mode', $token);
        $this->assertSame($volumeMount['mountPath'].'/'.$token['path'], config('runtime_engine.kubernetes.token_path'));

        $configMap = collect($projected['sources'])
            ->first(fn (array $source): bool => array_key_exists('configMap', $source))['configMap'];
        $this->assertSame('kube-root-ca.crt', $configMap['name']);
        $this->assertCount(1, $configMap['items']);
        $this->assertSame('ca.crt', $configMap['items'][0]['key']);
        $this->assertSame('ca.crt', $configMap['items'][0]['path']);
        $this->assertSame(292, $configMap['items'][0]['mode']);
        $this->assertSame($volumeMount['mountPath'].'/'.$configMap['items'][0]['path'], config('runtime_engine.kubernetes.ca_path'));

        $manifest = file_get_contents(base_path('../../infrastructure/kubernetes/components/runtime-fencing/infrastructure-worker-deployment.yaml'));
        $this->assertStringNotContainsString('audience:', $manifest);
        $this->assertStringNotContainsString('https://kubernetes.default.svc', $manifest);
        $this->assertStringNotContainsString('k3s', $manifest);
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
        $this->assertArrayNotHasKey('audience', $tokenVolume['projected']['sources'][0]['serviceAccountToken']);
        $this->assertSame('token', $tokenVolume['projected']['sources'][0]['serviceAccountToken']['path']);

        $role = $objects['Role/utcp-runtime/utcp-runtime-fencer'];
        $this->assertSame([
            ['apiGroups' => ['apps'], 'resources' => ['deployments'], 'verbs' => ['get', 'list', 'create', 'patch', 'delete']],
            ['apiGroups' => ['apps'], 'resources' => ['replicasets'], 'verbs' => ['get', 'list']],
            ['apiGroups' => ['apps'], 'resources' => ['deployments/scale'], 'verbs' => ['get', 'patch']],
            ['apiGroups' => [''], 'resources' => ['pods'], 'verbs' => ['get', 'list']],
            ['apiGroups' => [''], 'resources' => ['services'], 'verbs' => ['get', 'create', 'patch', 'delete']],
            ['apiGroups' => [''], 'resources' => ['secrets'], 'verbs' => ['get', 'create', 'patch', 'delete']],
        ], $role['rules']);

        $roleBinding = $objects['RoleBinding/utcp-runtime/utcp-runtime-fencer'];
        $this->assertSame([
            ['kind' => 'ServiceAccount', 'name' => 'utcp-runtime-fencer', 'namespace' => 'utcp-platform'],
        ], $roleBinding['subjects']);
        $this->assertSame('Role', $roleBinding['roleRef']['kind']);
        $this->assertSame('utcp-runtime-fencer', $roleBinding['roleRef']['name']);
    }

    public function test_rnp_writer_role_has_no_cluster_or_forbidden_privileges_and_api_has_no_fencer_identity(): void
    {
        $objects = $this->runtimeFencingObjects();
        $role = $objects['Role/utcp-runtime/utcp-runtime-fencer'];
        $serializedRules = json_encode($role['rules'], JSON_THROW_ON_ERROR);

        foreach (['*', 'pods/exec', 'pods/attach', 'pods/portforward', 'nodes', 'namespaces', 'serviceaccounts', 'roles', 'rolebindings', 'clusterroles', 'clusterrolebindings', 'customresourcedefinitions'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $serializedRules);
        }
        $this->assertArrayNotHasKey('ClusterRole/utcp-runtime/utcp-runtime-fencer', $objects);
        $this->assertArrayNotHasKey('ClusterRoleBinding/utcp-runtime/utcp-runtime-fencer', $objects);

        $baseObjects = $this->kustomizeObjects('infrastructure/kubernetes/base');
        $api = $baseObjects['Deployment/utcp-platform/api'];
        $this->assertNotSame('utcp-runtime-fencer', $api['spec']['template']['spec']['serviceAccountName'] ?? null);
        $this->assertSame('true', $api['spec']['template']['metadata']['labels']['utcp.io/kubernetes-api-client'] ?? null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runtimeFencingObjects(): array
    {
        return $this->kustomizeObjects('infrastructure/kubernetes/components/runtime-fencing');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function kustomizeObjects(string $path): array
    {
        $root = dirname(base_path(), 2);
        $render = new Process(['kubectl', 'kustomize', $path], $root);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function yamlFile(string $path): array
    {
        $root = dirname(base_path(), 2);
        $parse = new Process(['python3', '-c', <<<'PY'
import json
import sys
import yaml

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    print(json.dumps([doc for doc in yaml.safe_load_all(handle) if doc]))
PY, $path], $root);
        $parse->run();
        $this->assertSame(0, $parse->getExitCode(), $parse->getErrorOutput());

        return json_decode($parse->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    private function networkPolicyCidrs(array $policy): array
    {
        $cidrs = [];
        foreach ($policy['spec']['egress'] ?? [] as $rule) {
            foreach ($rule['to'] ?? [] as $target) {
                if (isset($target['ipBlock']['cidr'])) {
                    $cidrs[] = (string) $target['ipBlock']['cidr'];
                }
            }
        }

        return $cidrs;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    private function networkPolicyPorts(array $policy): array
    {
        $ports = [];
        foreach ($policy['spec']['egress'] ?? [] as $rule) {
            foreach ($rule['ports'] ?? [] as $port) {
                $ports[] = ($port['protocol'] ?? 'TCP').':'.$port['port'];
            }
        }

        return $ports;
    }

    private function renderFencerPolicy(string $endpointIp, string $endpointPort): string
    {
        return $this->renderTemplate('infrastructure/kubernetes/security/kubernetes-api/allow-runtime-fencer-kubernetes-api.template.yaml', $endpointIp, $endpointPort);
    }

    private function renderTemplate(string $relativePath, string $endpointIp, string $endpointPort): string
    {
        $root = dirname(base_path(), 2);
        $template = file_get_contents($root.'/'.$relativePath);
        $path = tempnam(sys_get_temp_dir(), 'utcp-fencer-policy-');
        file_put_contents($path, str_replace(
            ['__KUBERNETES_API_ENDPOINT_CIDR__', '__KUBERNETES_API_ENDPOINT_PORT__'],
            [$endpointIp.'/32', $endpointPort],
            $template,
        ));

        return $path;
    }

    /**
     * @param  array<int, string>  $cidrs
     * @param  array<int, array{0: string, 1: int}>  $ports
     */
    private function writePolicyFixture(string $name, array $cidrs, array $ports): string
    {
        $to = implode("\n", array_map(static fn (string $cidr): string => "        - ipBlock:\n            cidr: {$cidr}", $cidrs));
        $portYaml = implode("\n", array_map(static fn (array $port): string => "        - protocol: {$port[0]}\n          port: {$port[1]}", $ports));
        $yaml = <<<YAML
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: allow-runtime-fencer-kubernetes-api-{$name}
  namespace: utcp-platform
spec:
  podSelector:
    matchLabels:
      utcp.io/kubernetes-api-client: "true"
  policyTypes:
    - Egress
  egress:
    - to:
{$to}
      ports:
{$portYaml}
YAML;
        $path = tempnam(sys_get_temp_dir(), 'utcp-policy-fixture-');
        file_put_contents($path, $yaml);

        return $path;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function assertDriftCheckPasses(array $paths, string $endpointIp, string $endpointPort, string $serviceIp): void
    {
        $process = $this->driftCheckProcess($paths, $endpointIp, $endpointPort, $serviceIp);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function assertDriftCheckFails(array $paths, string $endpointIp, string $endpointPort, string $serviceIp, string $expectedError): void
    {
        $process = $this->driftCheckProcess($paths, $endpointIp, $endpointPort, $serviceIp);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString($expectedError, $process->getErrorOutput());
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function driftCheckProcess(array $paths, string $endpointIp, string $endpointPort, string $serviceIp): Process
    {
        $root = dirname(base_path(), 2);
        $process = new Process(array_merge(['scripts/security/check-apiserver-policy-drift'], $paths), $root);
        $process->setEnv([
            'UTCP_SECURITY_EXPECTED_API_ENDPOINT_IP' => $endpointIp,
            'UTCP_SECURITY_EXPECTED_API_ENDPOINT_PORT' => $endpointPort,
            'UTCP_SECURITY_KUBERNETES_SERVICE_IP' => $serviceIp,
        ]);

        return $process;
    }
}
