<?php

declare(strict_types=1);

namespace Utcp\ActiveProof;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Utcp\LifecycleProof\LifecycleAuthority;
use Utcp\LifecycleProof\LifecycleFingerprint;

require_once __DIR__.'/../gn-g2-lifecycle/LifecycleAuthority.php';
require_once __DIR__.'/../gn-g2-effects/ClaimVerificationBridge.php';
require_once __DIR__.'/../gn-g2-effects/ResultCompatibilityBridge.php';

/** Independent PHP v3 fixed-scalar fingerprint. No generated Go hashes are read. */
final class ActiveFingerprint
{
    public static function projection(array $p): string
    {
        $keys = ['template_version', 'image', 'configuration_id', 'configuration_sha256', 'secret_name'];
        if (array_diff(array_keys($p), $keys) || array_diff($keys, array_keys($p))) self::deny();
        if (! in_array($p['template_version'], ['asterisk.managed.v1', 'freeswitch.managed.v1'], true)
            || ! is_string($p['image']) || ! preg_match('/\A[a-z0-9][a-z0-9.:-]*\/[a-z0-9][a-z0-9._\/-]*@sha256:[0-9a-f]{64}\z/D', $p['image'])
            || ! is_string($p['configuration_id']) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/D', $p['configuration_id'])
            || ! is_string($p['configuration_sha256']) || ! preg_match('/\A[0-9a-f]{64}\z/D', $p['configuration_sha256'])
            || ! is_string($p['secret_name']) || strlen($p['secret_name']) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $p['secret_name'])) self::deny();
        return self::digest(array_map(fn ($k) => $p[$k], $keys));
    }

    public static function calculate(array $a): string
    {
        self::validate($a); $t = $a['target']; $i = $a['intent'];
        return self::digest([$a['contract_version'], $a['tenant_id'], $a['runtime_node_id'], $t['cluster_id'], $t['namespace'], $t['kind'],
            $t['name'], $t['runtime_node_slug'], $t['target_id'], $t['incarnation_id'], $t['expected_uid'], $a['target_generation'],
            $i['kind'], $i['projection_fingerprint'], (string) $i['replicas'], '', '', '', $a['canonical_state']]);
    }

    public static function validate(array $a): void
    {
        if (($a['contract_version'] ?? null) !== 'utcp.infrastructure.v3' || ($a['canonical_state'] ?? null) !== 'bound'
            || ($a['action'] ?? null) !== 'converge_projection' || ! is_array($a['intent']['projection'] ?? null)
            || ! hash_equals(self::projection($a['intent']['projection']), $a['intent']['projection_fingerprint'] ?? '')) self::deny();
        // Reuse the existing closed envelope validator only after independently validating
        // the entire active-profile representation. The caller's original envelope is untouched.
        $envelope = $a; unset($envelope['canonical_state']); $envelope['contract_version'] = 'utcp.infrastructure.v2';
        $envelope['intent']['projection'] = ['template_version' => 'inert.pause.v1', 'image' => LifecycleFingerprint::IMAGE,
            'configuration_id' => $a['intent']['projection']['configuration_id'], 'configuration_sha256' => $a['intent']['projection']['configuration_sha256']];
        $envelope['intent']['projection_fingerprint'] = LifecycleFingerprint::projection($envelope['intent']['projection']);
        LifecycleFingerprint::validate($envelope);
    }

    public static function versioned(array $a): string
    {
        if (($a['contract_version'] ?? null) === 'utcp.infrastructure.v3') return self::calculate($a);
        if (($a['contract_version'] ?? null) === 'utcp.infrastructure.v2') return LifecycleFingerprint::calculate($a);
        return \Utcp\GnG2Proof\ActionFingerprint::calculate($a);
    }

    private static function digest(array $fields): string { $s = ''; foreach ($fields as $v) $s .= strlen($v).':'.$v; return hash('sha256', $s); }
    private static function deny(): never { throw new InvalidArgumentException('Invalid active projection.'); }
}

/** Test-only policy seam. Lifecycle/generations are global across versions. No persistence claim. */
final class ActiveAuthority
{
    private LifecycleAuthority $lifecycle;
    private array $canonical = [];
    private array $profiles = [];
    public function __construct() { $this->lifecycle = new LifecycleAuthority; }
    public function prepare(string $tenant, string $node): array { return $this->lifecycle->prepare($tenant, $node); }
    public function read(string $id): array { return $this->lifecycle->read($id); }
    public function bind(string $id, int $revision, string $uid): array { return $this->lifecycle->bind($id, $revision, $uid); }
    public function next(string $id): array { $r = $this->read($id); unset($this->canonical[$id]); return $this->lifecycle->allocateGeneration($id, $r['revision']); }
    public function retire(string $id): array { $r = $this->read($id); unset($this->canonical[$id]); return $this->lifecycle->retire($id, $r['revision']); }
    public function complete(string $id, bool $absent): array { $r = $this->read($id); return $this->lifecycle->completeRetirement($id, $r['revision'], $absent); }

    public function authorize(array $a): void
    {
        if (! hash_equals(ActiveFingerprint::versioned($a), $a['intent_fingerprint'])) self::deny();
        $t = $a['target']; $r = $this->read($t['incarnation_id']); $action = $a['action']; $version = $a['contract_version'];
        $required = match ($action) { 'create_suspended' => 'preparing', 'retire_delete' => 'retiring', default => 'bound' };
        if ($r['state'] !== $required || $r['name'] !== $t['name'] || $r['uid'] !== $t['expected_uid']
            || $r['tenant_id'] !== $a['tenant_id'] || $r['runtime_node_id'] !== $a['runtime_node_id'] || $r['generation'] !== $a['target_generation']) self::deny();
        $id = $r['incarnation_id'];
        if ($version === 'utcp.infrastructure.v1' && $a['replicas'] > 0 && ! isset($this->profiles[$id])) self::deny();
        if ($version === 'utcp.infrastructure.v2' && $action === 'converge_projection' && ($a['intent']['replicas'] ?? 0) > 0 && isset($this->profiles[$id])) self::deny();
        if (isset($this->canonical[$id]) && $this->canonical[$id]['intent_fingerprint'] !== $a['intent_fingerprint']) self::deny();
        $this->canonical[$id] = $a;
        if ($version === 'utcp.infrastructure.v3') $this->profiles[$id] = $a['intent']['projection_fingerprint'];
    }

    public function current(array $a): bool
    {
        $r = $this->read($a['target']['incarnation_id']);
        $required = match ($a['action']) { 'create_suspended' => 'preparing', 'retire_delete' => 'retiring', default => 'bound' };
        return $r['state'] === $required && isset($this->canonical[$r['incarnation_id']])
            && LifecycleAuthority::normalized($this->canonical[$r['incarnation_id']]) === LifecycleAuthority::normalized($a);
    }
    private static function deny(): never { throw new InvalidArgumentException('Canonical active-runtime authorization denied.'); }
}

/** Actual Laravel lease validation plus the test-only canonical envelope. No positive cache. */
final class ActiveClaimBridge
{
    public function __construct(private readonly ActiveAuthority $authority) {}
    public function verify(array $a): array
    {
        $result = ['contract_version' => $a['contract_version'] ?? 'utcp.infrastructure.v3', 'authorized' => false];
        try {
            if (! hash_equals(ActiveFingerprint::versioned($a), $a['intent_fingerprint'])) return $result;
            $result['authorized'] = DB::transaction(function () use ($a): bool {
                $row = DB::table('runtime_operations')->where('id', $a['operation_id'])->lockForUpdate()->first();
                return $row !== null && in_array($row->status, ['leased', 'running'], true)
                    && $row->tenant_id === $a['tenant_id'] && $row->runtime_node_id === $a['runtime_node_id']
                    && $row->lease_token === $a['claim']['fencing_token'] && (int) $row->attempt_count === $a['claim']['attempt']
                    && $row->lease_expires_at !== null && new DateTimeImmutable($row->lease_expires_at) > now()
                    && new DateTimeImmutable($row->lease_expires_at) == new DateTimeImmutable($a['claim']['lease_expires_at'])
                    && new DateTimeImmutable($a['deadline']) > now() && $this->authority->current($a);
            });
        } catch (\Throwable) { /* Fail closed and redact the supplied envelope. */ }
        return $result;
    }
}
