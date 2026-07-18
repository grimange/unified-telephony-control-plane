<?php

namespace App\Infrastructure\RuntimeFencing;

final class KubernetesRuntimeWorkloadInspector
{
    /**
     * @param  array<string, mixed>  $deployment
     */
    public function isOwnedAsteriskDeployment(array $deployment, RuntimeNodeWorkloadIdentity $identity, object $runtimeNode): bool
    {
        $metadata = $deployment['metadata'] ?? [];
        if (! is_array($metadata)) {
            return false;
        }
        if ((string) ($metadata['namespace'] ?? '') !== $identity->namespace || (string) ($metadata['name'] ?? '') !== $identity->deployment) {
            return false;
        }
        $labels = $metadata['labels'] ?? [];
        if (! is_array($labels)) {
            return false;
        }

        return (string) ($labels['app.kubernetes.io/part-of'] ?? '') === 'utcp'
            && (string) ($labels['app.kubernetes.io/component'] ?? '') === 'asterisk-ari'
            && (string) ($labels['utcp.dev/runtime-node'] ?? '') === (string) $runtimeNode->slug;
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    public function desiredReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'spec.replicas', 1));
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    public function statusReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'status.replicas', 0));
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    public function availableReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'status.availableReplicas', 0));
    }
}
