<?php

namespace App\Infrastructure\RuntimeFencing;

final class RuntimeNodeWorkloadIdentityResolver
{
    private const CANONICAL_RUNTIME_NAMESPACE = 'utcp-runtime';

    public function resolve(object $runtimeNode): RuntimeNodeWorkloadIdentity
    {
        $labels = $this->decodeLabels($runtimeNode->labels ?? null);
        $workload = $labels['kubernetes_workload'] ?? null;
        if (! is_array($workload)) {
            throw RuntimeNodeWorkloadIdentityException::targetMismatch('RuntimeNode Kubernetes workload identity is missing.');
        }

        $namespace = trim((string) ($workload['namespace'] ?? ''));
        $deployment = trim((string) ($workload['deployment'] ?? ''));
        if (! $this->isDnsLabel($namespace) || ! $this->isDnsLabel($deployment)) {
            throw RuntimeNodeWorkloadIdentityException::targetMismatch('RuntimeNode Kubernetes workload identity is malformed.');
        }
        if ($namespace !== self::CANONICAL_RUNTIME_NAMESPACE) {
            throw RuntimeNodeWorkloadIdentityException::targetMismatch('RuntimeNode Kubernetes namespace is not allowed.');
        }

        return new RuntimeNodeWorkloadIdentity($namespace, $deployment);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLabels(mixed $labels): array
    {
        if (is_array($labels)) {
            return $labels;
        }
        if (is_object($labels)) {
            return get_object_vars($labels);
        }
        if (! is_string($labels) || trim($labels) === '') {
            return [];
        }

        $decoded = json_decode($labels, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isDnsLabel(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 63
            && preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $value) === 1;
    }
}
