<?php

declare(strict_types=1);

namespace Utcp\GnG2Proof;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Test-only proposed seam: no route, registration, network listener or new table. */
final class ClaimVerificationBridge
{
    public function __construct(private readonly array $canonical, private readonly bool $lifecycleAllowed = true) {}

    public function verify(array $action): array
    {
        $denied = ['contract_version' => 'utcp.infrastructure.v1', 'authorized' => false];
        try {
            ActionFingerprint::validate($action);
            if (! hash_equals(ActionFingerprint::calculate($action), $action['intent_fingerprint'])) return $denied;
            return DB::transaction(function () use ($action, $denied): array {
                $row = DB::table('runtime_operations')->where('id', $action['operation_id'])->lockForUpdate()->first();
                if ($row === null || ! $this->lifecycleAllowed || ! in_array($row->status, ['leased', 'running'], true)
                    || $row->lease_token !== $action['claim']['fencing_token']
                    || (int) $row->attempt_count !== $action['claim']['attempt']
                    || $row->tenant_id !== $action['tenant_id'] || $row->runtime_node_id !== $action['runtime_node_id']
                    || $row->lease_expires_at === null || new DateTimeImmutable($row->lease_expires_at) <= now()
                    || new DateTimeImmutable($row->lease_expires_at) != new DateTimeImmutable($action['claim']['lease_expires_at'])
                    || new DateTimeImmutable($action['deadline']) <= now()) return $denied;
                // Test-owned intent record stands in for the future canonical binding.
                // Current production operation rows do not contain this proposed seam.
                foreach (['operation_id', 'tenant_id', 'runtime_node_id', 'target', 'target_generation',
                    'intent_fingerprint', 'action', 'replicas', 'idempotency_key', 'correlation_id', 'audit_id'] as $key) {
                    $candidate = $action[$key];
                    $expected = $this->canonical[$key];
                    if ($key === 'target') { ksort($candidate); ksort($expected); }
                    if ($candidate !== $expected) return $denied;
                }
                return ['contract_version' => 'utcp.infrastructure.v1', 'authorized' => true];
            });
        } catch (\Throwable) {
            return $denied;
        }
    }
}

final class ActionFingerprint
{
    public static function calculate(array $a): string
    {
        self::validate($a);
        $t = $a['target'];
        $fields = [$a['contract_version'], $a['tenant_id'], $a['runtime_node_id'], $t['cluster_id'], $t['namespace'],
            $t['kind'], $t['name'], $t['runtime_node_slug'], $t['target_id'], $t['incarnation_id'], $t['expected_uid'],
            $a['target_generation'], 'set_replicas', (string) $a['replicas']];
        $bytes = '';
        foreach ($fields as $field) $bytes .= strlen($field).':'.$field;
        return hash('sha256', $bytes);
    }

    public static function validate(array $a): void
    {
        self::keys($a, ['contract_version', 'request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'target',
            'target_generation', 'intent_fingerprint', 'action', 'replicas', 'idempotency_key', 'claim', 'deadline',
            'correlation_id', 'audit_id']);
        if ($a['contract_version'] !== 'utcp.infrastructure.v1' || ! in_array($a['action'], ['admit_generation', 'set_replicas'], true)
            || ! is_int($a['replicas']) || $a['replicas'] < 0 || $a['replicas'] > 100
            || ! is_string($a['target_generation']) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $a['target_generation'])
            || strlen($a['target_generation']) === 19 && strcmp($a['target_generation'], '9223372036854775807') > 0
            || ! is_string($a['intent_fingerprint']) || ! preg_match('/\A[0-9a-f]{64}\z/D', $a['intent_fingerprint'])) self::invalid();
        foreach (['request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'idempotency_key', 'correlation_id', 'audit_id'] as $k) self::identifier($a[$k]);
        if (! is_array($a['target']) || ! is_array($a['claim'])) self::invalid();
        $t = $a['target'];
        self::keys($t, ['cluster_id', 'namespace', 'kind', 'name', 'target_id', 'incarnation_id', 'expected_uid', 'expected_resource_version', 'runtime_node_slug']);
        foreach (['cluster_id', 'target_id', 'incarnation_id'] as $k) self::identifier($t[$k]);
        if ($t['kind'] !== 'Deployment') self::invalid();
        foreach (['namespace', 'name'] as $k) {
            if (! is_string($t[$k]) || strlen($t[$k]) > 253 || ! preg_match('/\A[a-z0-9]([-a-z0-9.]*[a-z0-9])?\z/D', $t[$k])) self::invalid();
        }
        if (! is_string($t['runtime_node_slug']) || strlen($t['runtime_node_slug']) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $t['runtime_node_slug'])) self::invalid();
        foreach (['expected_uid', 'expected_resource_version'] as $k) {
            if (! is_string($t[$k]) || ! preg_match('//u', $t[$k]) || mb_strlen($t[$k]) < 1 || mb_strlen($t[$k]) > 256 || strpbrk($t[$k], "\r\n") !== false) self::invalid();
        }
        self::keys($a['claim'], ['attempt', 'fencing_token', 'lease_expires_at']);
        if (! is_int($a['claim']['attempt']) || $a['claim']['attempt'] < 1 || ! is_string($a['claim']['fencing_token']) || ! preg_match('/\A[0-9a-f]{32}\z/D', $a['claim']['fencing_token'])) self::invalid();
        foreach ([$a['deadline'], $a['claim']['lease_expires_at']] as $date) {
            if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $date)) self::invalid();
            $parsed = new DateTimeImmutable($date);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) self::invalid();
        }
    }

    private static function identifier(mixed $v): void
    {
        if (! is_string($v) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $v)) self::invalid();
    }

    private static function keys(array $a, array $keys): void
    {
        if (array_diff(array_keys($a), $keys) || array_diff($keys, array_keys($a))) self::invalid();
    }

    private static function invalid(): never { throw new InvalidArgumentException('Invalid infrastructure intent.'); }
}
