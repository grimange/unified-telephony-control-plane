<?php

namespace App\Infrastructure\RuntimeFencing;

use Illuminate\Support\Facades\DB;
use Throwable;

final class InfrastructureConnectivityProbe
{
    public function __construct(
        private readonly KubernetesWorkloadClient $client,
        private readonly RuntimeNodeWorkloadIdentityResolver $identityResolver,
        private readonly KubernetesRuntimeWorkloadInspector $inspector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runOnce(): array
    {
        $candidates = DB::table('runtime_nodes')
            ->where('desired_state', 'active')
            ->where('observed_state', 'ready')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return ['status' => 'failed', 'reason' => 'eligible_runtime_node_missing'];
        }

        foreach ($candidates as $runtimeNode) {
            try {
                $identity = $this->identityResolver->resolve($runtimeNode);
            } catch (RuntimeNodeWorkloadIdentityException) {
                continue;
            }

            return $this->probeNode($runtimeNode, $identity);
        }

        return ['status' => 'failed', 'reason' => 'workload_identity_missing'];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeNode(object $runtimeNode, RuntimeNodeWorkloadIdentity $identity): array
    {
        try {
            $deployment = $this->client->getDeployment($identity->namespace, $identity->deployment);
            $pods = $this->client->listOwnedPods($identity->namespace, $identity);
        } catch (KubernetesWorkloadClientException $exception) {
            return ['status' => 'failed', 'reason' => $exception->reason];
        } catch (Throwable) {
            return ['status' => 'failed', 'reason' => 'failed'];
        }

        if ($deployment === null || ! $this->inspector->isOwnedAsteriskDeployment($deployment, $identity, $runtimeNode)) {
            return ['status' => 'failed', 'reason' => 'target_mismatch'];
        }

        return [
            'status' => 'ok',
            'runtime_node_slug' => (string) $runtimeNode->slug,
            'namespace' => $identity->namespace,
            'deployment' => $identity->deployment,
            'desired_replicas' => $this->inspector->desiredReplicas($deployment),
            'status_replicas' => $this->inspector->statusReplicas($deployment),
            'available_replicas' => $this->inspector->availableReplicas($deployment),
            'owned_pod_count' => count($pods),
        ];
    }
}
