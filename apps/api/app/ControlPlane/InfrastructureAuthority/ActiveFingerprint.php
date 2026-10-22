<?php

namespace App\ControlPlane\InfrastructureAuthority;

use InvalidArgumentException;

final class ActiveFingerprint
{
    public static function projection(array $p): string
    {
        $keys = ['template_version', 'image', 'configuration_id', 'configuration_sha256', 'secret_name'];
        if (array_diff(array_keys($p), $keys) || array_diff($keys, array_keys($p))) {
            self::deny();
        }
        if (! in_array($p['template_version'], ['asterisk.managed.v1', 'freeswitch.managed.v1'], true)
            || ! is_string($p['image']) || ! preg_match('/\A[a-z0-9][a-z0-9.:-]*\/[a-z0-9][a-z0-9._\/-]*@sha256:[0-9a-f]{64}\z/D', $p['image'])
            || ! is_string($p['configuration_id']) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,127}\z/D', $p['configuration_id'])
            || ! is_string($p['configuration_sha256']) || ! preg_match('/\A[0-9a-f]{64}\z/D', $p['configuration_sha256'])
            || ! is_string($p['secret_name']) || strlen($p['secret_name']) > 63 || ! preg_match('/\A[a-z0-9]([-a-z0-9]*[a-z0-9])?\z/D', $p['secret_name'])) {
            self::deny();
        }

        return self::digest(array_map(fn ($k) => $p[$k], $keys));
    }

    public static function calculate(array $a): string
    {
        self::validate($a);
        $t = $a['target'];
        $i = $a['intent'];

        return self::digest([$a['contract_version'], $a['tenant_id'], $a['runtime_node_id'], $t['cluster_id'], $t['namespace'], $t['kind'],
            $t['name'], $t['runtime_node_slug'], $t['target_id'], $t['incarnation_id'], $t['expected_uid'], $a['target_generation'],
            $i['kind'], $i['projection_fingerprint'], (string) $i['replicas'], '', '', '', $a['canonical_state']]);
    }

    public static function validate(array $a): void
    {
        if (($a['contract_version'] ?? null) !== 'utcp.infrastructure.v3' || ($a['canonical_state'] ?? null) !== 'bound'
            || ($a['action'] ?? null) !== 'converge_projection' || ! is_array($a['intent']['projection'] ?? null)
            || ! hash_equals(self::projection($a['intent']['projection']), $a['intent']['projection_fingerprint'] ?? '')) {
            self::deny();
        }
        // Reuse the existing closed envelope validator only after independently validating
        // the entire active-profile representation. The caller's original envelope is untouched.
        $envelope = $a;
        unset($envelope['canonical_state']);
        $envelope['contract_version'] = 'utcp.infrastructure.v2';
        $envelope['intent']['projection'] = ['template_version' => 'inert.pause.v1', 'image' => LifecycleFingerprint::IMAGE,
            'configuration_id' => $a['intent']['projection']['configuration_id'], 'configuration_sha256' => $a['intent']['projection']['configuration_sha256']];
        $envelope['intent']['projection_fingerprint'] = LifecycleFingerprint::projection($envelope['intent']['projection']);
        LifecycleFingerprint::validate($envelope);
    }

    public static function versioned(array $a): string
    {
        if (($a['contract_version'] ?? null) === 'utcp.infrastructure.v3') {
            return self::calculate($a);
        }
        if (($a['contract_version'] ?? null) === 'utcp.infrastructure.v2') {
            return LifecycleFingerprint::calculate($a);
        }

        return ActionFingerprint::calculate($a);
    }

    private static function digest(array $fields): string
    {
        $s = '';
        foreach ($fields as $v) {
            $s .= strlen($v).':'.$v;
        }

        return hash('sha256', $s);
    }

    private static function deny(): never
    {
        throw new InvalidArgumentException('Invalid active projection.');
    }
}
