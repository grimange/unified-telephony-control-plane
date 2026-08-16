<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeEngine\Commands\RunsWithoutRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use Illuminate\Support\Facades\DB;

final class ManagedAsteriskDeprovisioningOperationHandler implements RunsWithoutRuntimeAdapter, RuntimeOperationHandler
{
    public function __construct(private readonly KubernetesWorkloadClient $kubernetes) {}

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_deprovision', 'runtime.node.deprovision');
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
        $nodeId = (string) ($operation['runtime_node_id'] ?? '');
        $requestId = (string) data_get($operation, 'payload.provisioning_request_id', '');
        if ($tenantId === '' || $nodeId === '' || $requestId === '') {
            return $this->failure(FailureClass::InvalidRequest, 'deprovision_authority_invalid', 'managed deprovision authority is incomplete');
        }

        $request = DB::table('runtime_provisioning_requests')
            ->where('id', $requestId)
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->first();
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        if ($request === null || $node === null || $node->desired_state !== 'retired') {
            return $this->failure(FailureClass::Conflict, 'deprovision_requires_retired_managed_node', 'managed deprovision requires a retired RuntimeNode');
        }

        $names = ManagedAsteriskResourceIdentity::names((string) $node->slug, $nodeId);
        $resources = [
            ['kind' => 'Deployment', 'name' => $names['deployment']],
            ['kind' => 'Service', 'name' => $names['service']],
            ['kind' => 'Secret', 'name' => $names['secret']],
        ];

        try {
            foreach ($resources as $resource) {
                $existing = $this->kubernetes->inspectResource($resource['kind'], $resource['name']);
                if ($existing !== null && ! $this->isOwned($existing, (string) $node->slug)) {
                    return $this->failure(FailureClass::Conflict, 'deprovision_ownership_conflict', 'managed Kubernetes resource ownership conflict');
                }
            }

            $this->kubernetes->deleteDeployment($names['deployment'], (string) $node->slug);
            $this->kubernetes->deleteService($names['service'], (string) $node->slug);
            $this->kubernetes->deleteSecret($names['secret'], (string) $node->slug);

            foreach ($resources as $resource) {
                if ($this->kubernetes->inspectResource($resource['kind'], $resource['name']) !== null) {
                    return $this->failure(FailureClass::InternalError, 'deprovision_resources_not_absent', 'managed Kubernetes resources have not converged to absent');
                }
            }
        } catch (KubernetesWorkloadClientException $exception) {
            $class = $exception->reason === 'ownership_conflict'
                ? FailureClass::Conflict
                : ($exception->reason === 'permission_denied' ? FailureClass::AuthorizationFailed : FailureClass::InternalError);

            return $this->failure($class, 'deprovision_'.$exception->reason, $class === FailureClass::Conflict ? 'managed Kubernetes resource ownership conflict' : 'managed Asterisk deprovisioning failed');
        } catch (\Throwable) {
            return $this->failure(FailureClass::InternalError, 'deprovision_failed', 'managed Asterisk deprovisioning failed');
        }

        return [
            'status' => 'completed',
            'event_type' => 'runtime_deprovision.succeeded',
            'event_payload' => [
                'operation_type' => $this->operationType(),
                'runtime_node_id' => $nodeId,
                'provisioning_request_id' => $requestId,
                'resources' => $resources,
            ],
        ];
    }

    /** @param array<string, mixed> $resource */
    private function isOwned(array $resource, string $slug): bool
    {
        $labels = data_get($resource, 'metadata.labels', []);

        return is_array($labels)
            && (string) ($labels['app.kubernetes.io/part-of'] ?? '') === 'utcp'
            && (string) ($labels['utcp.dev/runtime-node'] ?? '') === $slug;
    }

    /** @return array{status:string,failure_class:string,failure_code:string,failure_message:string} */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return ['status' => 'terminal_failure', 'failure_class' => $class->value, 'failure_code' => $code, 'failure_message' => $message];
    }
}
