<?php

declare(strict_types=1);

use App\ControlPlane\InfrastructureAuthority\ActiveFingerprint;
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic fixtures only; this helper is never loaded by production bootstrap. */
final class CanonicalPgFixture
{
    public static function node(string $family = 'asterisk'): array
    {
        $tenant = (string) Str::uuid();
        $node = (string) Str::uuid();
        $suffix = substr(str_replace('-', '', $node), 0, 12);
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'canonical-'.$suffix, 'display_name' => 'Synthetic canonical proof', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $node, 'tenant_id' => $tenant, 'name' => 'Canonical proof '.$suffix, 'slug' => 'canonical-'.$suffix, 'runtime_family' => $family, 'adapter_key' => $family === 'asterisk' ? 'asterisk-ari' : 'freeswitch-esl', 'desired_state' => 'active', 'observed_state' => 'unobserved', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenant, $node];
    }

    public static function configuration(): array
    {
        return ['image' => 'registry.example.test/utcp/asterisk-ari@sha256:'.str_repeat('a', 64), 'configuration_id' => 'configuration-immutable-v1', 'configuration_sha256' => str_repeat('a', 64), 'secret_name' => 'runtime-config-v1'];
    }

    public static function prepared(string $key = 'preparing'): array
    {
        [$tenant, $node] = self::node();
        $service = new CanonicalInfrastructureService;
        $context = ExecutionContext::system(tenantId: $tenant);

        return [$tenant, $node, $service->prepare($context, $node, 'canonical-proof-cluster', 'canonical-proof', 'asterisk.managed.v1', self::configuration(), $key)];
    }

    public static function action(array $r, string $version = 'utcp.infrastructure.v3', string $kind = 'converge_projection'): array
    {
        $path = $version === 'utcp.infrastructure.v3' ? '/proposals/gn-g2/contracts/v3/fixtures.json' : '/proposals/gn-g2/contracts/v2/fixtures.json';
        $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $a = null;
        foreach ($fixtures['positive'] as $entry) {
            if ($entry['action']['action'] === ($version === 'utcp.infrastructure.v1' ? 'converge_projection' : $kind)) {
                $a = $entry['action'];
                break;
            }
        }
        if ($a === null) {
            throw new RuntimeException('Missing synthetic action fixture.');
        }
        if ($version === 'utcp.infrastructure.v1') {
            unset($a['intent'], $a['canonical_state']);
            $a['contract_version'] = $version;
            $a['action'] = $kind;
            $a['replicas'] = 0;
        }
        $repo = new RuntimeOperationRepository;
        $type = match ($kind) {
            'create_suspended' => 'runtime.node.provision', 'retire_delete' => 'runtime.node.deprovision', 'admit_generation' => 'runtime.node.provision', 'set_replicas' => 'runtime.node.runtime.fence', default => $version === 'utcp.infrastructure.v3' ? 'runtime.node.provision' : 'runtime.node.workload.converge'
        };
        $context = ExecutionContext::system(tenantId: $r['tenant_id']);
        $payload = $kind === 'set_replicas' ? self::fencePayload($r) : ['purpose' => 'synthetic-canonical-proof'];
        $operationId = $repo->create($type, 'runtime_node', $r['runtime_node_id'], $payload, $context, runtimeNodeId: $r['runtime_node_id']);
        $claim = $repo->claimAvailable('canonical-proof-worker', leaseSeconds: 3600, includeOperationTypes: [$type])[0];
        $op = DB::table('runtime_operations')->where('id', $operationId)->first();
        $a['operation_id'] = $operationId;
        $a['tenant_id'] = $r['tenant_id'];
        $a['runtime_node_id'] = $r['runtime_node_id'];
        $a['target'] = ['cluster_id' => $r['cluster_id'], 'namespace' => $r['namespace'], 'kind' => 'Deployment', 'name' => $r['physical_name'], 'runtime_node_slug' => $r['runtime_node_slug'], 'target_id' => $r['target_id'], 'incarnation_id' => $r['id'], 'expected_uid' => $r['kubernetes_uid'], 'expected_resource_version' => $r['kubernetes_uid'] === null ? null : '10'];
        $a['target_generation'] = $r['generation'];
        if ($kind === 'retire_delete') {
            $a['intent']['retirement_generation'] = $r['generation'];
        }
        $a['claim'] = ['attempt' => $claim->attemptCount, 'fencing_token' => $claim->leaseToken, 'lease_expires_at' => Carbon::parse($op->lease_expires_at)->toIso8601ZuluString()];
        $a['deadline'] = now()->addMinutes(30)->toIso8601ZuluString();
        if ($version === 'utcp.infrastructure.v3') {
            $a['intent']['projection'] = ['template_version' => $r['profile']] + $r['configuration'];
            $a['intent']['projection_fingerprint'] = ActiveFingerprint::projection($a['intent']['projection']);
        }
        $a['intent_fingerprint'] = ActiveFingerprint::versioned($a);

        return $a;
    }

    private static function fencePayload(array $r): array
    {
        $conference = (string) Str::uuid();
        $binding = (string) Str::uuid();
        DB::table('conferences')->insert(['id' => $conference, 'tenant_id' => $r['tenant_id'], 'slug' => 'proof-'.Str::random(12), 'display_name' => 'Synthetic fence proof', 'runtime_node_id' => $r['runtime_node_id'], 'desired_state' => 'open', 'configuration_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conference_runtime_bindings')->insert(['id' => $binding, 'tenant_id' => $r['tenant_id'], 'conference_id' => $conference, 'runtime_node_id' => $r['runtime_node_id'], 'status' => 'active', 'bound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $replacement = (string) Str::uuid();
        DB::table('runtime_nodes')->insert(['id' => $replacement, 'tenant_id' => $r['tenant_id'], 'name' => 'Synthetic replacement', 'slug' => 'replacement-'.Str::lower(Str::random(8)), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
            DB::table('runtime_node_capabilities')->insert(['id' => (string) Str::uuid(), 'runtime_node_id' => $replacement, 'capability_key' => $capability, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['conference_id' => $conference, 'former_runtime_binding_id' => $binding, 'former_runtime_node_id' => $r['runtime_node_id'], 'configuration_generation' => 1];
    }
}
