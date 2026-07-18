<?php

namespace App\Infrastructure\RuntimeFencing;

use Illuminate\Support\Facades\DB;
use Throwable;

final class KubernetesRuntimeFenceAdapter implements RuntimeInfrastructureFenceAdapter
{
    public function __construct(
        private readonly KubernetesWorkloadClient $client,
        private readonly RuntimeNodeWorkloadIdentityResolver $identityResolver,
    ) {}

    public function adapterKey(): string
    {
        return 'kubernetes';
    }

    public function fence(object $formerRuntimeNode, array $authorityContext): array
    {
        try {
            $identity = $this->identityResolver->resolve($formerRuntimeNode);
            $deployment = $this->client->getDeployment($identity->namespace, $identity->deployment);
        } catch (RuntimeNodeWorkloadIdentityException) {
            return ['status' => 'target_mismatch'];
        } catch (KubernetesWorkloadClientException $exception) {
            return ['status' => $exception->reason];
        } catch (Throwable) {
            return ['status' => 'failed'];
        }

        if ($deployment === null || ! $this->isOwnedAsteriskDeployment($deployment, $identity, $formerRuntimeNode)) {
            return ['status' => 'target_mismatch'];
        }

        if ($this->runtimeNodeRecovered((string) $formerRuntimeNode->id, (string) $formerRuntimeNode->tenant_id)) {
            return ['status' => 'target_recovered'];
        }

        $wasAlreadyZero = $this->desiredReplicas($deployment) === 0;
        if (! $wasAlreadyZero) {
            try {
                $this->client->scaleDeployment($identity->namespace, $identity->deployment, 0);
            } catch (KubernetesWorkloadClientException $exception) {
                return ['status' => $exception->reason];
            } catch (Throwable) {
                return ['status' => 'failed'];
            }
        }

        try {
            $current = $this->client->getDeployment($identity->namespace, $identity->deployment);
            $pods = $this->client->listOwnedPods($identity->namespace, $identity);
        } catch (KubernetesWorkloadClientException $exception) {
            return ['status' => $exception->reason];
        } catch (Throwable) {
            return ['status' => 'failed'];
        }

        if ($current === null || ! $this->isOwnedAsteriskDeployment($current, $identity, $formerRuntimeNode)) {
            return ['status' => 'target_mismatch'];
        }

        if ($this->terminationPredicateSatisfied($current, $pods)) {
            return [
                'status' => $wasAlreadyZero ? 'already_fenced' : 'fenced',
                'details' => [
                    'namespace' => $identity->namespace,
                    'deployment' => $identity->deployment,
                    'desired_replicas' => 0,
                    'status_replicas' => $this->statusReplicas($current),
                    'available_replicas' => $this->availableReplicas($current),
                    'owned_pods_remaining' => 0,
                ],
            ];
        }

        return [
            'status' => 'fence_in_progress',
            'details' => [
                'namespace' => $identity->namespace,
                'deployment' => $identity->deployment,
                'desired_replicas' => $this->desiredReplicas($current),
                'status_replicas' => $this->statusReplicas($current),
                'available_replicas' => $this->availableReplicas($current),
                'owned_pods_remaining' => count($pods),
            ],
        ];
    }

    private function isOwnedAsteriskDeployment(array $deployment, RuntimeNodeWorkloadIdentity $identity, object $runtimeNode): bool
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

    private function runtimeNodeRecovered(string $runtimeNodeId, string $tenantId): bool
    {
        return DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->where('observed_state', 'ready')
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     */
    private function terminationPredicateSatisfied(array $deployment, array $pods): bool
    {
        return $this->desiredReplicas($deployment) === 0
            && $this->statusReplicas($deployment) === 0
            && $this->availableReplicas($deployment) === 0
            && count($pods) === 0;
    }

    private function desiredReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'spec.replicas', 1));
    }

    private function statusReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'status.replicas', 0));
    }

    private function availableReplicas(array $deployment): int
    {
        return max(0, (int) data_get($deployment, 'status.availableReplicas', 0));
    }
}
