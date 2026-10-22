<?php

declare(strict_types=1);

use App\ControlPlane\InfrastructureAuthority\ActiveFingerprint;
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/Fixture.php';

/** No RefreshDatabase transaction: independent workers must see committed rows. */
final class PostgresProofTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::getDriverName(), 'This proof must not fall back to SQLite.');
        self::assertSame('utcp_canonical_proof', DB::connection()->getDatabaseName());
        self::assertStringStartsWith('utcp-gn-canonical-', (string) getenv('UTCP_CANONICAL_PROOF_RUN'));
    }

    public function test_concurrent_generation_allocation_uses_independent_connections_and_real_row_lock_contention(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $workers = array_fill(0, 4, ['method' => 'nextGeneration', 'args' => [$r['id']]]);
        $results = $this->race($tenant, $node, $workers);
        self::assertCount(4, array_unique(array_column($results, 'pid')));
        self::assertSame([true, true, true, true], array_column($results, 'ok'));
        $generations = array_column($results, 'result');
        sort($generations, SORT_STRING);
        self::assertSame(['2', '3', '4', '5'], $generations);
        self::assertSame('5', (new CanonicalInfrastructureService)->read($r['id'])['generation']);
    }

    public function test_simultaneous_prepare_is_idempotent_and_different_intents_cannot_allocate_two_active_incarnations(): void
    {
        [$tenant, $node] = CanonicalPgFixture::node();
        $args = [$node, 'canonical-proof-cluster', 'canonical-proof', 'asterisk.managed.v1', CanonicalPgFixture::configuration(), 'same-request'];
        $same = $this->race($tenant, $node, [['method' => 'prepare', 'args' => $args], ['method' => 'prepare', 'args' => $args]]);
        self::assertSame([true, true], array_column($same, 'ok'));
        self::assertSame($same[0]['result']['id'], $same[1]['result']['id']);
        self::assertSame(1, DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('runtime_node_id', $node)->count());
        [$otherTenant, $otherNode] = CanonicalPgFixture::node();
        $first = [$otherNode, 'canonical-proof-cluster', 'canonical-proof', 'asterisk.managed.v1', CanonicalPgFixture::configuration(), 'first'];
        $second = $first;
        $second[5] = 'second';
        $different = $this->race($otherTenant, $otherNode, [['method' => 'prepare', 'args' => $first], ['method' => 'prepare', 'args' => $second]]);
        self::assertSame(1, count(array_filter($different, fn ($r) => $r['ok'])));
        self::assertSame(1, DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('runtime_node_id', $otherNode)->count());
    }

    public function test_conflicting_uid_binding_has_exactly_one_winner(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $result = $this->race($tenant, $node, [['method' => 'bind', 'args' => [$r['id'], 'uid-a']], ['method' => 'bind', 'args' => [$r['id'], 'uid-b']]]);
        self::assertSame(1, count(array_filter($result, fn ($r) => $r['ok'])));
        $stored = (new CanonicalInfrastructureService)->read($r['id']);
        self::assertSame('bound', $stored['state']);
        self::assertContains($stored['kubernetes_uid'], ['uid-a', 'uid-b']);
        $denied = $this->worker($tenant, 'bind', [$r['id'], $stored['kubernetes_uid'] === 'uid-a' ? 'uid-b' : 'uid-a']);
        self::assertFalse($denied['ok']);
    }

    public function test_rollback_does_not_leak_generation_or_audit_outbox_success(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $beforeAudit = DB::table('control_plane_audit_records')->count();
        $beforeOutbox = DB::table('control_plane_outbox_messages')->count();
        DB::beginTransaction();
        self::assertSame('2', $service->nextGeneration(ExecutionContext::system(tenantId: $tenant), $r['id']));
        DB::rollBack();
        self::assertSame('1', $service->read($r['id'])['generation']);
        self::assertSame($beforeAudit, DB::table('control_plane_audit_records')->count());
        self::assertSame($beforeOutbox, DB::table('control_plane_outbox_messages')->count());
        self::assertSame('2', $this->worker($tenant, 'nextGeneration', [$r['id']])['result']);
    }

    public function test_lossless_large_generation_and_database_boundary_enforcement(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->update(['generation' => '9007199254740992']);
        self::assertSame('9007199254740993', $this->worker($tenant, 'nextGeneration', [$r['id']])['result']);
        DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->update(['generation' => '9223372036854775807']);
        self::assertFalse($this->worker($tenant, 'nextGeneration', [$r['id']])['ok']);
        self::assertSame('9223372036854775807', (new CanonicalInfrastructureService)->read($r['id'])['generation']);
        DB::beginTransaction();
        try {
            DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->update(['generation' => '9223372036854775808']);
            self::fail('PostgreSQL must reject a generation outside signed BIGINT storage.');
        } catch (QueryException $e) {
            self::assertSame('22003', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_retirement_races_generation_and_current_claim_verification_without_reattivating(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $r = $service->bind(ExecutionContext::system(tenantId: $tenant), $r['id'], 'uid-race');
        $a = CanonicalPgFixture::action($r);
        $service->persistIntent(ExecutionContext::system(tenantId: $tenant), $r['id'], $a);
        self::assertTrue($service->verify($a)['authorized']);
        $result = $this->race($tenant, $node, [['method' => 'retire', 'args' => [$r['id']]], ['method' => 'verify', 'args' => [$a]], ['method' => 'nextGeneration', 'args' => [$r['id']]]]);
        self::assertTrue($result[0]['ok']);
        self::assertSame('retiring', $service->read($r['id'])['state']);
        self::assertFalse($service->verify($a)['authorized']);
        self::assertFalse($this->worker($tenant, 'nextGeneration', [$r['id']])['ok']);
        // A verifier ordered before retirement may have answered true; a committed retirement prevents subsequent authority.
    }

    public function test_uncertain_effect_survives_killed_worker_and_new_application_process_and_requires_observation(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $context = ExecutionContext::system(tenantId: $tenant);
        $r = $service->bind($context, $r['id'], 'uid-restart');
        $a = CanonicalPgFixture::action($r);
        $service->persistIntent($context, $r['id'], $a);
        $committed = '/output/worker-'.bin2hex(random_bytes(8)).'.committed';
        $worker = $this->start(['tenant' => $tenant, 'method' => 'unknown_and_wait', 'args' => [$r['id'], $r['generation'], $a['action']], 'committed' => $committed]);
        $this->await(fn () => is_file($committed), 'Worker never committed the uncertain effect.');
        $worker->stop(0, SIGKILL);
        unlink($committed);
        $restarted = $this->worker($tenant, 'unresolved', []);
        self::assertTrue($restarted['ok']);
        self::assertCount(1, $restarted['result']);
        self::assertSame('outcome_unknown', $restarted['result'][0]['outcome']);
        self::assertSame($a['operation_id'], $restarted['result'][0]['operation_id']);
        self::assertFalse($this->worker($tenant, 'verify', [$a])['result']['authorized']);
        $service->retire($context, $r['id']);
        self::assertCount(1, $service->unresolved($tenant), 'Supersession must not erase uncertain history.');
    }

    public function test_terminal_retirement_keeps_physical_name_history_and_prevents_uid_rebinding(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $context = ExecutionContext::system(tenantId: $tenant);
        $service->bind($context, $r['id'], 'uid-terminal');
        $service->retire($context, $r['id']);
        $retired = $service->completeRetirement($context, $r['id'], true);
        self::assertSame('retired', $retired['state']);
        self::assertFalse($this->worker($tenant, 'bind', [$r['id'], 'replacement-uid'])['ok']);
        $new = $service->prepare($context, $node, 'canonical-proof-cluster', 'canonical-proof', 'asterisk.managed.v1', CanonicalPgFixture::configuration(), 'replacement');
        self::assertNotSame($r['physical_name'], $new['physical_name']);
        self::assertSame($r['physical_name'], $service->read($r['id'])['physical_name']);
    }

    public function test_postgresql_constraints_reject_direct_identity_replacement_history_deletion_and_name_reuse(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $context = ExecutionContext::system(tenantId: $tenant);
        $r = $service->bind($context, $r['id'], 'uid-db-guard');
        foreach ([['kubernetes_uid' => 'replacement'], ['physical_name' => 'reused-name'], ['tenant_id' => (string) Str::uuid()]] as $change) {
            $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->update($change), ['P0001']);
        }
        $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->delete(), ['P0001']);
        $copy = (array) DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->first();
        $copy['id'] = bin2hex(random_bytes(16));
        $copy['physical_name'] = 'utcp-'.$copy['id'];
        $copy['state'] = 'preparing';
        $copy['kubernetes_uid'] = null;
        $copy['prepare_key'] = bin2hex(random_bytes(16));
        $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->insert($copy), ['23505']);
        [$otherTenant] = CanonicalPgFixture::node();
        $copy['tenant_id'] = $otherTenant;
        $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->insert($copy), ['P0001']);
        $service->retire($context, $r['id']);
        $service->completeRetirement($context, $r['id'], true);
        $copy['tenant_id'] = $tenant;
        // A physical name is derived from its immutable ID. Retain both to test
        // attempted reuse of the terminal identity without violating that check.
        $copy['id'] = $r['id'];
        $copy['physical_name'] = $r['physical_name'];
        $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->insert($copy), ['23505']);
        $this->assertPgRejected(fn () => DB::table(CanonicalInfrastructureService::INCARNATIONS)->where('id', $r['id'])->update(['state' => 'bound']), ['P0001']);
        self::assertSame('retired', $service->read($r['id'])['state']);
        self::assertSame('uid-db-guard', $service->read($r['id'])['kubernetes_uid']);
    }

    public function test_generation_supersession_races_claim_verification_and_denies_all_later_verifications(): void
    {
        [$tenant, $node, $r] = CanonicalPgFixture::prepared();
        $service = new CanonicalInfrastructureService;
        $context = ExecutionContext::system(tenantId: $tenant);
        $r = $service->bind($context, $r['id'], 'uid-supersession');
        $a = CanonicalPgFixture::action($r);
        $service->persistIntent($context, $r['id'], $a);
        self::assertTrue($service->verify($a)['authorized']);
        $race = $this->race($tenant, $node, [['method' => 'nextGeneration', 'args' => [$r['id']]], ['method' => 'verify', 'args' => [$a]]]);
        self::assertTrue($race[0]['ok']);
        self::assertSame('2', $race[0]['result']);
        self::assertFalse($this->worker($tenant, 'verify', [$a])['result']['authorized']);
        self::assertTrue((bool) DB::table(CanonicalInfrastructureService::INTENTS)->where('operation_id', $a['operation_id'])->value('superseded'));
    }

    private function assertPgRejected(callable $write, array $states): void
    {
        DB::beginTransaction();
        try {
            $write();
            self::fail('PostgreSQL must reject a direct canonical invariant violation.');
        } catch (QueryException $e) {
            self::assertContains($e->errorInfo[0], $states);
        } finally {
            DB::rollBack();
        }
    }

    public function test_exports_live_database_backed_claims_for_independent_go_mtls_verifiers(): void
    {
        $service = new CanonicalInfrastructureService;
        $proof = ['claims' => [], 'fingerprints' => [], 'postgresql' => (string) DB::selectOne('show server_version')->server_version];
        foreach (['admit_generation', 'set_replicas', 'create_suspended', 'converge_projection', 'retire_delete'] as $kind) {
            [$tenant, $node, $r] = CanonicalPgFixture::prepared();
            $context = ExecutionContext::system(tenantId: $tenant);
            $version = match ($kind) {
                'admit_generation', 'set_replicas' => 'utcp.infrastructure.v1', 'converge_projection' => 'utcp.infrastructure.v3', default => 'utcp.infrastructure.v2'
            };
            if ($kind !== 'create_suspended') {
                $r = $service->bind($context, $r['id'], 'uid-export');
            }
            if ($kind === 'retire_delete') {
                $r = $service->retire($context, $r['id']);
            }
            $a = CanonicalPgFixture::action($r, $version, $kind);
            $service->persistIntent($context, $r['id'], $a);
            $response = $service->verify($a);
            self::assertTrue($response['authorized']);
            $proof['claims'][] = ['name' => $kind.'-current', 'action' => $a, 'response' => $response];
            $proof['fingerprints'][] = ['name' => $kind, 'action' => $a, 'expected' => ActiveFingerprint::versioned($a)];
            $bad = $a;
            $bad['claim']['fencing_token'] = str_repeat('0', 32);
            $proof['claims'][] = ['name' => $kind.'-wrong-claim', 'action' => $bad, 'response' => $service->verify($bad)];
        }
        file_put_contents('/output/canonical-proof.json', json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        self::assertFileExists('/output/canonical-proof.json');
    }

    private function race(string $tenant, string $node, array $commands): array
    {
        DB::beginTransaction();
        DB::table('runtime_nodes')->where('id', $node)->lockForUpdate()->first();
        $workers = [];
        $paths = [];
        try {
            foreach ($commands as $command) {
                $path = '/output/worker-'.bin2hex(random_bytes(8));
                $paths[] = $path;
                $workers[] = $this->start($command + ['tenant' => $tenant, 'barrier' => $path]);
            }
            foreach ($paths as $path) {
                $this->await(fn () => is_file($path.'.ready'), 'Concurrent worker did not reach its synchronization barrier.');
            }
            foreach ($paths as $path) {
                touch($path.'.release');
            }
            $this->await(function () use ($workers): bool {
                // Statistics are cached within a transaction unless refreshed.
                DB::select('SELECT pg_stat_clear_snapshot()');

                return DB::table('pg_stat_activity')->where('application_name', 'canonical-proof-worker')->where('wait_event_type', 'Lock')->count() >= count($workers);
            }, 'Independent PostgreSQL workers did not contend on the held canonical row lock.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0, SIGKILL);
                }
            }
            foreach ($paths as $path) {
                foreach (['.ready', '.release'] as $suffix) {
                    if (is_file($path.$suffix)) {
                        unlink($path.$suffix);
                    }
                }
            }
        }
    }

    private function worker(string $tenant, string $method, array $args): array
    {
        $process = $this->start(compact('tenant', 'method', 'args'));
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function start(array $input): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php'], '/var/www/html', null, json_encode($input, JSON_THROW_ON_ERROR), 30);
        $process->start();

        return $process;
    }

    private function await(callable $condition, string $failure): void
    {
        $end = microtime(true) + 15;
        do {
            if ($condition()) {
                return;
            } usleep(10000);
        } while (microtime(true) < $end);
        self::fail($failure);
    }
}
