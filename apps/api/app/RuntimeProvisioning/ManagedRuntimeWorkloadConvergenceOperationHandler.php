<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentity;
use App\RuntimeEngine\Commands\RunsWithoutRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\RuntimeRegistry\RuntimeExecutionContract;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;

final class ManagedRuntimeWorkloadConvergenceOperationHandler implements RunsWithoutRuntimeAdapter, RuntimeOperationHandler
{
    /** @var array<string, ManagedRuntimeProvisioningProvider> */
    private array $providers = [];

    /** @param iterable<ManagedRuntimeProvisioningProvider> $providers */
    public function __construct(
        private readonly KubernetesWorkloadClient $kubernetes,
        private readonly RuntimeRegistryService $registry,
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->adapterKey()] = $provider;
        }
    }

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_workload_converge', 'runtime.node.workload.converge');
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function requiredRuntimeCapability(): ?string
    {
        return null;
    }

    public function execute(array $operation, ?RuntimeAdapter $adapter): array
    {
        unset($adapter);
        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $nodeId = (string) ($operation['runtime_node_id'] ?? data_get($operation, 'payload.runtime_node_id', ''));
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        if ($tenantId === '' || $node === null || ! $this->registry->isManagedNode($tenantId, $nodeId) || ! in_array($node->desired_state, ['active', 'draining'], true)) {
            return $this->failure(FailureClass::InvalidRequest, 'managed_workload_authority_invalid', 'managed workload convergence requires an active managed RuntimeNode');
        }

        $provider = $this->providers[(string) $node->adapter_key] ?? null;
        if ($provider === null) {
            return $this->failure(FailureClass::UnsupportedCapability, 'managed_workload_provider_not_registered', 'managed workload provider is not registered');
        }

        try {
            $this->kubernetes->applyDeployment($provider->desiredDeployment($nodeId, (string) $node->slug), (string) $node->slug);
            $this->observeExecutionImage($node);
        } catch (KubernetesWorkloadClientException $exception) {
            $class = match ($exception->reason) {
                'ownership_conflict' => FailureClass::Conflict,
                'permission_denied' => FailureClass::AuthorizationFailed,
                default => FailureClass::InternalError,
            };

            return $this->failure($class, 'workload_convergence_'.$exception->reason, 'managed workload Kubernetes convergence failed');
        } catch (\Throwable) {
            return $this->failure(FailureClass::InternalError, 'workload_convergence_failed', 'managed workload convergence failed');
        }

        return [
            'status' => 'completed',
            'event_type' => 'runtime.node.workload_converged',
            'event_payload' => [
                'operation_type' => $this->operationType(),
                'runtime_node_id' => $nodeId,
                'resource' => 'Deployment',
            ],
        ];
    }

    private function observeExecutionImage(object $node): void
    {
        if ((string) $node->adapter_key !== (string) config('asterisk_ari.adapter_key', 'asterisk-ari')) {
            return;
        }

        $labels = is_string($node->labels)
            ? json_decode($node->labels, true, 512, JSON_THROW_ON_ERROR)
            : ($node->labels ?? []);
        $workload = is_array($labels) ? ($labels['kubernetes_workload'] ?? null) : null;
        if (! is_array($workload) || ! isset($workload['namespace'], $workload['deployment'])) {
            return;
        }

        $candidates = [];
        foreach ($this->kubernetes->listOwnedPods(
            (string) $workload['namespace'],
            new RuntimeNodeWorkloadIdentity((string) $workload['namespace'], (string) $workload['deployment']),
        ) as $pod) {
            if (($pod['metadata']['deletionTimestamp'] ?? null) !== null
                || in_array($pod['status']['phase'] ?? null, ['Succeeded', 'Failed'], true)) {
                continue;
            }

            $ready = false;
            foreach (data_get($pod, 'status.conditions', []) as $condition) {
                if (is_array($condition)
                    && ($condition['type'] ?? null) === 'Ready'
                    && ($condition['status'] ?? null) === 'True'
                ) {
                    $ready = true;
                    break;
                }
            }

            foreach (data_get($pod, 'status.containerStatuses', []) as $status) {
                if (($status['name'] ?? '') === 'asterisk' && is_string($status['imageID'] ?? null) && $status['imageID'] !== '') {
                    $image = RuntimeExecutionContract::digest($status['imageID']);
                    if ($image !== null) {
                        $candidates[] = [
                            'name' => (string) data_get($pod, 'metadata.name', ''),
                            'ready' => $ready,
                            'image' => $image,
                        ];
                    }
                    break;
                }
            }
        }

        usort($candidates, static fn (array $left, array $right): int => [! $left['ready'], $left['name']] <=> [! $right['ready'], $right['name']]);
        $image = $candidates[0]['image'] ?? null;
        if ($image === null) {
            return;
        }

        DB::table('runtime_nodes')->where('id', $node->id)->update(['observed_execution_image' => $image, 'updated_at' => now()]);
    }

    /** @return array{status:string,failure_class:string,failure_code:string,failure_message:string} */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return ['status' => 'failed', 'failure_class' => $class->value, 'failure_code' => $code, 'failure_message' => $message];
    }
}
