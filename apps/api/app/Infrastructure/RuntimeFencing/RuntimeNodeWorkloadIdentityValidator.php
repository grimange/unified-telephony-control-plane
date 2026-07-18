<?php

namespace App\Infrastructure\RuntimeFencing;

use InvalidArgumentException;

final class RuntimeNodeWorkloadIdentityValidator
{
    public const RESERVED_LABEL_KEY = 'kubernetes_workload';

    public const CANONICAL_RUNTIME_NAMESPACE = 'utcp-runtime';

    public static function fromLabelValue(mixed $value): RuntimeNodeWorkloadIdentity
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('RuntimeNode Kubernetes workload identity must be an object.');
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['deployment', 'namespace']) {
            throw new InvalidArgumentException('RuntimeNode Kubernetes workload identity must contain exactly namespace and deployment.');
        }

        $namespace = $value['namespace'];
        $deployment = $value['deployment'];
        if (! is_string($namespace) || ! is_string($deployment)) {
            throw new InvalidArgumentException('RuntimeNode Kubernetes workload identity values must be strings.');
        }
        if (! self::isDnsLabel($namespace) || ! self::isDnsLabel($deployment)) {
            throw new InvalidArgumentException('RuntimeNode Kubernetes workload identity is malformed.');
        }
        if ($namespace !== self::CANONICAL_RUNTIME_NAMESPACE) {
            throw new InvalidArgumentException('RuntimeNode Kubernetes namespace is not allowed.');
        }

        return new RuntimeNodeWorkloadIdentity($namespace, $deployment);
    }

    public static function isDnsLabel(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 63
            && preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $value) === 1;
    }
}
