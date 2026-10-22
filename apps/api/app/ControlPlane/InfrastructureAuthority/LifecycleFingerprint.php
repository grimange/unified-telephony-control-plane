<?php

namespace App\ControlPlane\InfrastructureAuthority;

use DateTimeImmutable;
use InvalidArgumentException;

final class LifecycleFingerprint
{
    public const IMAGE = 'registry.k8s.io/pause@sha256:ee6521f290b2168b6e0935a181d4cff9be1ac3f505666ef0e3c98fae8199917a';

    public static function projection(array $p): string
    {
        self::keys($p, ['template_version', 'image', 'configuration_id', 'configuration_sha256']);
        if ($p['template_version'] !== 'inert.pause.v1' || $p['image'] !== self::IMAGE) {
            self::invalid();
        }
        self::id($p['configuration_id']);
        self::hash($p['configuration_sha256']);

        return self::digest([$p['template_version'], $p['image'], $p['configuration_id'], $p['configuration_sha256']]);
    }

    public static function calculate(array $a): string
    {
        self::validate($a);
        $t = $a['target'];
        $i = $a['intent'];

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
            || ! is_array($a['target']) || ! is_array($a['intent']) || ! is_array($a['claim'])) {
            self::invalid();
        }
        foreach (['request_id', 'operation_id', 'tenant_id', 'runtime_node_id', 'idempotency_key', 'correlation_id', 'audit_id'] as $k) {
            self::id($a[$k]);
        }
        self::generation($a['target_generation']);
        self::hash($a['intent_fingerprint']);
        $t = $a['target'];
        self::keys($t, ['cluster_id', 'namespace', 'kind', 'name', 'runtime_node_slug', 'target_id', 'incarnation_id', 'expected_uid', 'expected_resource_version']);
        foreach (['cluster_id', 'target_id'] as $k) {
            self::id($t[$k]);
        }
        foreach (['namespace', 'name', 'runtime_node_slug'] as $k) {
            if (! is_string($t[$k]) || strlen($t[$k]) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $t[$k])) {
                self::invalid();
            }
        }
        if ($t['kind'] !== 'Deployment' || ! is_string($t['incarnation_id']) || ! preg_match('/\A[a-f0-9]{32}\z/D', $t['incarnation_id']) || $t['name'] !== 'utcp-'.$t['incarnation_id']) {
            self::invalid();
        }
        foreach (['expected_uid', 'expected_resource_version'] as $k) {
            if ($a['action'] === 'create_suspended') {
                if ($t[$k] !== null) {
                    self::invalid();
                }
            } elseif (! is_string($t[$k]) || $t[$k] === '' || strlen($t[$k]) > ($k === 'expected_uid' ? 128 : 256) || strpbrk($t[$k], "\r\n") !== false) {
                self::invalid();
            }
        }
        $i = $a['intent'];
        if ($a['action'] === 'retire_delete') {
            self::keys($i, ['kind', 'retirement_id', 'retirement_generation', 'retirement_fingerprint']);
            self::id($i['retirement_id']);
            self::generation($i['retirement_generation']);
            self::hash($i['retirement_fingerprint']);
            if ($i['retirement_generation'] !== $a['target_generation']) {
                self::invalid();
            }
        } else {
            self::keys($i, $a['action'] === 'create_suspended' ? ['kind', 'projection', 'projection_fingerprint'] : ['kind', 'projection', 'projection_fingerprint', 'replicas']);
            if (! is_array($i['projection']) || ! is_string($i['projection_fingerprint']) || ! hash_equals(self::projection($i['projection']), $i['projection_fingerprint'])) {
                self::invalid();
            }
            if ($a['action'] === 'converge_projection' && (! is_int($i['replicas']) || $i['replicas'] < 0 || $i['replicas'] > 100)) {
                self::invalid();
            }
        }
        if ($i['kind'] !== $a['action']) {
            self::invalid();
        }
        self::keys($a['claim'], ['attempt', 'fencing_token', 'lease_expires_at']);
        if (! is_int($a['claim']['attempt']) || $a['claim']['attempt'] < 1 || $a['claim']['attempt'] > 2147483647 || ! is_string($a['claim']['fencing_token']) || ! preg_match('/\A[0-9a-f]{32}\z/D', $a['claim']['fencing_token'])) {
            self::invalid();
        }
        foreach ([$a['deadline'], $a['claim']['lease_expires_at']] as $date) {
            if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $date)) {
                self::invalid();
            }
            new DateTimeImmutable($date);
            $e = DateTimeImmutable::getLastErrors();
            if ($e !== false && ($e['warning_count'] || $e['error_count'])) {
                self::invalid();
            }
        }
    }

    private static function digest(array $fields): string
    {
        $s = '';
        foreach ($fields as $v) {
            $s .= strlen($v).':'.$v;
        }

        return hash('sha256', $s);
    }

    private static function keys(array $a, array $keys): void
    {
        if (array_diff(array_keys($a), $keys) || array_diff($keys, array_keys($a))) {
            self::invalid();
        }
    }

    private static function id(mixed $v): void
    {
        if (! is_string($v) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/D', $v)) {
            self::invalid();
        }
    }

    private static function hash(mixed $v): void
    {
        if (! is_string($v) || ! preg_match('/\A[0-9a-f]{64}\z/D', $v)) {
            self::invalid();
        }
    }

    private static function generation(mixed $v): void
    {
        if (! is_string($v) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $v) || strlen($v) === 19 && strcmp($v, '9223372036854775807') > 0) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid Lifecycle V2 intent.');
    }
}
