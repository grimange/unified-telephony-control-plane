<?php

declare(strict_types=1);

use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\RuntimeProvisioning\ManagedAsteriskProvisioningOperationHandler;
use App\RuntimeProvisioning\ManagedFreeSwitchProvisioningOperationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Utcp\ActiveProof\ActiveAuthority;
use Utcp\ActiveProof\ActiveClaimBridge;
use Utcp\ActiveProof\ActiveFingerprint;
use Utcp\LifecycleProof\LifecycleFingerprint;

require_once __DIR__.'/ActiveAuthority.php';

final class ActiveProjectionParityTest extends TestCase
{
    use RefreshDatabase;

    // Actual locally inspected RepoDigests. These fixtures never start provider Pods.
    public const IMAGES = [
        'asterisk' => '127.0.0.1:5001/utcp/asterisk-ari@sha256:6027f3bc1464e4c7ed3485bb5a078a529e93ce3f04a2b3d3b0a03d5ac00598e9',
        'freeswitch' => '127.0.0.1:5001/utcp/freeswitch@sha256:3eedabc8afb2e3d1a4fc1e4ef8a6439bec2c7a30df4588566989dd9346ba3a11',
    ];

    protected function setUp(): void
    {
        parent::setUp(); Carbon::setTestNow('2030-01-02T03:04:05Z');
        config(['asterisk_ari.managed_image' => self::IMAGES['asterisk'], 'freeswitch_esl.managed_image' => self::IMAGES['freeswitch']]);
        $this->app->instance(KubernetesWorkloadClient::class, $this->createMock(KubernetesWorkloadClient::class));
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    public static function providers(): array { return [['asterisk'], ['freeswitch']]; }

    private function deployment(string $provider): array
    {
        $class = $provider === 'asterisk' ? ManagedAsteriskProvisioningOperationHandler::class : ManagedFreeSwitchProvisioningOperationHandler::class;
        return app($class)->desiredDeployment('11111111-2222-3333-4444-555555555555', 'managed-node');
    }

    #[DataProvider('providers')]
    public function test_actual_php_builder_exports_complete_provider_configuration(string $provider): void
    {
        $d = $this->deployment($provider); $pod = $d['spec']['template']['spec']; $c = $pod['containers'][0];
        $this->assertSame(self::IMAGES[$provider], $c['image']);
        $this->assertFalse($pod['automountServiceAccountToken']); $this->assertSame(30, $pod['terminationGracePeriodSeconds']);
        $this->assertSame(['cpu' => '50m', 'memory' => '128Mi'], $c['resources']['requests']);
        $this->assertSame(['cpu' => '500m', 'memory' => '384Mi'], $c['resources']['limits']);
        $this->assertSame(['ALL'], $c['securityContext']['capabilities']['drop']);
        $this->assertFalse($c['securityContext']['allowPrivilegeEscalation']);
        $this->assertArrayNotHasKey('command', $c); $this->assertArrayNotHasKey('args', $c);
        $this->assertArrayNotHasKey('serviceAccountName', $pod); $this->assertArrayNotHasKey('hostNetwork', $pod);
        $this->assertCount($provider === 'asterisk' ? 2 : 3, $c['ports']);
        $this->assertCount($provider === 'asterisk' ? 1 : 3, $pod['volumes']);
        $this->assertNotEmpty($c['readinessProbe']['exec']['command']);
        $this->assertNotEmpty($c['startupProbe']); $this->assertNotEmpty($c['livenessProbe']);
        $this->assertSame($provider === 'asterisk' ? 'asterisk-ari' : 'freeswitch-esl', $d['spec']['template']['metadata']['labels']['utcp.io/network-role']);
    }

    #[DataProvider('providers')]
    public function test_provisioning_requires_inert_creation_uid_binding_then_explicit_active_projection(string $provider): void
    {
        $authority = new ActiveAuthority; $r = $authority->prepare('tenant-a', 'node-a');
        $active = self::action($provider, $r); $this->denied(fn () => $authority->authorize($active));
        $create = self::lifecycleAction($r, 'create_suspended'); $authority->authorize($create);
        $this->assertTrue($authority->current($create)); $this->assertNull($create['target']['expected_uid']);
        $this->assertSame('inert.pause.v1', $create['intent']['projection']['template_version']);
        $bound = $authority->bind($r['incarnation_id'], $r['revision'], 'uid-a');
        $this->assertFalse($authority->current($create));
        $bound = $authority->next($r['incarnation_id']); $active = self::action($provider, $bound);
        $authority->authorize($active); $this->assertTrue($authority->current($active));
        $this->assertSame('bound', $active['canonical_state']); $this->assertSame(1, $active['intent']['replicas']);
        $this->assertSame('uid-a', $active['target']['expected_uid']);
    }

    #[DataProvider('providers')]
    public function test_convergence_has_global_generation_and_full_envelope_claim_matching(string $provider): void
    {
        [$a, $authority, $repository] = $this->claimed($provider); $bridge = new ActiveClaimBridge($authority);
        $this->assertTrue($bridge->verify($a)['authorized']); $authority->authorize($a);
        $this->assertTrue($bridge->verify(array_reverse($a, true))['authorized']);
        foreach ([['tenant_id'], ['runtime_node_id'], ['target', 'expected_uid'], ['target', 'incarnation_id'],
            ['target', 'namespace'], ['target', 'expected_resource_version'], ['intent', 'replicas'],
            ['intent', 'projection', 'secret_name'], ['claim', 'fencing_token'], ['operation_id'], ['request_id']] as $path) {
            $bad = $a; $v = &$bad; foreach ($path as $key) $v = &$v[$key];
            $v = is_int($v) ? 0 : ($path === ['claim', 'fencing_token'] ? str_repeat('f', 32) : ($path === ['target', 'incarnation_id'] ? str_repeat('b', 32) : 'mismatch')); unset($v);
            if ($path === ['target', 'incarnation_id']) $bad['target']['name'] = 'utcp-'.str_repeat('b', 32);
            $bad['intent']['projection_fingerprint'] = ActiveFingerprint::projection($bad['intent']['projection']);
            $bad['intent_fingerprint'] = ActiveFingerprint::calculate($bad);
            $this->assertFalse($bridge->verify($bad)['authorized'], implode('.', $path));
        }
        $new = $a; $r = $authority->next($a['target']['incarnation_id']); $new['target_generation'] = $r['generation'];
        $new['intent_fingerprint'] = ActiveFingerprint::calculate($new); $authority->authorize($new);
        $this->assertFalse($bridge->verify($a)['authorized']); $this->assertTrue($bridge->verify($new)['authorized']);
        $repository->cancel($a['operation_id']); $this->assertFalse($bridge->verify($new)['authorized']);
    }

    #[DataProvider('providers')]
    public function test_fencing_and_restoration_require_fresh_claim_and_current_active_incarnation(string $provider): void
    {
        [$a, $authority, $repository] = $this->claimed($provider); $bridge = new ActiveClaimBridge($authority);
        // Existing PHP telephony fencing/restoration is Asterisk-only. FreeSWITCH
        // exercises a bounded infrastructure extension, not existing domain parity.
        $r = $authority->next($a['target']['incarnation_id']); $fence = self::legacyScale($a, $r, 0);
        $authority->authorize($fence); $this->assertTrue($bridge->verify($fence)['authorized']);
        $this->assertFalse($bridge->verify($a)['authorized']);
        $r = $authority->next($r['incarnation_id']); $restore = self::action($provider, $r);
        $restore = $this->lease($restore); $authority->authorize($restore);
        $this->assertTrue($bridge->verify($restore)['authorized']); $this->assertFalse($bridge->verify($fence)['authorized']);
        $this->assertNotSame($a['claim']['fencing_token'], $restore['claim']['fencing_token']);
        $repository->cancel($restore['operation_id']); $this->assertFalse($bridge->verify($restore)['authorized']);
        $r = $authority->retire($r['incarnation_id']);
        $this->denied(fn () => $authority->authorize($restore));
        $oldRestore = self::legacyScale($a, $r, 1); $this->denied(fn () => $authority->authorize($oldRestore));
        $authority->complete($r['incarnation_id'], true); $this->denied(fn () => $authority->authorize($oldRestore));
    }

    #[DataProvider('providers')]
    public function test_deprovisioning_terminal_retirement_denies_active_and_legacy_restore(string $provider): void
    {
        [$active, $authority] = $this->claimed($provider); $r = $authority->retire($active['target']['incarnation_id']);
        $this->assertFalse((new ActiveClaimBridge($authority))->verify($active)['authorized']);
        $retire = $this->lease(self::lifecycleAction($r, 'retire_delete')); $authority->authorize($retire);
        $this->assertTrue((new ActiveClaimBridge($authority))->verify($retire)['authorized']);
        $this->denied(fn () => $authority->complete($r['incarnation_id'], false));
        $authority->complete($r['incarnation_id'], true);
        $this->assertFalse((new ActiveClaimBridge($authority))->verify($retire)['authorized']);
        $this->denied(fn () => $authority->authorize($active));
        $replacement = $authority->prepare('tenant-a', 'node-a'); $this->assertNotSame($r['name'], $replacement['name']);
    }

    public function test_cross_version_restore_and_inert_projection_cannot_bypass_lifecycle_authority(): void
    {
        $authority = new ActiveAuthority; $r = $authority->prepare('tenant-a', 'node-a');
        $active = self::action('asterisk', $r); $this->denied(fn () => $authority->authorize(self::legacyScale($active, $r, 1)));
        $r = $authority->bind($r['incarnation_id'], $r['revision'], 'uid-a');
        $this->denied(fn () => $authority->authorize(self::legacyScale($active, $r, 1)), 'inert bound resource is not an active profile');
        $active = self::action('asterisk', $r); $authority->authorize($active); $r = $authority->next($r['incarnation_id']);
        $inert = self::lifecycleAction($r, 'converge_projection'); $inert['intent']['replicas'] = 1;
        $inert['intent_fingerprint'] = LifecycleFingerprint::calculate($inert); $this->denied(fn () => $authority->authorize($inert));
    }

    public function test_malformed_profiles_and_ambiguous_generations_are_rejected_independently(): void
    {
        $a = self::action('asterisk', self::fixedRecord());
        foreach (['01', '0', '1e2', 1, 1.0, '9223372036854775808'] as $value) { $bad = $a; $bad['target_generation'] = $value; $this->denied(fn () => ActiveFingerprint::calculate($bad)); }
        foreach (['command', 'args', 'sidecars', 'hostPath', 'privileged', 'serviceAccountName', 'unknown'] as $key) {
            $bad = $a; $bad['intent']['projection'][$key] = 'unsafe'; $this->denied(fn () => ActiveFingerprint::calculate($bad));
        }
        foreach (['latest', 'registry.example.test/provider:latest', '', null] as $image) {
            $bad = $a; $bad['intent']['projection']['image'] = $image; $this->denied(fn () => ActiveFingerprint::calculate($bad));
        }
        $bad = $a; $bad['canonical_state'] = 'retiring'; $this->denied(fn () => ActiveFingerprint::calculate($bad));
        $bad = $a; $bad['intent']['projection']['template_version'] = 'asterisk.managed.v2'; $this->denied(fn () => ActiveFingerprint::calculate($bad));
    }

    public function test_all_corridors_preserve_error_parity_and_unknown_outcome_receipts(): void
    {
        foreach (['provisioning', 'convergence', 'fencing', 'restoration', 'deprovisioning'] as $corridor) {
            foreach (['success' => null, 'already_satisfied' => null, 'ownership_conflict' => 'ownership_conflict',
                'identity_mismatch' => 'target_mismatch', 'forbidden' => 'permission_denied',
                'resource_version_conflict' => 'fence_in_progress', 'stale_generation' => 'fence_in_progress', 'outcome_unknown' => 'unavailable_to_control'] as $outcome => $reason) {
                $result = ['contract_version' => 'utcp.infrastructure.v3', 'outcome' => $outcome,
                    'effect' => $outcome === 'outcome_unknown' ? 'unknown' : 'none',
                    'dispatch' => $outcome === 'outcome_unknown' ? 'unknown' : 'not_dispatched',
                    'retry' => $outcome === 'outcome_unknown' ? 'observe_first' : 'none'];
                $projected = \Utcp\GnG2Proof\ResultCompatibilityBridge::project($result);
                $this->assertSame($reason, $projected['php_exception_reason'], $corridor.'-'.$outcome);
                $this->assertSame($result, $projected['normalized'], 'Compatibility must preserve uncertainty receipt');
            }
        }
    }

    public function test_normative_v3_fixtures_have_independent_php_acceptance_and_hash_parity(): void
    {
        $fixtures = json_decode(file_get_contents('/active-contracts/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $fixtures['positive']); $this->assertCount(11, $fixtures['negative']);
        foreach ($fixtures['positive'] as $fixture) {
            $this->assertSame($fixture['action']['intent_fingerprint'], ActiveFingerprint::calculate($fixture['action']), $fixture['name']);
        }
        foreach ($fixtures['negative'] as $fixture) {
            $this->denied(fn () => ActiveFingerprint::calculate($fixture['action']), $fixture['name']);
        }
    }

    public function test_expired_claim_does_not_become_current_merely_because_resource_version_is_unchanged(): void
    {
        [$a, $authority] = $this->claimed('asterisk'); $rv = $a['target']['expected_resource_version']; $bridge = new ActiveClaimBridge($authority);
        $this->assertTrue($bridge->verify($a)['authorized']); Carbon::setTestNow(now()->addSeconds(61));
        $this->assertSame($rv, $a['target']['expected_resource_version']); $this->assertFalse($bridge->verify($a)['authorized']);
    }

    public function test_exports_actual_php_builders_independent_fingerprints_and_claims(): void
    {
        $vectors = []; $deployments = []; $claims = [];
        foreach (['asterisk', 'freeswitch'] as $provider) {
            $deployment = $this->deployment($provider); $deployments[] = ['provider' => $provider, 'deployment' => $deployment];
            foreach (['10', '9223372036854775807'] as $generation) {
                $r = self::fixedRecord(); $r['generation'] = $generation;
                $a = self::action($provider, $r, $deployment['spec']['template']['spec']['containers'][0]['envFrom'][0]['secretRef']['name']);
                $vectors[] = ['name' => $provider.'-'.$generation, 'action' => $a, 'expected' => ActiveFingerprint::calculate($a), 'projection' => ActiveFingerprint::projection($a['intent']['projection'])];
            }
            [$a, $authority] = $this->claimed($provider); $bridge = new ActiveClaimBridge($authority);
            $claims[] = ['name' => $provider.'-current', 'action' => $a, 'response' => $bridge->verify($a)];
            $this->assertTrue(end($claims)['response']['authorized']);
            $bad = $a; $bad['target']['expected_uid'] = 'replacement'; $bad['intent_fingerprint'] = ActiveFingerprint::calculate($bad);
            $claims[] = ['name' => $provider.'-replacement', 'action' => $bad, 'response' => $bridge->verify($bad)];
            $this->assertFalse(end($claims)['response']['authorized']);
            $authority->retire($a['target']['incarnation_id']);
            $claims[] = ['name' => $provider.'-retiring', 'action' => $a, 'response' => $bridge->verify($a)];
            $this->assertFalse(end($claims)['response']['authorized']);
        }
        $this->assertNotFalse(file_put_contents('/output/php-active-proof.json', json_encode(['fingerprints' => $vectors, 'deployments' => $deployments, 'claims' => $claims], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"));
        if (is_file('/output/go-active-proof.json')) {
            $reverse = json_decode(file_get_contents('/output/go-active-proof.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(4, $reverse['fingerprints']);
            foreach ($reverse['fingerprints'] as $vector) $this->assertSame(ActiveFingerprint::calculate($vector['action']), $vector['actual']);
        }
    }

    private function claimed(string $provider): array
    {
        $authority = new ActiveAuthority; $r = $authority->prepare('tenant-a', 'node-a'); $r = $authority->bind($r['incarnation_id'], $r['revision'], 'uid-a');
        $a = $this->lease(self::action($provider, $r)); $authority->authorize($a); return [$a, $authority, new RuntimeOperationRepository];
    }
    private function lease(array $a): array
    {
        $repository = new RuntimeOperationRepository;
        $a['operation_id'] = $repository->create('gn.g2.active.'.$a['action'], 'infrastructure.target', $a['target']['target_id'], ['action' => 'execute'], ExecutionContext::system(tenantId: $a['tenant_id']), runtimeNodeId: $a['runtime_node_id']);
        $claims = $repository->claimAvailable('active-worker', leaseSeconds: 60);
        $claim = collect($claims)->first(fn ($c) => $c->id === $a['operation_id']);
        if ($claim === null) throw new RuntimeException('Test operation was not claimed.');
        $a['claim'] = ['attempt' => $claim->attemptCount, 'fencing_token' => $claim->leaseToken, 'lease_expires_at' => now()->addSeconds(60)->toIso8601ZuluString()];
        $a['deadline'] = now()->addSeconds(5)->toIso8601ZuluString(); return $a;
    }
    private static function fixedRecord(): array { return ['incarnation_id' => str_repeat('a', 32), 'name' => 'utcp-'.str_repeat('a', 32), 'uid' => 'uid-a', 'generation' => '10']; }
    public static function action(string $provider, array $r, string $secret = 'runtime-credentials'): array
    {
        $p = ['template_version' => $provider.'.managed.v1', 'image' => self::IMAGES[$provider], 'configuration_id' => 'configuration-a', 'configuration_sha256' => str_repeat('a', 64), 'secret_name' => $secret];
        $a = ['contract_version' => 'utcp.infrastructure.v3', 'canonical_state' => 'bound', 'request_id' => 'request-a', 'operation_id' => 'operation-a', 'tenant_id' => 'tenant-a', 'runtime_node_id' => 'node-a',
            'target' => ['cluster_id' => 'cluster-a', 'namespace' => 'utcp-runtime', 'kind' => 'Deployment', 'name' => $r['name'], 'runtime_node_slug' => 'managed-node', 'target_id' => 'target-a', 'incarnation_id' => $r['incarnation_id'], 'expected_uid' => $r['uid'] ?? 'uid-a', 'expected_resource_version' => '1'],
            'target_generation' => $r['generation'], 'intent_fingerprint' => str_repeat('0', 64), 'action' => 'converge_projection',
            'intent' => ['kind' => 'converge_projection', 'projection' => $p, 'projection_fingerprint' => ActiveFingerprint::projection($p), 'replicas' => 1], 'idempotency_key' => 'idempotency-a',
            'claim' => ['attempt' => 1, 'fencing_token' => str_repeat('a', 32), 'lease_expires_at' => '2030-01-02T03:05:05Z'], 'deadline' => '2030-01-02T03:04:10Z', 'correlation_id' => 'correlation-a', 'audit_id' => 'audit-a'];
        $a['intent_fingerprint'] = ActiveFingerprint::calculate($a); return $a;
    }
    private static function lifecycleAction(array $r, string $kind): array
    {
        $p = ['template_version' => 'inert.pause.v1', 'image' => LifecycleFingerprint::IMAGE, 'configuration_id' => 'configuration-a', 'configuration_sha256' => str_repeat('a', 64)];
        $a = self::action('asterisk', [...$r, 'uid' => $r['uid'] ?? 'uid-a']); unset($a['canonical_state']); $a['contract_version'] = 'utcp.infrastructure.v2'; $a['action'] = $kind;
        if ($kind === 'create_suspended') { $a['target']['expected_uid'] = null; $a['target']['expected_resource_version'] = null; }
        $a['intent'] = $kind === 'retire_delete' ? ['kind' => $kind, 'retirement_id' => 'retirement-a', 'retirement_generation' => $r['generation'], 'retirement_fingerprint' => str_repeat('b', 64)]
            : ['kind' => $kind, 'projection' => $p, 'projection_fingerprint' => LifecycleFingerprint::projection($p)];
        if ($kind === 'converge_projection') $a['intent']['replicas'] = 0;
        $a['intent_fingerprint'] = LifecycleFingerprint::calculate($a); return $a;
    }
    private static function legacyScale(array $a, array $r, int $replicas): array
    {
        unset($a['canonical_state'], $a['intent']); $a['contract_version'] = 'utcp.infrastructure.v1'; $a['action'] = 'set_replicas'; $a['replicas'] = $replicas;
        $a['target_generation'] = $r['generation']; $a['target']['expected_uid'] = $r['uid'] ?? 'uid-a';
        $a['intent_fingerprint'] = ActiveFingerprint::versioned($a); return $a;
    }
    private function denied(callable $f, string $message = ''): void { try { $f(); $this->fail('Invalid intent accepted. '.$message); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); } }
}
