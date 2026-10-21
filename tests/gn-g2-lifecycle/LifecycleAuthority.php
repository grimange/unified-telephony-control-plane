<?php

declare(strict_types=1);

namespace Utcp\LifecycleProof;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Test-only canonical authority model. In-memory CAS is not PostgreSQL concurrency proof. */
final class LifecycleAuthority
{
    private array $records = [];
    private array $names = [];

    public function prepare(string $tenant, string $node): array
    {
        do { $incarnation = bin2hex(random_bytes(16)); $name = 'utcp-'.$incarnation; } while (isset($this->names[$name]));
        $this->names[$name] = true; // Tombstone retained after retirement.
        return $this->records[$incarnation] = ['incarnation_id' => $incarnation, 'name' => $name,
            'tenant_id' => $tenant, 'runtime_node_id' => $node, 'state' => 'preparing',
            'uid' => null, 'revision' => 1, 'generation' => '1', 'canonical' => null];
    }

    public function read(string $incarnation): array
    {
        return $this->records[$incarnation] ?? throw new InvalidArgumentException('Unknown incarnation.');
    }

    public function allocateGeneration(string $incarnation, int $revision): array
    {
        $r = $this->read($incarnation);
        if (! in_array($r['state'], ['preparing', 'bound'], true) || $r['revision'] !== $revision) self::deny();
        $r['generation'] = self::next($r['generation']); $r['revision']++; $r['canonical'] = null;
        return $this->records[$incarnation] = $r;
    }

    public function bind(string $incarnation, int $revision, string $uid): array
    {
        $r = $this->read($incarnation);
        if ($r['state'] === 'bound' && $r['uid'] === $uid) return $r;
        if ($uid === '' || $r['state'] !== 'preparing' || $r['uid'] !== null || $r['revision'] !== $revision) self::deny();
        $r['uid'] = $uid; $r['state'] = 'bound'; $r['revision']++; $r['canonical'] = null;
        return $this->records[$incarnation] = $r;
    }

    public function retire(string $incarnation, int $revision): array
    {
        $r = $this->read($incarnation);
        if (! in_array($r['state'], ['preparing', 'bound'], true) || $r['revision'] !== $revision) self::deny();
        $r['state'] = 'retiring'; $r['revision']++; $r['generation'] = self::next($r['generation']); $r['canonical'] = null;
        return $this->records[$incarnation] = $r;
    }

    /** Delayed creation is observed, then UID may bind for cleanup only, never activation. */
    public function bindRetirementEvidence(string $incarnation, int $revision, string $uid): array
    {
        $r = $this->read($incarnation);
        if ($r['state'] !== 'retiring' || $r['revision'] !== $revision || $r['uid'] !== null || $uid === '') self::deny();
        $r['uid'] = $uid; $r['revision']++;
        return $this->records[$incarnation] = $r;
    }

    public function completeRetirement(string $incarnation, int $revision, bool $exactResourceAbsent): array
    {
        $r = $this->read($incarnation);
        if ($r['state'] !== 'retiring' || $r['revision'] !== $revision || ! $exactResourceAbsent) self::deny();
        $r['state'] = 'retired'; $r['revision']++; $r['canonical'] = null;
        return $this->records[$incarnation] = $r;
    }

    public function authorize(array $a): void
    {
        LifecycleFingerprint::validate($a);
        if (! hash_equals(LifecycleFingerprint::calculate($a), $a['intent_fingerprint'])) self::deny();
        $r = $this->read($a['target']['incarnation_id']);
        $expected = match ($a['action']) { 'create_suspended' => 'preparing', 'converge_projection' => 'bound', 'retire_delete' => 'retiring' };
        if ($r['state'] !== $expected || $r['name'] !== $a['target']['name'] || $r['tenant_id'] !== $a['tenant_id']
            || $r['runtime_node_id'] !== $a['runtime_node_id'] || $r['uid'] !== $a['target']['expected_uid']
            || self::compare($a['target_generation'], $r['generation']) < 0) self::deny();
        if ($r['canonical'] !== null && self::compare($a['target_generation'], $r['generation']) === 0
            && $r['canonical']['intent_fingerprint'] !== $a['intent_fingerprint']) self::deny();
        $r['generation'] = $a['target_generation']; $r['canonical'] = $a;
        $this->records[$r['incarnation_id']] = $r;
    }

    public function current(array $a): bool
    {
        $r = $this->read($a['target']['incarnation_id']);
        return $r['canonical'] !== null && self::normalized($r['canonical']) === self::normalized($a)
            && $r['state'] === match ($a['action']) { 'create_suspended' => 'preparing', 'converge_projection' => 'bound', 'retire_delete' => 'retiring', default => '' };
    }

    public static function normalized(array $value): array
    {
        ksort($value); foreach ($value as &$v) if (is_array($v)) $v = self::normalized($v); return $value;
    }

    private static function next(string $n): string
    {
        if ($n === '9223372036854775807') self::deny();
        for ($i = strlen($n)-1; $i >= 0; $i--) { if ($n[$i] !== '9') { $n[$i] = chr(ord($n[$i])+1); return $n; } $n[$i] = '0'; }
        return '1'.$n;
    }

    private static function compare(string $a, string $b): int { return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
    private static function deny(): never { throw new InvalidArgumentException('Canonical lifecycle transition denied.'); }
}

/** Independent PHP implementation of the versioned byte-length-prefixed hashes. */
final class LifecycleFingerprint
{
    public const IMAGE = 'registry.k8s.io/pause@sha256:ee6521f290b2168b6e0935a181d4cff9be1ac3f505666ef0e3c98fae8199917a';

    public static function projection(array $p): string
    {
        self::keys($p, ['template_version', 'image', 'configuration_id', 'configuration_sha256']);
        if ($p['template_version'] !== 'inert.pause.v1' || $p['image'] !== self::IMAGE) self::invalid();
        self::id($p['configuration_id']); self::hash($p['configuration_sha256']);
        return self::digest([$p['template_version'], $p['image'], $p['configuration_id'], $p['configuration_sha256']]);
    }

    public static function calculate(array $a): string
    {
        self::validate($a); $t = $a['target']; $i = $a['intent'];
        return self::digest([$a['contract_version'], $a['tenant_id'], $a['runtime_node_id'], $t['cluster_id'], $t['namespace'], $t['kind'],
            $t['name'], $t['runtime_node_slug'], $t['target_id'], $t['incarnation_id'], $t['expected_uid'] ?? '', $a['target_generation'],
            $i['kind'], $i['projection_fingerprint'] ?? '', isset($i['replicas']) ? (string) $i['replicas'] : '',
            $i['retirement_id'] ?? '', $i['retirement_generation'] ?? '', $i['retirement_fingerprint'] ?? '']);
    }

    public static function validate(array $a): void
    {
        self::keys($a, ['contract_version', 'request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'target', 'target_generation',
            'intent_fingerprint', 'action', 'intent', 'idempotency_key', 'claim', 'deadline', 'correlation_id', 'audit_id']);
        if ($a['contract_version'] !== 'utcp.infrastructure.v2' || ! in_array($a['action'], ['create_suspended', 'converge_projection', 'retire_delete'], true)
            || ! is_array($a['target']) || ! is_array($a['intent']) || ! is_array($a['claim'])) self::invalid();
        foreach (['request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'idempotency_key', 'correlation_id', 'audit_id'] as $k) self::id($a[$k]);
        self::generation($a['target_generation']); self::hash($a['intent_fingerprint']);
        $t = $a['target']; self::keys($t, ['cluster_id', 'namespace', 'kind', 'name', 'runtime_node_slug', 'target_id', 'incarnation_id', 'expected_uid', 'expected_resource_version']);
        foreach (['cluster_id', 'target_id'] as $k) self::id($t[$k]);
        foreach (['namespace', 'name', 'runtime_node_slug'] as $k) if (! is_string($t[$k]) || strlen($t[$k]) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $t[$k])) self::invalid();
        if ($t['kind'] !== 'Deployment' || ! is_string($t['incarnation_id']) || ! preg_match('/\A[a-f0-9]{32}\z/D', $t['incarnation_id']) || $t['name'] !== 'utcp-'.$t['incarnation_id']) self::invalid();
        foreach (['expected_uid', 'expected_resource_version'] as $k) {
            if ($a['action'] === 'create_suspended') { if ($t[$k] !== null) self::invalid(); }
            elseif (! is_string($t[$k]) || $t[$k] === '' || strlen($t[$k]) > ($k === 'expected_uid' ? 128 : 256) || strpbrk($t[$k], "\r\n") !== false) self::invalid();
        }
        $i = $a['intent'];
        if ($a['action'] === 'retire_delete') {
            self::keys($i, ['kind', 'retirement_id', 'retirement_generation', 'retirement_fingerprint']);
            self::id($i['retirement_id']); self::generation($i['retirement_generation']); self::hash($i['retirement_fingerprint']);
            if ($i['retirement_generation'] !== $a['target_generation']) self::invalid();
        } else {
            self::keys($i, $a['action'] === 'create_suspended' ? ['kind', 'projection', 'projection_fingerprint'] : ['kind', 'projection', 'projection_fingerprint', 'replicas']);
            if (! is_array($i['projection']) || ! is_string($i['projection_fingerprint']) || ! hash_equals(self::projection($i['projection']), $i['projection_fingerprint'])) self::invalid();
            if ($a['action'] === 'converge_projection' && (! is_int($i['replicas']) || $i['replicas'] < 0 || $i['replicas'] > 100)) self::invalid();
        }
        if ($i['kind'] !== $a['action']) self::invalid();
        self::keys($a['claim'], ['attempt', 'fencing_token', 'lease_expires_at']);
        if (! is_int($a['claim']['attempt']) || $a['claim']['attempt'] < 1 || $a['claim']['attempt'] > 2147483647 || ! is_string($a['claim']['fencing_token']) || ! preg_match('/\A[0-9a-f]{32}\z/D', $a['claim']['fencing_token'])) self::invalid();
        foreach ([$a['deadline'], $a['claim']['lease_expires_at']] as $date) {
            if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $date)) self::invalid();
            new DateTimeImmutable($date); $e = DateTimeImmutable::getLastErrors(); if ($e !== false && ($e['warning_count'] || $e['error_count'])) self::invalid();
        }
    }

    private static function digest(array $fields): string { $s = ''; foreach ($fields as $v) $s .= strlen($v).':'.$v; return hash('sha256', $s); }
    private static function keys(array $a, array $keys): void { if (array_diff(array_keys($a), $keys) || array_diff($keys, array_keys($a))) self::invalid(); }
    private static function id(mixed $v): void { if (! is_string($v) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/D', $v)) self::invalid(); }
    private static function hash(mixed $v): void { if (! is_string($v) || ! preg_match('/\A[0-9a-f]{64}\z/D', $v)) self::invalid(); }
    private static function generation(mixed $v): void { if (! is_string($v) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $v) || strlen($v) === 19 && strcmp($v, '9223372036854775807') > 0) self::invalid(); }
    private static function invalid(): never { throw new InvalidArgumentException('Invalid Lifecycle V2 intent.'); }
}

/** Test-only callback: actual Laravel lease row plus complete canonical envelope. No caching. */
final class LifecycleClaimBridge
{
    public function __construct(private readonly LifecycleAuthority $authority) {}

    public function verify(array $a): array
    {
        $response = ['contract_version' => 'utcp.infrastructure.v2', 'authorized' => false];
        try {
            LifecycleFingerprint::validate($a);
            if (! hash_equals(LifecycleFingerprint::calculate($a), $a['intent_fingerprint'])) return $response;
            $response['authorized'] = DB::transaction(function () use ($a): bool {
                $r = DB::table('runtime_operations')->where('id', $a['operation_id'])->lockForUpdate()->first();
                return $r !== null && in_array($r->status, ['leased', 'running'], true)
                    && $r->lease_token === $a['claim']['fencing_token'] && (int) $r->attempt_count === $a['claim']['attempt']
                    && $r->tenant_id === $a['tenant_id'] && $r->runtime_node_id === $a['runtime_node_id']
                    && $r->lease_expires_at !== null && new DateTimeImmutable($r->lease_expires_at) > now()
                    && new DateTimeImmutable($r->lease_expires_at) == new DateTimeImmutable($a['claim']['lease_expires_at'])
                    && new DateTimeImmutable($a['deadline']) > now() && $this->authority->current($a);
            });
        } catch (\Throwable) { /* Fail closed without leaking the submitted envelope. */ }
        return $response;
    }
}
