<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AsteriskTwoNodeTopologyTest extends TestCase
{
    private const STARTUP_COMMAND = ['/usr/local/bin/utcp-asterisk-readiness'];

    private const LIVENESS_COMMAND = [
        '/usr/sbin/asterisk',
        '-C',
        '/tmp/utcp-asterisk/asterisk.conf',
        '-rx',
        'core show uptime',
    ];

    public function test_staged_two_node_topology_and_registration_definitions_validate(): void
    {
        $process = $this->runRepositoryCommand(['scripts/asterisk-ari/validate-ab-topology']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('asterisk_ari_ab_topology_validation=ok', $process->getOutput());
    }

    public function test_registration_uniqueness_failures_are_rejected(): void
    {
        foreach (['duplicate-slug', 'duplicate-endpoint', 'duplicate-workload', 'duplicate-credential'] as $fixture) {
            $process = $this->runRepositoryCommand(['scripts/asterisk-ari/validate-ab-topology', '--fixture', $fixture]);

            $this->assertNotSame(0, $process->getExitCode(), $fixture.' unexpectedly validated');
            $this->assertStringContainsString('validation failed', $process->getErrorOutput());
        }
    }

    public function test_runtime_node_registration_definitions_use_canonical_api_payloads(): void
    {
        $nodeA = $this->registration('local-asterisk-ari-a');
        $nodeB = $this->registration('local-asterisk-ari-b');

        $this->assertSame('/api/v1/admin/runtime-nodes', $nodeA['runtime_node']['path']);
        $this->assertSame('/api/v1/admin/runtime-nodes', $nodeB['runtime_node']['path']);
        $this->assertSame('asterisk', $nodeA['runtime_node']['body']['runtime_family']);
        $this->assertSame('asterisk-ari', $nodeA['runtime_node']['body']['adapter_key']);
        $this->assertSame('asterisk', $nodeB['runtime_node']['body']['runtime_family']);
        $this->assertSame('asterisk-ari', $nodeB['runtime_node']['body']['adapter_key']);

        $this->assertSame('local-asterisk-ari-a', $nodeA['runtime_node']['body']['slug']);
        $this->assertSame('local-asterisk-ari-b', $nodeB['runtime_node']['body']['slug']);
        $this->assertSame('asterisk-ari-a', $nodeA['runtime_node']['body']['labels']['kubernetes_workload']['deployment']);
        $this->assertSame('asterisk-ari-b', $nodeB['runtime_node']['body']['labels']['kubernetes_workload']['deployment']);
        $this->assertSame('utcp-runtime', $nodeA['runtime_node']['body']['labels']['kubernetes_workload']['namespace']);
        $this->assertSame('utcp-runtime', $nodeB['runtime_node']['body']['labels']['kubernetes_workload']['namespace']);

        $this->assertSame(
            ['conference.lifecycle', 'conference.participation', 'event.stream', 'runtime.observation'],
            $nodeA['capabilities']['body']['capabilities'],
        );
        $this->assertSame($nodeA['capabilities']['body']['capabilities'], $nodeB['capabilities']['body']['capabilities']);
        $this->assertSame('active', $nodeA['desired_state']['body']['desired_state']);
        $this->assertSame('active', $nodeB['desired_state']['body']['desired_state']);

        $this->assertSame('utcp-local-asterisk-ari-a-credentials', $nodeA['credential']['kubernetes_secret']);
        $this->assertSame('utcp-local-asterisk-ari-b-credentials', $nodeB['credential']['kubernetes_secret']);
        $this->assertNotSame($nodeA['credential']['kubernetes_secret'], $nodeB['credential']['kubernetes_secret']);
        $this->assertSame('ari-basic', $nodeA['credential']['body']['credential_type']);
        $this->assertSame('ari-basic', $nodeB['credential']['body']['credential_type']);

        $this->assertSame(['asterisk-ari-a.utcp-runtime.svc.cluster.local'], $this->endpointHosts($nodeA));
        $this->assertSame(['asterisk-ari-b.utcp-runtime.svc.cluster.local'], $this->endpointHosts($nodeB));
        $this->assertSame(['control', 'events', 'health'], $this->endpointPurposes($nodeA));
        $this->assertSame(['control', 'events', 'health'], $this->endpointPurposes($nodeB));
    }

    public function test_asterisk_probe_authority_is_split_between_core_liveness_and_ari_readiness(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local-two-asterisk');

        $nodeA = $this->deployment($objects, 'asterisk-ari-a');
        $nodeB = $this->deployment($objects, 'asterisk-ari-b');

        $this->assertAsteriskProbeContract($nodeA);
        $this->assertAsteriskProbeContract($nodeB);
        $this->assertSame(
            $this->probeContract($nodeA),
            $this->probeContract($nodeB),
            'node A and node B must render identical probe contracts',
        );
    }

    public function test_asterisk_probe_change_preserves_selectors_identity_security_and_resources(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local-two-asterisk');

        foreach (['asterisk-ari-a', 'asterisk-ari-b'] as $deploymentName) {
            $deployment = $this->deployment($objects, $deploymentName);
            $service = $this->service($objects, $deploymentName);
            $container = $this->asteriskContainer($deployment);
            $podSpec = $deployment['spec']['template']['spec'];

            $this->assertSame(
                $deployment['spec']['selector']['matchLabels']['utcp.dev/runtime-node'],
                $service['spec']['selector']['utcp.dev/runtime-node'],
            );
            $this->assertSame('asterisk-ari', $service['spec']['selector']['app.kubernetes.io/component']);
            $this->assertSame('utcp-runtime-asterisk', $podSpec['serviceAccountName']);
            $this->assertFalse($podSpec['automountServiceAccountToken']);
            $this->assertTrue($podSpec['securityContext']['runAsNonRoot']);
            $this->assertSame('RuntimeDefault', $podSpec['securityContext']['seccompProfile']['type']);
            $this->assertFalse($container['securityContext']['allowPrivilegeEscalation']);
            $this->assertSame(['ALL'], $container['securityContext']['capabilities']['drop']);
            $this->assertSame(['cpu' => '50m', 'memory' => '128Mi'], $container['resources']['requests']);
            $this->assertSame(['cpu' => '500m', 'memory' => '384Mi'], $container['resources']['limits']);
        }
    }

    public function test_asterisk_rtp_configuration_reclaims_abandoned_media_with_bounded_timeouts(): void
    {
        $path = dirname(base_path(), 2).'/infrastructure/docker/asterisk/config/rtp.conf';
        $config = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression('/^rtp_timeout=30$/m', $config);
        $this->assertMatchesRegularExpression('/^rtp_timeout_hold=30$/m', $config);
        $pjsip = file_get_contents(dirname(base_path(), 2).'/infrastructure/docker/asterisk/config/pjsip.conf');
        $this->assertMatchesRegularExpression('/^rtp_timeout=30$/m', $pjsip);
        $this->assertMatchesRegularExpression('/^rtp_timeout_hold=30$/m', $pjsip);
        $this->assertLessThanOrEqual(60, 30, 'abandoned media timeout must remain bounded');
    }

    /**
     * @param  list<string>  $command
     */
    private function runRepositoryCommand(array $command): Process
    {
        $process = new Process($command, dirname(base_path(), 2));
        $process->run();

        return $process;
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
     * @param  array<string, array<string, mixed>>  $objects
     * @return array<string, mixed>
     */
    private function deployment(array $objects, string $name): array
    {
        $object = $objects["Deployment/utcp-runtime/{$name}"] ?? null;
        $this->assertIsArray($object, "missing Deployment/utcp-runtime/{$name}");

        return $object;
    }

    /**
     * @param  array<string, array<string, mixed>>  $objects
     * @return array<string, mixed>
     */
    private function service(array $objects, string $name): array
    {
        $object = $objects["Service/utcp-runtime/{$name}"] ?? null;
        $this->assertIsArray($object, "missing Service/utcp-runtime/{$name}");

        return $object;
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>
     */
    private function asteriskContainer(array $deployment): array
    {
        $containers = array_values(array_filter(
            $deployment['spec']['template']['spec']['containers'],
            static fn (array $container): bool => $container['name'] === 'asterisk',
        ));
        $this->assertCount(1, $containers);

        return $containers[0];
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    private function assertAsteriskProbeContract(array $deployment): void
    {
        $container = $this->asteriskContainer($deployment);
        $startup = $container['startupProbe'] ?? [];
        $readiness = $container['readinessProbe'] ?? [];
        $liveness = $container['livenessProbe'] ?? [];

        $this->assertSame(self::STARTUP_COMMAND, $startup['exec']['command'] ?? null);
        $this->assertSame(15, $startup['initialDelaySeconds'] ?? null);
        $this->assertSame(15, $startup['periodSeconds'] ?? null);
        $this->assertSame(5, $startup['timeoutSeconds'] ?? null);
        $this->assertSame(12, $startup['failureThreshold'] ?? null);

        $this->assertSame(self::STARTUP_COMMAND, $readiness['exec']['command'] ?? null);
        $this->assertNull($readiness['tcpSocket'] ?? null);
        $this->assertArrayNotHasKey('httpGet', $readiness);
        $this->assertSame(15, $readiness['periodSeconds'] ?? null);
        $this->assertSame(5, $readiness['timeoutSeconds'] ?? null);
        $this->assertSame(3, $readiness['failureThreshold'] ?? null);

        $this->assertSame(self::LIVENESS_COMMAND, $liveness['exec']['command'] ?? null);
        $this->assertNull($liveness['tcpSocket'] ?? null);
        $this->assertArrayNotHasKey('httpGet', $liveness);
        $this->assertSame(20, $liveness['periodSeconds'] ?? null);
        $this->assertSame(5, $liveness['timeoutSeconds'] ?? null);
        $this->assertSame(3, $liveness['failureThreshold'] ?? null);
        $this->assertLessThan(300, $liveness['periodSeconds'] * $liveness['failureThreshold']);

        $command = strtolower(implode(' ', self::LIVENESS_COMMAND));
        foreach (['reload', 'restart', ' stop ', 'unload', 'module', 'originate'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $command);
        }
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>
     */
    private function probeContract(array $deployment): array
    {
        $container = $this->asteriskContainer($deployment);

        return [
            'startupProbe' => $container['startupProbe'],
            'readinessProbe' => $container['readinessProbe'],
            'livenessProbe' => $container['livenessProbe'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $slug): array
    {
        $path = dirname(base_path(), 2)."/infrastructure/runtime-nodes/asterisk-ari/{$slug}.registration.json";
        $registration = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($registration);

        return $registration;
    }

    /**
     * @param  array<string, mixed>  $registration
     * @return list<string>
     */
    private function endpointHosts(array $registration): array
    {
        $hosts = array_values(array_unique(array_map(
            static fn (array $endpoint): string => $endpoint['body']['host'],
            $registration['endpoints'],
        )));
        sort($hosts);

        return $hosts;
    }

    /**
     * @param  array<string, mixed>  $registration
     * @return list<string>
     */
    private function endpointPurposes(array $registration): array
    {
        $purposes = array_map(
            static fn (array $endpoint): string => $endpoint['body']['purpose'],
            $registration['endpoints'],
        );
        sort($purposes);

        return array_values($purposes);
    }
}
