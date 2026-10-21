<?php

declare(strict_types=1);

use App\ControlPlane\RuntimeOperations\FencingViolation;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Utcp\LifecycleProof\LifecycleAuthority;
use Utcp\LifecycleProof\LifecycleClaimBridge;
use Utcp\LifecycleProof\LifecycleFingerprint;

require_once __DIR__.'/LifecycleAuthority.php';

final class LifecycleParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow('2030-01-02T03:04:05Z'); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_lifecycle_is_one_way_with_never_reused_names_and_terminal_tombstones(): void
    {
        $authority = new LifecycleAuthority; $first = $authority->prepare('tenant-a', 'node-a');
        $this->assertSame('preparing', $first['state']);
        $bound = $authority->bind($first['incarnation_id'], 1, 'uid-a');
        $this->assertSame('bound', $bound['state']);
        $this->assertSame($bound, $authority->bind($first['incarnation_id'], 1, 'uid-a'), 'same UID binding is idempotent');
        $this->denied(fn () => $authority->bind($first['incarnation_id'], 1, 'uid-b'));
        $retiring = $authority->retire($first['incarnation_id'], $bound['revision']);
        $this->assertSame('retiring', $retiring['state']);
        $this->denied(fn () => $authority->completeRetirement($first['incarnation_id'], $retiring['revision'], false));
        $retired = $authority->completeRetirement($first['incarnation_id'], $retiring['revision'], true);
        $this->assertSame('retired', $retired['state']);
        $this->denied(fn () => $authority->bind($first['incarnation_id'], $retired['revision'], 'uid-a'));
        $this->denied(fn () => $authority->retire($first['incarnation_id'], $retired['revision']));
        $replacement = $authority->prepare('tenant-a', 'node-a');
        $this->assertNotSame($first['name'], $replacement['name']);
        $this->assertNotSame($first['incarnation_id'], $replacement['incarnation_id']);
        $this->assertSame('retired', $authority->read($first['incarnation_id'])['state']);
    }

    public function test_competing_uid_binding_uses_canonical_revision_cas(): void
    {
        $authority = new LifecycleAuthority; $r = $authority->prepare('tenant-a', 'node-a');
        $workerA = $authority->read($r['incarnation_id']); $workerB = $authority->read($r['incarnation_id']);
        $authority->bind($r['incarnation_id'], $workerA['revision'], 'uid-a');
        $this->denied(fn () => $authority->bind($r['incarnation_id'], $workerB['revision'], 'uid-b'));
        $this->assertSame('uid-a', $authority->read($r['incarnation_id'])['uid']);
        // Sequential deterministic CAS interleaving models the rule, not PostgreSQL multi-process locking.
    }

    public function test_only_canonical_model_allocates_generations_with_revision_protection(): void
    {
        $authority = new LifecycleAuthority; $first = $authority->prepare('tenant-a', 'node-a');
        $next = $authority->allocateGeneration($first['incarnation_id'], $first['revision']);
        $this->assertSame('2', $next['generation']);
        $this->denied(fn () => $authority->allocateGeneration($first['incarnation_id'], $first['revision']));
        $retiring = $authority->retire($first['incarnation_id'], $next['revision']);
        $this->assertSame('3', $retiring['generation']);
        $this->denied(fn () => $authority->allocateGeneration($first['incarnation_id'], $retiring['revision']));
    }

    public function test_retirement_invalidates_create_and_projection_and_only_allows_cleanup_binding(): void
    {
        [$create, $authority] = $this->claimed(); $bridge = new LifecycleClaimBridge($authority);
        $this->assertTrue($bridge->verify($create)['authorized']);
        $r = $authority->read($create['target']['incarnation_id']);
        $retiring = $authority->retire($r['incarnation_id'], $r['revision']);
        $this->assertFalse($bridge->verify($create)['authorized'], 'no positive authorization caching');
        $this->denied(fn () => $authority->bind($r['incarnation_id'], $retiring['revision'], 'late-uid'));
        $cleanup = $authority->bindRetirementEvidence($r['incarnation_id'], $retiring['revision'], 'late-uid');
        $this->assertSame('retiring', $cleanup['state']); $this->assertSame('late-uid', $cleanup['uid']);
        $this->denied(fn () => $authority->authorize($create));
        [$projection, $boundAuthority] = $this->claimed('converge_projection');
        $this->assertTrue((new LifecycleClaimBridge($boundAuthority))->verify($projection)['authorized']);
        $b = $boundAuthority->read($projection['target']['incarnation_id']); $boundAuthority->retire($b['incarnation_id'], $b['revision']);
        $this->assertFalse((new LifecycleClaimBridge($boundAuthority))->verify($projection)['authorized']);
    }

    public static function actions(): array { return [['create_suspended'], ['converge_projection'], ['retire_delete']]; }

    #[DataProvider('actions')]
    public function test_each_effect_requires_current_lifecycle_and_actual_repository_lease(string $kind): void
    {
        [$a, $authority, $repository] = $this->claimed($kind); $bridge = new LifecycleClaimBridge($authority);
        $this->assertTrue($bridge->verify($a)['authorized']);
        $repository->markRunning($a['operation_id'], $a['claim']['fencing_token']);
        $this->assertTrue($bridge->verify($a)['authorized']);
        Carbon::setTestNow(now()->addSeconds(60));
        $this->assertFalse($bridge->verify($a)['authorized']);
        $this->expectException(FencingViolation::class); $repository->markRunning($a['operation_id'], $a['claim']['fencing_token']);
    }

    public function test_reclaimed_worker_and_restart_must_obtain_current_fence(): void
    {
        [$old, $authority, $repository] = $this->claimed(); $bridge = new LifecycleClaimBridge($authority);
        Carbon::setTestNow(now()->addSeconds(61)); $newClaim = $repository->claimAvailable('replacement-worker', leaseSeconds: 60)[0];
        $new = $old; $new['claim'] = ['attempt' => $newClaim->attemptCount, 'fencing_token' => $newClaim->leaseToken, 'lease_expires_at' => now()->addSeconds(60)->toIso8601ZuluString()];
        $new['deadline'] = now()->addSeconds(5)->toIso8601ZuluString(); $authority->authorize($new);
        $this->assertFalse($bridge->verify($old)['authorized']); $this->assertTrue((new LifecycleClaimBridge($authority))->verify($new)['authorized']);
        $this->assertSame(2, $new['claim']['attempt']); $this->assertNotSame($old['claim']['fencing_token'], $new['claim']['fencing_token']);
    }

    public function test_every_envelope_field_is_bound_and_no_mismatch_mutates_operation(): void
    {
        [$a, $authority] = $this->claimed('converge_projection'); $bridge = new LifecycleClaimBridge($authority);
        foreach (self::paths($a) as $path) {
            $bad = $a; $cursor = &$bad;
            foreach ($path as $key) $cursor = &$cursor[$key];
            $cursor = is_int($cursor) ? $cursor+1 : (is_string($cursor) ? $cursor.'x' : 'mismatch'); unset($cursor);
            $before = DB::table('runtime_operations')->where('id', $a['operation_id'])->first();
            $this->assertFalse($bridge->verify($bad)['authorized'], implode('.', $path));
            $this->assertEquals($before, DB::table('runtime_operations')->where('id', $a['operation_id'])->first());
        }
        $reordered = array_reverse($a, true); $reordered['target'] = array_reverse($a['target'], true);
        $this->assertTrue($bridge->verify($reordered)['authorized']);
    }

    public function test_superseded_generation_and_cancelled_repository_operation_fail_closed(): void
    {
        [$old, $authority, $repository] = $this->claimed('converge_projection'); $new = $old;
        $new['target_generation'] = '11'; $new['intent']['replicas'] = 1; $new['intent_fingerprint'] = LifecycleFingerprint::calculate($new); $authority->authorize($new);
        $this->assertFalse((new LifecycleClaimBridge($authority))->verify($old)['authorized']);
        $this->assertTrue((new LifecycleClaimBridge($authority))->verify($new)['authorized']);
        $repository->cancel($new['operation_id']); $this->assertFalse((new LifecycleClaimBridge($authority))->verify($new)['authorized']);
    }

    public function test_valid_rehashed_mismatches_are_denied_by_complete_canonical_binding(): void
    {
        [$a, $authority] = $this->claimed('converge_projection'); $bridge = new LifecycleClaimBridge($authority);
        foreach ([['tenant_id'], ['runtime_node_id'], ['target', 'cluster_id'], ['target', 'namespace'], ['target', 'expected_uid'],
            ['target', 'expected_resource_version'], ['target', 'target_id'], ['target', 'runtime_node_slug'], ['intent', 'replicas'],
            ['intent', 'projection', 'configuration_id'], ['correlation_id'], ['audit_id'], ['request_id'], ['idempotency_key'], ['target_generation']] as $path) {
            $bad = $a; $cursor = &$bad; foreach ($path as $key) $cursor = &$cursor[$key];
            $cursor = is_int($cursor) ? 1 : ($path === ['target_generation'] ? '11' : 'different'); unset($cursor);
            $bad['intent']['projection_fingerprint'] = LifecycleFingerprint::projection($bad['intent']['projection']);
            $bad['intent_fingerprint'] = LifecycleFingerprint::calculate($bad);
            $this->assertFalse($bridge->verify($bad)['authorized'], implode('.', $path));
        }
    }

    public function test_well_formed_false_lease_claims_are_denied_by_actual_operation_row(): void
    {
        [$a, $authority] = $this->claimed(); $bridge = new LifecycleClaimBridge($authority);
        foreach ([['fencing_token', str_repeat('f', 32)], ['attempt', 2], ['lease_expires_at', '2030-01-02T03:06:05Z']] as [$field, $value]) {
            $bad = $a; $bad['claim'][$field] = $value;
            $this->assertFalse($bridge->verify($bad)['authorized'], $field);
        }
        $bad = $a; $bad['deadline'] = '2030-01-02T03:04:04Z';
        $this->assertFalse($bridge->verify($bad)['authorized'], 'deadline elapsed');
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertFalse($bridge->verify($a)['authorized'], 'expired row without canonical envelope changes');
    }

    public function test_wrong_phase_unknown_incarnation_and_conflicting_same_generation_are_rejected(): void
    {
        [$a, $authority] = $this->claimed('converge_projection');
        $conflict = $a; $conflict['intent']['replicas'] = 1; $conflict['intent_fingerprint'] = LifecycleFingerprint::calculate($conflict);
        $this->denied(fn () => $authority->authorize($conflict));
        $r = $authority->read($a['target']['incarnation_id']); $retiring = $authority->retire($r['incarnation_id'], $r['revision']);
        $this->denied(fn () => $authority->authorize($a));
        $retired = $authority->completeRetirement($r['incarnation_id'], $retiring['revision'], true);
        $this->assertSame('retired', $retired['state']); $this->assertFalse((new LifecycleClaimBridge($authority))->verify($a)['authorized']);
        $unknown = $a; $unknown['target']['incarnation_id'] = str_repeat('f', 32); $unknown['target']['name'] = 'utcp-'.str_repeat('f', 32); $unknown['intent_fingerprint'] = LifecycleFingerprint::calculate($unknown);
        $this->assertFalse((new LifecycleClaimBridge($authority))->verify($unknown)['authorized']);
    }

    public function test_normative_positive_and_negative_fixtures_match_independent_php_validation(): void
    {
        $fixtures = json_decode(file_get_contents('/contracts/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['positive'] as $v) $this->assertSame($v['expected_fingerprint'], LifecycleFingerprint::calculate($v['action']), $v['name']);
        foreach ($fixtures['negative'] as $v) {
            try {
                $hash = LifecycleFingerprint::calculate($v['action']);
                $this->assertNotSame($v['action']['intent_fingerprint'], $hash, $v['name']);
            } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_generation_strings_are_lossless_and_ambiguous_inputs_are_rejected(): void
    {
        $a = self::sample(); $a['target_generation'] = '9223372036854775807';
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', LifecycleFingerprint::calculate($a));
        foreach ([1, 1.0, '01', '1e2', '0', '-1', '9223372036854775808', '18446744073709551615'] as $value) {
            $bad = $a; $bad['target_generation'] = $value; $this->denied(fn () => LifecycleFingerprint::calculate($bad));
        }
        foreach (['command', 'env', 'volumes', 'sidecars', 'service_account', 'ports', 'lifecycle_hooks'] as $field) {
            $bad = $a; $bad['intent']['projection'][$field] = 'unsafe'; $this->denied(fn () => LifecycleFingerprint::calculate($bad));
        }
    }

    public function test_exports_independent_php_vectors_and_real_repository_claim_decisions(): void
    {
        $vectors = [];
        foreach (['create_suspended', 'converge_projection', 'retire_delete'] as $kind) {
            foreach (['10', '9223372036854775807'] as $generation) {
                $a = self::sample($kind); $a['target_generation'] = $generation;
                if ($kind === 'retire_delete') $a['intent']['retirement_generation'] = $generation;
                $a['intent_fingerprint'] = LifecycleFingerprint::calculate($a);
                $vectors[] = ['name' => $kind.'-'.$generation, 'action' => $a, 'expected' => $a['intent_fingerprint']];
            }
        }
        [$a, $authority] = $this->claimed(); $bridge = new LifecycleClaimBridge($authority);
        $claims = [['name' => 'current', 'action' => $a, 'response' => $bridge->verify($a)]];
        $this->assertTrue($claims[0]['response']['authorized']);
        foreach (['tenant_id', 'runtime_node_id', 'request_id', 'correlation_id', 'audit_id', 'operation_id', 'target_generation'] as $field) {
            $bad = $a; $bad[$field] = $field === 'target_generation' ? '11' : 'other';
            $bad['intent_fingerprint'] = LifecycleFingerprint::calculate($bad);
            $claims[] = ['name' => $field, 'action' => $bad, 'response' => $bridge->verify($bad)]; $this->assertFalse(end($claims)['response']['authorized']);
        }
        $r = $authority->read($a['target']['incarnation_id']); $authority->retire($r['incarnation_id'], $r['revision']);
        $claims[] = ['name' => 'retired_intent', 'action' => $a, 'response' => $bridge->verify($a)]; $this->assertFalse(end($claims)['response']['authorized']);
        $this->assertNotFalse(file_put_contents('/output/php-lifecycle-proof.json', json_encode(['fingerprints' => $vectors, 'claims' => $claims], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n"));
        if (file_exists('/output/go-lifecycle-proof.json')) {
            $proof = json_decode(file_get_contents('/output/go-lifecycle-proof.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(count($vectors), $proof['fingerprints']);
            foreach ($proof['fingerprints'] as $v) $this->assertSame(LifecycleFingerprint::calculate($v['action']), $v['actual'], $v['name']);
        }
    }

    private function claimed(string $kind = 'create_suspended'): array
    {
        $authority = new LifecycleAuthority; $r = $authority->prepare('tenant-a', 'node-a');
        if ($kind !== 'create_suspended') $r = $authority->bind($r['incarnation_id'], $r['revision'], 'uid-a');
        if ($kind === 'retire_delete') $r = $authority->retire($r['incarnation_id'], $r['revision']);
        $a = self::sample($kind); $a['target']['incarnation_id'] = $r['incarnation_id']; $a['target']['name'] = $r['name'];
        $repository = new RuntimeOperationRepository;
        $a['operation_id'] = $repository->create('gn.g2.lifecycle.'.$kind, 'infrastructure.target', $a['target']['target_id'], ['action' => 'execute'], ExecutionContext::system(tenantId: $a['tenant_id']), runtimeNodeId: $a['runtime_node_id']);
        $claim = $repository->claimAvailable('lifecycle-worker', leaseSeconds: 60)[0];
        $a['claim'] = ['attempt' => $claim->attemptCount, 'fencing_token' => $claim->leaseToken, 'lease_expires_at' => now()->addSeconds(60)->toIso8601ZuluString()];
        $a['deadline'] = now()->addSeconds(5)->toIso8601ZuluString(); $a['intent_fingerprint'] = LifecycleFingerprint::calculate($a); $authority->authorize($a);
        return [$a, $authority, $repository];
    }

    public static function sample(string $kind = 'create_suspended'): array
    {
        $p = ['template_version' => 'inert.pause.v1', 'image' => LifecycleFingerprint::IMAGE, 'configuration_id' => 'config-a', 'configuration_sha256' => str_repeat('a', 64)];
        $intent = $kind === 'retire_delete' ? ['kind' => $kind, 'retirement_id' => 'retirement-a', 'retirement_generation' => '10', 'retirement_fingerprint' => str_repeat('b', 64)]
            : ['kind' => $kind, 'projection' => $p, 'projection_fingerprint' => LifecycleFingerprint::projection($p)];
        if ($kind === 'converge_projection') $intent['replicas'] = 0;
        $a = ['contract_version' => 'utcp.infrastructure.v2', 'request_id' => 'request-a', 'operation_id' => 'operation-a', 'tenant_id' => 'tenant-a', 'runtime_node_id' => 'node-a',
            'target' => ['cluster_id' => 'cluster-a', 'namespace' => 'proof', 'kind' => 'Deployment', 'name' => 'utcp-'.str_repeat('a', 32), 'runtime_node_slug' => 'runtime-a', 'target_id' => 'target-a', 'incarnation_id' => str_repeat('a', 32), 'expected_uid' => $kind === 'create_suspended' ? null : 'uid-a', 'expected_resource_version' => $kind === 'create_suspended' ? null : '1'],
            'target_generation' => '10', 'intent_fingerprint' => str_repeat('0', 64), 'action' => $kind, 'intent' => $intent, 'idempotency_key' => 'idempotency-a',
            'claim' => ['attempt' => 1, 'fencing_token' => str_repeat('a', 32), 'lease_expires_at' => '2030-01-02T03:05:05Z'], 'deadline' => '2030-01-02T03:04:10Z', 'correlation_id' => 'correlation-a', 'audit_id' => 'audit-a'];
        $a['intent_fingerprint'] = LifecycleFingerprint::calculate($a); return $a;
    }

    private static function paths(array $a, array $prefix = []): array { $out = []; foreach ($a as $k => $v) $out = array_merge($out, is_array($v) ? self::paths($v, [...$prefix, $k]) : [[...$prefix, $k]]); return $out; }
    private function denied(callable $f): void { try { $f(); $this->fail('Invalid lifecycle action accepted.'); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); } }
}
