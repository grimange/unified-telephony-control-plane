<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchCatalog;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Support\Facades\DB;

final class ManagedFreeSwitchProvisioningOperationHandler implements ManagedRuntimeProvisioningProvider
{
    public function __construct(
        private readonly KubernetesWorkloadClient $kubernetes,
        private readonly RuntimeRegistryService $registry,
        private readonly FreeSwitchCatalog $catalog,
    ) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    public function provisionOperation(array $operation, ?RuntimeAdapter $adapter): array
    {
        unset($adapter);
        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $payload = $operation['payload'] ?? [];
        $nodeId = (string) ($payload['runtime_node_id'] ?? '');
        $request = DB::table('runtime_provisioning_requests')->where('id', $payload['provisioning_request_id'] ?? null)->where('tenant_id', $tenantId)->first();
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        if ($request === null || $node === null || (string) $request->runtime_node_id !== $nodeId || $node->adapter_key !== $this->adapterKey() || $node->desired_state !== 'draft') {
            return $this->failure(FailureClass::InvalidRequest, 'provisioning_authority_invalid', 'managed FreeSWITCH provisioning authority is invalid');
        }
        $target = DB::table('deployment_targets')->where('id', $request->deployment_target_id)->where('tenant_id', $tenantId)->first();
        if ($target === null || $target->kind !== 'local_kubernetes') {
            return $this->failure(FailureClass::InvalidRequest, 'provisioning_target_invalid', 'managed provisioning target is invalid');
        }
        $image = (string) config('freeswitch_esl.managed_image', '');
        if (! self::isQualifiedImageReference($image)) {
            return $this->failure(FailureClass::InvalidRequest, 'managed_freeswitch_image_invalid', 'managed FreeSWITCH image configuration is invalid');
        }
        $context = ExecutionContext::system(tenantId: $tenantId, reason: 'managed FreeSWITCH provisioning', origin: 'runtime-engine');
        $names = ManagedRuntimeResourceIdentity::names('freeswitch', (string) $node->slug, $nodeId);
        try {
            $credential = $this->registry->ensureManagedCredential($context, $tenantId, $nodeId, $this->catalog->credentialType());
            $this->kubernetes->applySecret($this->secret($names['secret'], $credential), (string) $node->slug);
            $this->kubernetes->applyDeployment($this->deployment($names, (string) $node->slug, $image), (string) $node->slug);
            $this->kubernetes->applyService($this->service($names, (string) $node->slug), (string) $node->slug);
            $host = $names['service'].'.utcp-runtime.svc.cluster.local';
            foreach ([
                ['purpose' => 'control', 'transport' => 'tcp', 'host' => $host, 'port' => 8021, 'path' => null, 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
                ['purpose' => 'sip', 'transport' => 'udp', 'host' => $host, 'port' => 5060, 'path' => null, 'tls_mode' => 'disabled', 'priority' => 100, 'enabled' => true],
            ] as $endpoint) {
                $this->registry->ensureManagedEndpoint($context, $tenantId, $nodeId, $endpoint);
            }
            $this->registry->ensureManagedCapabilities($context, $tenantId, $nodeId, config('runtime_registry.adapter_keys.freeswitch-esl.supported_capabilities', []));
            $this->registry->ensureManagedWorkloadIdentity($context, $tenantId, $nodeId, ['kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => $names['deployment']]]);
            $this->registry->activateManagedNode($context, $tenantId, $nodeId);
        } catch (KubernetesWorkloadClientException $exception) {
            return $this->failure($exception->reason === 'ownership_conflict' ? FailureClass::Conflict : ($exception->reason === 'permission_denied' ? FailureClass::AuthorizationFailed : FailureClass::InternalError), 'provisioning_'.$exception->reason, 'managed FreeSWITCH Kubernetes provisioning failed');
        } catch (\Throwable) {
            return $this->failure(FailureClass::InternalError, 'provisioning_failed', 'managed FreeSWITCH provisioning failed');
        }

        return ['status' => 'completed', 'event_type' => 'runtime.node.provisioned', 'event_payload' => ['operation_type' => 'runtime.node.provision', 'runtime_node_id' => $nodeId, 'resources' => [
            ['kind' => 'Secret', 'name' => $names['secret']], ['kind' => 'Deployment', 'name' => $names['deployment']], ['kind' => 'Service', 'name' => $names['service']],
        ]]];
    }

    public function deprovisionOperation(array $operation, ?RuntimeAdapter $adapter): array
    {
        unset($adapter);
        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $nodeId = (string) data_get($operation, 'runtime_node_id', data_get($operation, 'payload.runtime_node_id', ''));
        $requestId = (string) data_get($operation, 'payload.provisioning_request_id', '');
        $request = DB::table('runtime_provisioning_requests')->where('id', $requestId)->where('tenant_id', $tenantId)->where('runtime_node_id', $nodeId)->first();
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        if ($request === null || $node === null || $node->desired_state !== 'retired') {
            return $this->failure(FailureClass::Conflict, 'deprovision_requires_retired_managed_node', 'managed deprovision requires a retired RuntimeNode');
        }
        $names = ManagedRuntimeResourceIdentity::names('freeswitch', (string) $node->slug, $nodeId);
        try {
            foreach ([['Deployment', $names['deployment']], ['Service', $names['service']], ['Secret', $names['secret']]] as [$kind, $name]) {
                $resource = $this->kubernetes->inspectResource($kind, $name);
                if ($resource !== null && ! $this->owned($resource, (string) $node->slug)) {
                    return $this->failure(FailureClass::Conflict, 'deprovision_ownership_conflict', 'managed Kubernetes resource ownership conflict');
                }
            }
            $this->kubernetes->deleteDeployment($names['deployment'], (string) $node->slug);
            $this->kubernetes->deleteService($names['service'], (string) $node->slug);
            $this->kubernetes->deleteSecret($names['secret'], (string) $node->slug);
        } catch (KubernetesWorkloadClientException $exception) {
            return $this->failure($exception->reason === 'ownership_conflict' ? FailureClass::Conflict : FailureClass::InternalError, 'deprovision_'.$exception->reason, 'managed FreeSWITCH deprovisioning failed');
        } catch (\Throwable) {
            return $this->failure(FailureClass::InternalError, 'deprovision_failed', 'managed FreeSWITCH deprovisioning failed');
        }

        return ['status' => 'completed', 'event_type' => 'runtime_deprovision.succeeded', 'event_payload' => ['operation_type' => 'runtime.node.deprovision', 'runtime_node_id' => $nodeId]];
    }

    public function desiredDeployment(string $runtimeNodeId, string $slug): array
    {
        $image = (string) config('freeswitch_esl.managed_image', '');
        if (! self::isQualifiedImageReference($image)) {
            throw new \InvalidArgumentException('managed FreeSWITCH image configuration is invalid');
        }

        return $this->deployment(ManagedRuntimeResourceIdentity::names('freeswitch', $slug, $runtimeNodeId), $slug, $image);
    }

    /** @param array<string,mixed> $credential */
    private function secret(string $name, array $credential): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => ['name' => $name, 'namespace' => 'utcp-runtime'], 'type' => 'Opaque', 'stringData' => ['UTCP_ESL_PASSWORD' => $credential['secret']]];
    }

    private function deployment(array $names, string $slug, string $image): array
    {
        $labels = ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'freeswitch-esl', 'app.kubernetes.io/instance' => $names['deployment'], 'utcp.dev/runtime-node' => $slug];
        $probe = ['exec' => ['command' => ['/usr/local/bin/utcp-freeswitch-healthcheck']], 'periodSeconds' => 10, 'timeoutSeconds' => 5, 'failureThreshold' => 6];
        $container = ['name' => 'freeswitch', 'image' => $image, 'imagePullPolicy' => 'Always', 'ports' => [['name' => 'sip', 'containerPort' => 5060, 'protocol' => 'UDP'], ['name' => 'esl', 'containerPort' => 8021, 'protocol' => 'TCP'], ['name' => 'rtp', 'containerPort' => 21000, 'protocol' => 'UDP']], 'envFrom' => [['secretRef' => ['name' => $names['secret']]]], 'volumeMounts' => [['name' => 'freeswitch-runtime', 'mountPath' => '/var/lib/freeswitch'], ['name' => 'freeswitch-run', 'mountPath' => '/var/run/freeswitch'], ['name' => 'freeswitch-log', 'mountPath' => '/var/log/freeswitch']], 'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['cpu' => '500m', 'memory' => '384Mi']], 'securityContext' => ['allowPrivilegeEscalation' => false, 'readOnlyRootFilesystem' => true, 'capabilities' => ['drop' => ['ALL']]], 'startupProbe' => [...$probe, 'failureThreshold' => 24], 'readinessProbe' => $probe, 'livenessProbe' => $probe];

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => $names['deployment'], 'namespace' => 'utcp-runtime', 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'freeswitch-esl', 'utcp.dev/runtime-node' => $slug]],
                'template' => [
                    'metadata' => ['labels' => [...$labels, 'utcp.io/network-role' => 'freeswitch-esl']],
                    'spec' => [
                        'automountServiceAccountToken' => false,
                        'terminationGracePeriodSeconds' => 30,
                        'securityContext' => ['runAsNonRoot' => true, 'runAsUser' => 1000, 'runAsGroup' => 1000, 'fsGroup' => 1000, 'seccompProfile' => ['type' => 'RuntimeDefault']],
                        'containers' => [$container],
                        'volumes' => [['name' => 'freeswitch-runtime', 'emptyDir' => new \stdClass], ['name' => 'freeswitch-run', 'emptyDir' => new \stdClass], ['name' => 'freeswitch-log', 'emptyDir' => new \stdClass]],
                    ],
                ],
            ],
        ];
    }

    private function service(array $names, string $slug): array
    {
        $labels = ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'freeswitch-esl', 'app.kubernetes.io/instance' => $names['service'], 'utcp.dev/runtime-node' => $slug];

        return ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => $names['service'], 'namespace' => 'utcp-runtime', 'labels' => $labels], 'spec' => ['type' => 'ClusterIP', 'selector' => ['app.kubernetes.io/part-of' => 'utcp', 'app.kubernetes.io/component' => 'freeswitch-esl', 'utcp.dev/runtime-node' => $slug], 'ports' => [['name' => 'sip', 'protocol' => 'UDP', 'port' => 5060, 'targetPort' => 'sip'], ['name' => 'esl', 'protocol' => 'TCP', 'port' => 8021, 'targetPort' => 'esl']]]];
    }

    private function owned(array $resource, string $slug): bool
    {
        $labels = data_get($resource, 'metadata.labels', []);

        return is_array($labels) && ($labels['app.kubernetes.io/part-of'] ?? '') === 'utcp' && ($labels['utcp.dev/runtime-node'] ?? '') === $slug;
    }

    private static function isQualifiedImageReference(string $image): bool
    {
        return (bool) preg_match('/^[^\/\s]+\/utcp\/freeswitch@sha256:[0-9a-f]{64}$/', $image);
    }

    private function failure(FailureClass $class, string $code, string $message): array
    {
        return ['status' => 'failed', 'failure_class' => $class->value, 'failure_code' => $code, 'failure_message' => $message];
    }
}
