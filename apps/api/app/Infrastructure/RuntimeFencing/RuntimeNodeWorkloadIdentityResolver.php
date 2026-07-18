<?php

namespace App\Infrastructure\RuntimeFencing;

use InvalidArgumentException;

final class RuntimeNodeWorkloadIdentityResolver
{
    public function resolve(object $runtimeNode): RuntimeNodeWorkloadIdentity
    {
        $labels = $this->decodeLabels($runtimeNode->labels ?? null);
        $workload = $labels['kubernetes_workload'] ?? null;
        if ($workload === null) {
            throw RuntimeNodeWorkloadIdentityException::targetMismatch('RuntimeNode Kubernetes workload identity is missing.');
        }

        try {
            return RuntimeNodeWorkloadIdentityValidator::fromLabelValue($workload);
        } catch (InvalidArgumentException $exception) {
            throw RuntimeNodeWorkloadIdentityException::targetMismatch($exception->getMessage());
        }
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
}
