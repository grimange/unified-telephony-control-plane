<?php

namespace App\Infrastructure\RuntimeFencing;

use Illuminate\Support\Facades\DB;
use Throwable;

final class KubernetesRuntimeFenceAdapter implements RuntimeInfrastructureFenceAdapter
{
    public function __construct(
        private readonly KubernetesWorkloadClient $client,
        private readonly RuntimeNodeWorkloadIdentityResolver $identityResolver,
        private readonly KubernetesRuntimeWorkloadInspector $inspector,
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

        if ($deployment === null || ! $this->inspector->isOwnedAsteriskDeployment($deployment, $identity, $formerRuntimeNode)) {
            return ['status' => 'target_mismatch'];
        }

        if ($this->runtimeNodeRecovered((string) $formerRuntimeNode->id, (string) $formerRuntimeNode->tenant_id)) {
            return ['status' => 'target_recovered'];
        }

        $preScaleReplicas = $this->inspector->desiredReplicas($deployment);
        $selfScaleProvenance = $this->hasSelfScaleProvenance($authorityContext, $identity);
        $wasAlreadyZero = $preScaleReplicas === 0;
        $scaleRequestedByOperation = false;
        if (! $wasAlreadyZero) {
            try {
                $this->client->scaleDeployment($identity->namespace, $identity->deployment, 0);
                $scaleRequestedByOperation = true;
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

        if ($current === null || ! $this->inspector->isOwnedAsteriskDeployment($current, $identity, $formerRuntimeNode)) {
            return ['status' => 'target_mismatch'];
        }

        if ($this->terminationPredicateSatisfied($current, $pods)) {
            $status = ($scaleRequestedByOperation || $selfScaleProvenance) ? 'fenced' : 'already_fenced';

            return [
                'status' => $status,
                'details' => array_merge([
                    'namespace' => $identity->namespace,
                    'deployment' => $identity->deployment,
                    'desired_replicas' => 0,
                    'status_replicas' => $this->inspector->statusReplicas($current),
                    'available_replicas' => $this->inspector->availableReplicas($current),
                    'owned_pods_remaining' => 0,
                ], $scaleRequestedByOperation ? $this->scaleProvenanceDetails($preScaleReplicas) : []),
            ];
        }

        return [
            'status' => 'fence_in_progress',
            'details' => array_merge([
                'namespace' => $identity->namespace,
                'deployment' => $identity->deployment,
                'desired_replicas' => $this->inspector->desiredReplicas($current),
                'status_replicas' => $this->inspector->statusReplicas($current),
                'available_replicas' => $this->inspector->availableReplicas($current),
                'owned_pods_remaining' => count($pods),
            ], $scaleRequestedByOperation ? $this->scaleProvenanceDetails($preScaleReplicas) : []),
        ];
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
        return $this->inspector->desiredReplicas($deployment) === 0
            && $this->inspector->statusReplicas($deployment) === 0
            && $this->inspector->availableReplicas($deployment) === 0
            && count($pods) === 0;
    }

    /**
     * @param  array<string, mixed>  $authorityContext
     */
    private function hasSelfScaleProvenance(array $authorityContext, RuntimeNodeWorkloadIdentity $identity): bool
    {
        $operationId = (string) ($authorityContext['runtime_fence_operation_id'] ?? '');
        $provenance = $authorityContext['runtime_fence_self_scale_provenance'] ?? null;
        if (! is_array($provenance)) {
            return false;
        }

        return ($provenance['by_operation'] ?? null) === true
            && (string) ($provenance['operation_id'] ?? '') === $operationId
            && (string) ($provenance['namespace'] ?? '') === $identity->namespace
            && (string) ($provenance['deployment'] ?? '') === $identity->deployment;
    }

    /**
     * @return array<string, mixed>
     */
    private function scaleProvenanceDetails(int $preScaleReplicas): array
    {
        return [
            'scale_to_zero_requested_by_operation' => true,
            'pre_scale_replicas' => $preScaleReplicas,
        ];
    }
}
