<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeEngine\Commands\RunsWithoutRuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\RuntimeRegistry\RuntimeExecutionContract;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;

final class ManagedAsteriskProvisioningOperationHandler implements ManagedRuntimeProvisioningProvider, RunsWithoutRuntimeAdapter, RuntimeOperationHandler
{
    public function __construct(private readonly KubernetesWorkloadClient $kubernetes, private readonly RuntimeRegistryService $registry, private readonly AsteriskCatalog $asterisk, private readonly AsteriskAriProfileService $profiles) {}

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_provision', 'runtime.node.provision');
    }

    public function adapterKey(): string
    {
        return $this->asterisk->adapterKey();
    }

    public function provisionOperation(array $operation, ?RuntimeAdapter $adapter): array
    {
        return $this->execute($operation, $adapter);
    }

    public function deprovisionOperation(array $operation, ?RuntimeAdapter $adapter): array
    {
        unset($operation, $adapter);

        return ['status' => 'failed', 'failure_class' => FailureClass::UnsupportedCapability->value, 'failure_code' => 'managed_deprovision_provider_mismatch', 'failure_message' => 'Asterisk provisioning provider cannot deprovision through the provisioning handler.'];
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
        $payload = $operation['payload'] ?? [];
        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $nodeId = (string) ($payload['runtime_node_id'] ?? '');
        $request = DB::table('runtime_provisioning_requests')->where('id', $payload['provisioning_request_id'] ?? null)->where('tenant_id', $tenantId)->first();
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        if ($request === null || $node === null || (string) $request->runtime_node_id !== $nodeId || $node->adapter_key !== $this->asterisk->adapterKey() || $node->desired_state !== 'draft') {
            return $this->failure(FailureClass::InvalidRequest, 'provisioning_authority_invalid', 'managed provisioning authority is invalid');
        }
        $target = DB::table('deployment_targets')->where('id', $request->deployment_target_id)->where('tenant_id', $tenantId)->first();
        if ($target === null || $target->kind !== 'local_kubernetes') {
            return $this->failure(FailureClass::InvalidRequest, 'provisioning_target_invalid', 'managed provisioning target is invalid');
        }
        $image = (string) config('asterisk_ari.managed_image', '');
        if (! RuntimeExecutionContract::isQualifiedImmutableImageReference($image)) {
            return $this->failure(FailureClass::InvalidRequest, 'managed_asterisk_image_invalid', 'managed Asterisk image configuration is invalid');
        }
        $context = ExecutionContext::system(tenantId: $tenantId, reason: 'managed Asterisk provisioning', origin: 'runtime-engine');
        $names = ManagedAsteriskResourceIdentity::names((string) $node->slug, $nodeId);
        try {
            $this->registry->ensureManagedExecutionImage($context, $tenantId, $nodeId, $image);
            $credential = $this->registry->ensureManagedCredential($context, $tenantId, $nodeId, $this->asterisk->credentialType());
            $this->kubernetes->applySecret($this->secret($names['secret'], $credential), (string) $node->slug);
            $this->kubernetes->applyDeployment($this->deployment($names, (string) $node->slug, $image), (string) $node->slug);
            $this->kubernetes->applyService($this->service($names, (string) $node->slug), (string) $node->slug);
            $host = $names['service'].'.utcp-runtime.svc.cluster.local';
            foreach ([
                ['purpose' => 'control', 'transport' => 'http', 'host' => $host, 'port' => 8088, 'path' => '/ari', 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
                ['purpose' => 'events', 'transport' => 'ws', 'host' => $host, 'port' => 8088, 'path' => '/ari/events', 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
                ['purpose' => 'health', 'transport' => 'http', 'host' => $host, 'port' => 8088, 'path' => '/ari', 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
                ['purpose' => 'sip', 'transport' => 'udp', 'host' => $host, 'port' => 5060, 'path' => null, 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
            ] as $endpoint) {
                $this->registry->ensureManagedEndpoint($context, $tenantId, $nodeId, $endpoint);
            }
            $this->registry->ensureManagedCapabilities($context, $tenantId, $nodeId, config('runtime_registry.adapter_keys.asterisk-ari.supported_capabilities', []));
            $this->profiles->put($context, $tenantId, $nodeId, $this->profiles->defaults());
            $this->registry->ensureManagedWorkloadIdentity($context, $tenantId, $nodeId, ['kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => $names['deployment']]]);
            $this->registry->activateManagedNode($context, $tenantId, $nodeId);
        } catch (KubernetesWorkloadClientException $exception) {
            return $this->failure($exception->reason === 'ownership_conflict' ? FailureClass::Conflict : ($exception->reason === 'permission_denied' ? FailureClass::AuthorizationFailed : FailureClass::InternalError), 'provisioning_'.$exception->reason, $exception->reason === 'ownership_conflict' ? 'managed Kubernetes resource ownership conflict' : 'managed Kubernetes provisioning failed');
        } catch (\Throwable) {
            return $this->failure(FailureClass::InternalError, 'provisioning_failed', 'managed Asterisk provisioning failed');
        }

        return ['status' => 'completed', 'event_type' => 'runtime.node.provisioned', 'event_payload' => ['operation_type' => $this->operationType(), 'runtime_node_id' => $nodeId, 'resources' => [
            ['kind' => 'Secret', 'name' => $names['secret']],
            ['kind' => 'Deployment', 'name' => $names['deployment']],
            ['kind' => 'Service', 'name' => $names['service']],
        ]]];
    }

    private function secret(string $name, array $credential): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => ['name' => $name, 'namespace' => 'utcp-runtime'], 'type' => 'Opaque', 'stringData' => ['ARI_USERNAME' => $credential['identifier'], 'ARI_PASSWORD' => $credential['secret']]];
    }

    private function deployment(array $names, string $slug, string $image): array
    {
        $labels = ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'asterisk-ari', 'app.kubernetes.io/instance' => $names['deployment'], 'utcp.dev/runtime-node' => $slug];
        $probe = ['exec' => ['command' => ['/usr/local/bin/utcp-asterisk-readiness']], 'periodSeconds' => 15, 'timeoutSeconds' => 5, 'failureThreshold' => 3];

        return ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => ['name' => $names['deployment'], 'namespace' => 'utcp-runtime', 'labels' => $labels], 'spec' => ['replicas' => 1, 'selector' => ['matchLabels' => ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'asterisk-ari', 'utcp.dev/runtime-node' => $slug]], 'template' => ['metadata' => ['labels' => [...$labels, 'utcp.io/network-role' => 'asterisk-ari']], 'spec' => ['automountServiceAccountToken' => false, 'terminationGracePeriodSeconds' => 30, 'securityContext' => ['runAsNonRoot' => true, 'seccompProfile' => ['type' => 'RuntimeDefault']], 'containers' => [['name' => 'asterisk', 'image' => $image, 'imagePullPolicy' => 'Always', 'ports' => [['name' => 'ari', 'containerPort' => 8088, 'protocol' => 'TCP'], ['name' => 'sip', 'containerPort' => 5060, 'protocol' => 'UDP']], 'envFrom' => [['secretRef' => ['name' => $names['secret']]]], 'volumeMounts' => [['name' => 'asterisk-local-config', 'mountPath' => '/opt/utcp-asterisk-local-config', 'readOnly' => true]], 'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['cpu' => '500m', 'memory' => '384Mi']], 'securityContext' => ['allowPrivilegeEscalation' => false, 'capabilities' => ['drop' => ['ALL']]], 'startupProbe' => [...$probe, 'initialDelaySeconds' => 15, 'failureThreshold' => 12], 'readinessProbe' => $probe, 'livenessProbe' => ['exec' => ['command' => ['/usr/sbin/asterisk', '-C', '/tmp/utcp-asterisk/asterisk.conf', '-rx', 'core show uptime']], 'initialDelaySeconds' => 20, 'periodSeconds' => 20, 'timeoutSeconds' => 5, 'failureThreshold' => 3]]], 'volumes' => [['name' => 'asterisk-local-config', 'configMap' => ['name' => 'asterisk-local-sip-fixtures', 'optional' => true]]]]]]];
    }

    /**
     * Return the canonical system-owned Deployment projection for a managed node.
     * Provisioning and existing-node reconciliation must use the same builder.
     *
     * @return array<string, mixed>
     */
    public function desiredDeployment(string $runtimeNodeId, string $slug): array
    {
        $image = (string) config('asterisk_ari.managed_image', '');
        if (! RuntimeExecutionContract::isQualifiedImmutableImageReference($image)) {
            throw new \InvalidArgumentException('managed Asterisk image configuration is invalid');
        }

        return $this->deployment(ManagedAsteriskResourceIdentity::names($slug, $runtimeNodeId), $slug, $image);
    }

    private function service(array $names, string $slug): array
    {
        $labels = ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'asterisk-ari', 'app.kubernetes.io/instance' => $names['service'], 'utcp.dev/runtime-node' => $slug];

        return ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => $names['service'], 'namespace' => 'utcp-runtime', 'labels' => $labels], 'spec' => ['type' => 'ClusterIP', 'selector' => ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'asterisk-ari', 'utcp.dev/runtime-node' => $slug], 'ports' => [['name' => 'ari', 'protocol' => 'TCP', 'port' => 8088, 'targetPort' => 'ari'], ['name' => 'sip', 'protocol' => 'UDP', 'port' => 5060, 'targetPort' => 'sip']]]];
    }

    private function failure(FailureClass $class, string $code, string $message): array
    {
        return ['status' => 'failed', 'failure_class' => $class->value, 'failure_code' => $code, 'failure_message' => $message];
    }
}
