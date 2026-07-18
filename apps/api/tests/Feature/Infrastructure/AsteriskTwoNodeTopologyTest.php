<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AsteriskTwoNodeTopologyTest extends TestCase
{
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
