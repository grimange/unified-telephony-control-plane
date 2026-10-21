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
use Utcp\GnG2Proof\ActionFingerprint;
use Utcp\GnG2Proof\ClaimVerificationBridge;
use Utcp\GnG2Proof\ResultCompatibilityBridge;

require_once __DIR__.'/ClaimVerificationBridge.php';
require_once __DIR__.'/ResultCompatibilityBridge.php';

final class ConditionalEffectParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-02T03:04:05Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function fingerprintCases(): array
    {
        return array_map(static fn (array $v): array => [$v], self::fixture('fingerprints.json'));
    }

    #[DataProvider('fingerprintCases')]
    public function test_independent_php_fingerprint_matches_permanent_vectors(array $vector): void
    {
        $this->assertSame($vector['expected'], ActionFingerprint::calculate($vector['action']), $vector['name']);
    }

    public static function invalidCases(): array
    {
        return array_map(static fn (array $v): array => [$v], self::fixture('invalid-fingerprints.json'));
    }

    #[DataProvider('invalidCases')]
    public function test_malformed_or_ambiguous_intent_is_rejected(array $vector): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActionFingerprint::calculate($vector['action']);
    }

    public function test_every_v1_field_is_required_and_optional_fields_are_not_invented(): void
    {
        $action = self::fixture('fingerprints.json')[0]['action'];
        foreach (['top' => array_keys($action), 'target' => array_keys($action['target']), 'claim' => array_keys($action['claim'])] as $group => $keys) {
            foreach ($keys as $key) {
                $bad = $action;
                if ($group === 'top') unset($bad[$key]); else unset($bad[$group][$key]);
                try { ActionFingerprint::calculate($bad); $this->fail('Missing '.$group.'.'.$key.' accepted.'); }
                catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
        }
    }

    public static function deniedCases(): array
    {
        return array_map(static fn (string $v): array => [$v], ['wrong_token', 'wrong_attempt', 'expired', 'expiry_spoof', 'wrong_operation', 'tenant', 'runtime_node', 'cluster', 'namespace', 'name', 'uid', 'incarnation', 'target_id', 'slug', 'rv', 'replicas', 'generation', 'fingerprint', 'action', 'idempotency', 'correlation', 'audit', 'deadline', 'lifecycle_hold', 'terminal', 'canonical_superseded']);
    }

    #[DataProvider('deniedCases')]
    public function test_complete_claim_binding_denies_mismatch(string $case): void
    {
        [$canonical, $repository] = $this->claimed();
        $action = $this->mutate($canonical, $case);
        $bridge = new ClaimVerificationBridge($canonical, $case !== 'lifecycle_hold');
        if ($case === 'expired') DB::table('runtime_operations')->where('id', $canonical['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
        if ($case === 'terminal') $repository->cancel($canonical['operation_id']);
        if ($case === 'canonical_superseded') {
            $canonical['target_generation'] = '11';
            $canonical['intent_fingerprint'] = ActionFingerprint::calculate($canonical);
            $bridge = new ClaimVerificationBridge($canonical);
        }
        $before = DB::table('runtime_operations')->where('id', $canonical['operation_id'])->first();
        $this->assertSame(['contract_version' => 'utcp.infrastructure.v1', 'authorized' => false], $bridge->verify($action), $case);
        $this->assertEquals($before, DB::table('runtime_operations')->where('id', $canonical['operation_id'])->first(), 'verification has no operation transition');
    }

    public function test_json_object_order_does_not_change_canonical_binding(): void
    {
        [$action] = $this->claimed();
        $reordered = $action;
        $reordered['target'] = array_reverse($action['target'], true);
        $this->assertSame(ActionFingerprint::calculate($action), ActionFingerprint::calculate($reordered));
        $this->assertTrue((new ClaimVerificationBridge($action))->verify($reordered)['authorized']);
        $reordered['target']['expected_uid'] = '01';
        $canonical = $action;
        $canonical['target']['expected_uid'] = '1';
        $canonical['intent_fingerprint'] = ActionFingerprint::calculate($canonical);
        $reordered['intent_fingerprint'] = ActionFingerprint::calculate($reordered);
        $this->assertFalse((new ClaimVerificationBridge($canonical))->verify($reordered)['authorized'], 'opaque identities are not numerically coerced');
    }

    public function test_real_repository_claim_reclaim_and_fence_reject_old_worker(): void
    {
        [$first, $repository] = $this->claimed();
        $bridge = new ClaimVerificationBridge($first);
        $this->assertTrue($bridge->verify($first)['authorized']);
        $repository->markRunning($first['operation_id'], $first['claim']['fencing_token']);
        $this->assertTrue($bridge->verify($first)['authorized'], 'running current claim remains current');
        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertFalse($bridge->verify($first)['authorized']);
        $second = $repository->claimAvailable('gn-g2-worker-b', leaseSeconds: 60)[0];
        $this->assertSame(2, $second->attemptCount);
        $this->assertNotSame($first['claim']['fencing_token'], $second->leaseToken);
        $retry = $first;
        $retry['claim'] = ['attempt' => 2, 'fencing_token' => $second->leaseToken, 'lease_expires_at' => now()->addSeconds(60)->toIso8601ZuluString()];
        $retry['deadline'] = now()->addSeconds(5)->toIso8601ZuluString();
        $this->assertTrue((new ClaimVerificationBridge($retry))->verify($retry)['authorized']);
        $this->assertFalse((new ClaimVerificationBridge($retry))->verify($first)['authorized']);
        $this->expectException(FencingViolation::class);
        $repository->markRunning($first['operation_id'], $first['claim']['fencing_token']);
    }

    public function test_expired_repository_fence_rejects_even_without_reclaim(): void
    {
        [$action, $repository] = $this->claimed();
        Carbon::setTestNow(now()->addSeconds(60));
        $this->assertFalse((new ClaimVerificationBridge($action))->verify($action)['authorized']);
        $this->expectException(FencingViolation::class);
        $repository->markRunning($action['operation_id'], $action['claim']['fencing_token']);
    }

    public function test_unknown_outcome_keeps_uncertainty_after_legacy_exception_projection(): void
    {
        $result = self::contract('fixtures/result-outcome_unknown.json');
        $projected = ResultCompatibilityBridge::project($result);
        $this->assertSame('unavailable_to_control', $projected['php_exception_reason']);
        $this->assertSame('outcome_unknown', $projected['normalized']['outcome']);
        $this->assertSame('unknown', $projected['normalized']['effect']);
        $this->assertSame('unknown', $projected['normalized']['dispatch']);
        $this->assertSame('observe_first', $projected['normalized']['retry']);
        $this->assertSame($result, $projected['normalized']);
        $this->assertSame('failed', ResultCompatibilityBridge::project(['outcome' => 'unrecognized'])['php_exception_reason']);
    }

    public function test_exports_actual_php_claim_decisions_and_independent_hashes_for_go(): void
    {
        [$action] = $this->claimed();
        $claims = [['name' => 'current', 'action' => $action, 'response' => (new ClaimVerificationBridge($action))->verify($action)]];
        $this->assertTrue($claims[0]['response']['authorized']);
        foreach (['wrong_token', 'wrong_attempt', 'expiry_spoof', 'tenant', 'runtime_node', 'namespace', 'uid', 'incarnation', 'rv', 'generation', 'replicas', 'deadline'] as $case) {
            $bad = $this->mutate($action, $case);
            $response = (new ClaimVerificationBridge($action))->verify($bad);
            $this->assertFalse($response['authorized'], $case);
            $claims[] = ['name' => $case, 'action' => $bad, 'response' => $response];
        }
        DB::table('runtime_operations')->where('id', $action['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $expired = (new ClaimVerificationBridge($action))->verify($action);
        $this->assertFalse($expired['authorized']);
        $claims[] = ['name' => 'expired', 'action' => $action, 'response' => $expired];
        $errors = [];
        foreach (self::contract('errors.json')['php_exception_reasons'] as $code => $expected) {
            $result = self::contract('fixtures/result-'.$code.'.json');
            $projection = ResultCompatibilityBridge::project($result);
            $this->assertSame($expected, $projection['php_exception_reason'], $code);
            $this->assertSame($result, $projection['normalized'], 'retain full normalized result');
            $errors[] = ['code' => $code, 'php_reason' => $expected, 'dispatch' => $result['dispatch'], 'effect' => $result['effect'], 'retry' => $result['retry']];
        }
        $vectors = self::fixture('fingerprints.json');
        foreach ($vectors as &$vector) {
            $this->assertSame($vector['expected'], ActionFingerprint::calculate($vector['action']));
        }
        unset($vector);
        $encoded = json_encode(['fingerprints' => $vectors, 'claims' => $claims, 'error_parity' => $errors], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        $this->assertNotFalse(file_put_contents('/output/php-proof.json', $encoded));
    }

    public function test_actual_go_fingerprints_and_results_match_php_when_export_is_present(): void
    {
        if (! file_exists('/output/go-proof.json')) {
            $this->markTestSkipped('First pass exports PHP decisions; verifier reruns after Go reverse export.');
        }
        $proof = json_decode(file_get_contents('/output/go-proof.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(count(self::fixture('fingerprints.json')), $proof['fingerprints']);
        foreach ($proof['fingerprints'] as $vector) {
            $this->assertSame(ActionFingerprint::calculate($vector['action']), $vector['actual'], $vector['name']);
        }
        foreach ($proof['results'] ?? [] as $result) {
            $expected = self::contract('errors.json')['php_exception_reasons'][$result['outcome']];
            $projection = ResultCompatibilityBridge::project($result);
            $this->assertSame($expected, $projection['php_exception_reason']);
            $this->assertSame($result, $projection['normalized']);
        }
    }

    private function claimed(): array
    {
        $a = self::fixture('fingerprints.json')[0]['action'];
        $repository = new RuntimeOperationRepository;
        $id = $repository->create('gn.g2.test.effect', 'infrastructure.target', $a['target']['target_id'], ['action' => 'execute'], ExecutionContext::system(tenantId: $a['tenant_id']), runtimeNodeId: $a['runtime_node_id']);
        $claim = $repository->claimAvailable('gn-g2-worker-a', leaseSeconds: 60)[0];
        $a['operation_id'] = $id;
        $a['claim'] = ['attempt' => $claim->attemptCount, 'fencing_token' => $claim->leaseToken, 'lease_expires_at' => now()->addSeconds(60)->toIso8601ZuluString()];
        $a['deadline'] = now()->addSeconds(5)->toIso8601ZuluString();
        return [$a, $repository];
    }

    private function mutate(array $a, string $case): array
    {
        match ($case) {
            'wrong_token' => $a['claim']['fencing_token'] = str_repeat('f', 32),
            'wrong_attempt' => $a['claim']['attempt'] = 2,
            'expiry_spoof' => $a['claim']['lease_expires_at'] = '2030-01-02T03:06:05Z',
            'wrong_operation' => $a['operation_id'] = 'other-operation',
            'tenant' => $a['tenant_id'] = 'other-tenant',
            'runtime_node' => $a['runtime_node_id'] = 'other-node',
            'cluster' => $a['target']['cluster_id'] = 'other-cluster',
            'namespace' => $a['target']['namespace'] = 'other-namespace',
            'name' => $a['target']['name'] = 'other-name',
            'uid' => $a['target']['expected_uid'] = 'other-uid',
            'incarnation' => $a['target']['incarnation_id'] = 'other-incarnation',
            'target_id' => $a['target']['target_id'] = 'other-target',
            'slug' => $a['target']['runtime_node_slug'] = 'other-slug',
            'rv' => $a['target']['expected_resource_version'] = 'other-rv',
            'replicas' => $a['replicas'] = 1,
            'generation' => $a['target_generation'] = '11',
            'action' => $a['action'] = 'admit_generation',
            'idempotency' => $a['idempotency_key'] = 'other-idempotency',
            'correlation' => $a['correlation_id'] = 'other-correlation',
            'audit' => $a['audit_id'] = 'other-audit',
            'deadline' => $a['deadline'] = '2030-01-02T03:04:04Z',
            default => null,
        };
        $a['intent_fingerprint'] = $case === 'fingerprint' ? str_repeat('0', 64) : ActionFingerprint::calculate($a);
        return $a;
    }

    private static function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function contract(string $name): array
    {
        return json_decode(file_get_contents('/contracts/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }
}
