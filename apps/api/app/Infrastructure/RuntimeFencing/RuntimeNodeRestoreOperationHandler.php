<?php

namespace App\Infrastructure\RuntimeFencing;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\Commands\RunsOnDisabledRuntimeNode;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\RuntimeRegistry\RuntimeRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RuntimeNodeRestoreOperationHandler implements RunsOnDisabledRuntimeNode, RuntimeOperationHandler
{
    public function __construct(
        private readonly KubernetesWorkloadClient $client,
        private readonly RuntimeNodeWorkloadIdentityResolver $identityResolver,
        private readonly KubernetesRuntimeWorkloadInspector $inspector,
        private readonly RuntimeRegistryService $registry,
    ) {}

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_node_restore', 'runtime.node.restore');
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
        if (! $this->payloadHasRequiredAuthority($payload)) {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_runtime_restore_payload', 'runtime restore operation payload is invalid');
        }

        $tenantId = (string) ($operation['tenant_id'] ?? '');
        $nodeId = (string) $payload['runtime_node_id'];
        if ((string) $payload['tenant_id'] !== $tenantId) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_restore_tenant_mismatch', 'runtime restore tenant authority is invalid');
        }

        $node = DB::table('runtime_nodes')
            ->where('id', $nodeId)
            ->where('tenant_id', $tenantId)
            ->first();
        if ($node === null || (string) ($operation['runtime_node_id'] ?? '') !== (string) $node->id) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_restore_node_mismatch', 'runtime restore target node is invalid');
        }
        if ((string) $node->desired_state !== 'disabled') {
            return $this->failure(FailureClass::Conflict, 'runtime_restore_authority_stale', 'runtime restore target is no longer disabled');
        }
        if ((int) $node->configuration_version !== (int) $payload['expected_runtime_node_configuration_version']) {
            return $this->failure(FailureClass::Conflict, 'runtime_restore_configuration_stale', 'runtime restore configuration version is stale');
        }

        $sourceFence = DB::table('runtime_operations')
            ->where('id', (string) $payload['source_fence_operation_id'])
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('operation_type', (string) config('telephony_domain.operation_types.runtime_fence'))
            ->where('status', 'succeeded')
            ->whereNotNull('completed_at')
            ->first();
        if ($sourceFence === null) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_restore_source_fence_missing', 'runtime restore source fence operation is missing or incomplete');
        }

        $sourcePayload = json_decode((string) $sourceFence->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! $this->sourceFenceMatchesPayload($sourcePayload, $payload, $nodeId, (string) $sourceFence->id)) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_restore_source_fence_invalid', 'runtime restore source fence evidence is invalid');
        }

        try {
            $identity = $this->identityResolver->resolve($node);
        } catch (RuntimeNodeWorkloadIdentityException) {
            return $this->failure(FailureClass::InvalidRequest, 'target_mismatch', 'runtime restore target workload identity is invalid');
        }
        if ($identity->namespace !== (string) $payload['workload_namespace'] || $identity->deployment !== (string) $payload['deployment']) {
            return $this->failure(FailureClass::InvalidRequest, 'target_mismatch', 'runtime restore workload identity does not match source fence evidence');
        }

        $targetReplicas = (int) $payload['target_replicas'];
        $provenance = $this->scaleProvenance($payload, (string) ($operation['id'] ?? ''));

        $deployment = $this->deployment($identity);
        if ($deployment instanceof KubernetesWorkloadClientException) {
            return $this->clientFailure($deployment);
        }
        if ($deployment === null || ! $this->inspector->isOwnedAsteriskDeployment($deployment, $identity, $node)) {
            return $this->failure(FailureClass::InvalidRequest, 'target_mismatch', 'runtime restore target did not match trusted RuntimeNode metadata');
        }

        $pods = $this->ownedPods($identity);
        if ($pods instanceof KubernetesWorkloadClientException) {
            return $this->clientFailure($pods);
        }
        if ($this->hasUnresolvedFenceOperation($tenantId, $nodeId, (string) $payload['source_fence_operation_id'])) {
            return $this->failure(FailureClass::Conflict, 'runtime_restore_fence_chain_unresolved', 'runtime restore found an unresolved fence operation for the RuntimeNode');
        }
        if ($this->hasActiveOpenConferenceBinding($tenantId, $nodeId)) {
            return $this->failure(FailureClass::Conflict, 'runtime_restore_active_binding_present', 'runtime restore target still owns an active open Conference binding');
        }

        if ($provenance === null) {
            $desiredReplicas = $this->inspector->desiredReplicas($deployment);
            if ($desiredReplicas !== 0) {
                return $this->failure(FailureClass::Conflict, 'runtime_restore_external_scale_detected', 'runtime restore found a workload already scaled by another authority');
            }
            if (count($pods) !== 0) {
                return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_waiting_for_old_pods', 'runtime restore is waiting for fenced Pods to terminate');
            }
            $scaleResult = $this->scaleToTarget($identity, $targetReplicas);
            if ($scaleResult instanceof KubernetesWorkloadClientException) {
                return $this->clientFailure($scaleResult);
            }
            $payload = $this->recordScaleProvenance((string) ($operation['id'] ?? ''), $payload, $operation);
            $provenance = $this->scaleProvenance($payload, (string) ($operation['id'] ?? ''));

            $deployment = $this->deployment($identity);
            if ($deployment instanceof KubernetesWorkloadClientException) {
                return $this->clientFailure($deployment);
            }
            $pods = $this->ownedPods($identity);
            if ($pods instanceof KubernetesWorkloadClientException) {
                return $this->clientFailure($pods);
            }
        }

        if ($deployment === null || ! $this->inspector->isOwnedAsteriskDeployment($deployment, $identity, $node)) {
            return $this->failure(FailureClass::InvalidRequest, 'target_mismatch', 'runtime restore target did not match trusted RuntimeNode metadata');
        }
        if (! $this->deploymentReady($deployment, $targetReplicas)) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_deployment_not_ready', 'runtime restore is waiting for Deployment replicas to become available');
        }
        if (! $this->podsReady($pods, $targetReplicas)) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_pods_not_ready', 'runtime restore is waiting for owned Pods to become Ready');
        }

        $startedAt = $this->restoreStartedAt($operation, $provenance);
        $lease = $this->currentLease($tenantId, $nodeId);
        if ($lease === null) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_listener_lease_missing', 'runtime restore is waiting for the listener lease');
        }
        $epoch = $this->newOpenEpoch($tenantId, $nodeId, $startedAt);
        if ($epoch === null) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_event_epoch_missing', 'runtime restore is waiting for a fresh runtime event epoch');
        }

        $node = DB::table('runtime_nodes')
            ->where('id', $nodeId)
            ->where('tenant_id', $tenantId)
            ->first();
        if ($node === null || (string) $node->observed_state !== 'ready') {
            return $this->failure(FailureClass::RuntimeUnavailable, 'runtime_restore_node_not_ready', 'runtime restore is waiting for RuntimeNode readiness projection');
        }
        if ($this->hasActiveOpenConferenceBinding($tenantId, $nodeId)) {
            return $this->failure(FailureClass::Conflict, 'runtime_restore_active_binding_present', 'runtime restore target still owns an active open Conference binding');
        }

        $context = ExecutionContext::system(
            tenantId: $tenantId,
            reason: 'runtime node restore completed',
            origin: 'runtime-engine',
        );
        $this->registry->completeRestorationActivation($context, $tenantId, $nodeId, (string) ($operation['id'] ?? ''));

        return [
            'status' => 'completed',
            'event_type' => 'runtime_node.restored',
            'event_payload' => [
                'operation_id' => (string) ($operation['id'] ?? ''),
                'operation_type' => $this->operationType(),
                'tenant_id' => $tenantId,
                'runtime_node_id' => $nodeId,
                'source_fence_operation_id' => (string) $payload['source_fence_operation_id'],
                'source_fence_generation' => (int) $payload['source_fence_generation'],
                'namespace' => (string) $payload['workload_namespace'],
                'deployment' => (string) $payload['deployment'],
                'requested_actor' => $payload['requesting_actor'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'target_replicas' => $targetReplicas,
                'scale_provenance' => $provenance,
                'new_pod_uids' => $this->podUids($pods),
                'new_event_epoch_id' => (string) $epoch->id,
                'ready_observation_timestamp' => $node->observed_at ?? $node->updated_at,
                'fresh_runtime_result' => 'passed',
                'completed_at' => now()->toJSON(),
            ],
        ];
    }

    private function payloadHasRequiredAuthority(mixed $payload): bool
    {
        return is_array($payload)
            && ($payload['requested_desired_state'] ?? null) === 'active'
            && isset(
                $payload['tenant_id'],
                $payload['runtime_node_id'],
                $payload['source_fence_operation_id'],
                $payload['source_fence_generation'],
                $payload['workload_namespace'],
                $payload['deployment'],
                $payload['target_replicas'],
                $payload['expected_runtime_node_configuration_version'],
            )
            && filter_var($payload['source_fence_generation'], FILTER_VALIDATE_INT) !== false
            && filter_var($payload['target_replicas'], FILTER_VALIDATE_INT) !== false
            && (int) $payload['target_replicas'] > 0;
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $payload
     */
    private function sourceFenceMatchesPayload(array $sourcePayload, array $payload, string $nodeId, string $sourceFenceOperationId): bool
    {
        $scale = data_get($sourcePayload, 'runtime_fence_provenance.scale_to_zero_requested');
        $disabled = data_get($sourcePayload, 'runtime_fence_provenance.runtime_node_disabled');

        return is_array($scale)
            && is_array($disabled)
            && ($disabled['by_operation'] ?? null) === true
            && (string) ($disabled['runtime_node_id'] ?? '') === $nodeId
            && (string) ($disabled['operation_id'] ?? '') === $sourceFenceOperationId
            && (string) ($scale['operation_id'] ?? '') === $sourceFenceOperationId
            && (int) ($sourcePayload['configuration_generation'] ?? 0) === (int) $payload['source_fence_generation']
            && (int) ($scale['pre_scale_replicas'] ?? 0) === (int) $payload['target_replicas']
            && (string) ($scale['namespace'] ?? '') === (string) $payload['workload_namespace']
            && (string) ($scale['deployment'] ?? '') === (string) $payload['deployment'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scaleProvenance(array $payload, string $operationId): ?array
    {
        $provenance = $payload['runtime_restore_provenance']['scale_to_target_requested'] ?? null;
        if (! is_array($provenance)
            || ($provenance['by_operation'] ?? null) !== true
            || (string) ($provenance['operation_id'] ?? '') !== $operationId
        ) {
            return null;
        }

        return $provenance;
    }

    private function deployment(RuntimeNodeWorkloadIdentity $identity): array|KubernetesWorkloadClientException|null
    {
        try {
            return $this->client->getDeployment($identity->namespace, $identity->deployment);
        } catch (KubernetesWorkloadClientException $exception) {
            return $exception;
        }
    }

    private function ownedPods(RuntimeNodeWorkloadIdentity $identity): array|KubernetesWorkloadClientException
    {
        try {
            return $this->client->listOwnedPods($identity->namespace, $identity);
        } catch (KubernetesWorkloadClientException $exception) {
            return $exception;
        }
    }

    private function scaleToTarget(RuntimeNodeWorkloadIdentity $identity, int $targetReplicas): ?KubernetesWorkloadClientException
    {
        try {
            $this->client->scaleDeployment($identity->namespace, $identity->deployment, $targetReplicas);

            return null;
        } catch (KubernetesWorkloadClientException $exception) {
            return $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function recordScaleProvenance(string $operationId, array $payload, array $operation): array
    {
        if ($operationId === '') {
            return $payload;
        }

        return DB::transaction(function () use ($operationId, $payload, $operation): array {
            $row = DB::table('runtime_operations')->where('id', $operationId)->lockForUpdate()->first();
            if ($row === null) {
                return $payload;
            }
            $currentPayload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($currentPayload)) {
                return $payload;
            }
            if ($this->scaleProvenance($currentPayload, $operationId) !== null) {
                return $currentPayload;
            }

            $currentPayload['runtime_restore_provenance']['scale_to_target_requested'] = [
                'by_operation' => true,
                'operation_id' => $operationId,
                'source_fence_operation_id' => (string) ($currentPayload['source_fence_operation_id'] ?? ''),
                'namespace' => (string) ($currentPayload['workload_namespace'] ?? ''),
                'deployment' => (string) ($currentPayload['deployment'] ?? ''),
                'pre_scale_replicas' => 0,
                'target_replicas' => (int) ($currentPayload['target_replicas'] ?? 0),
                'attempt_count' => (int) ($operation['attempt_count'] ?? 0),
                'requested_at' => now()->toJSON(),
            ];

            DB::table('runtime_operations')->where('id', $operationId)->update([
                'payload' => StableJson::encode(PayloadSafety::assertSafe($currentPayload)),
                'updated_at' => now(),
            ]);

            return $currentPayload;
        });
    }

    private function deploymentReady(array $deployment, int $targetReplicas): bool
    {
        return $this->inspector->desiredReplicas($deployment) === $targetReplicas
            && $this->inspector->statusReplicas($deployment) >= $targetReplicas
            && $this->inspector->availableReplicas($deployment) >= $targetReplicas;
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     */
    private function podsReady(array $pods, int $targetReplicas): bool
    {
        if (count($pods) !== $targetReplicas) {
            return false;
        }

        foreach ($pods as $pod) {
            if (! $this->podReady($pod)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $pod
     */
    private function podReady(array $pod): bool
    {
        $conditions = data_get($pod, 'status.conditions', []);
        if (! is_array($conditions)) {
            return false;
        }
        foreach ($conditions as $condition) {
            if (is_array($condition)
                && ($condition['type'] ?? null) === 'Ready'
                && ($condition['status'] ?? null) === 'True'
            ) {
                return true;
            }
        }

        return false;
    }

    private function restoreStartedAt(array $operation, ?array $provenance): CarbonImmutable
    {
        $value = $provenance['requested_at'] ?? $operation['started_at'] ?? $operation['created_at'] ?? null;
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return CarbonImmutable::now();
    }

    private function currentLease(string $tenantId, string $nodeId): ?object
    {
        return DB::table('runtime_listener_leases')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('listener_kind', (string) config('asterisk_ari.listener_kind', 'asterisk-ari-events'))
            ->where('status', 'claimed')
            ->where('lease_expires_at', '>', now())
            ->orderByDesc('updated_at')
            ->first();
    }

    private function newOpenEpoch(string $tenantId, string $nodeId, CarbonImmutable $startedAt): ?object
    {
        return DB::table('runtime_event_connection_epochs')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('adapter_key', (string) config('asterisk_ari.adapter_key', 'asterisk-ari'))
            ->where('status', 'open')
            ->where('opened_at', '>=', $startedAt)
            ->orderByDesc('opened_at')
            ->first();
    }

    private function hasActiveOpenConferenceBinding(string $tenantId, string $nodeId): bool
    {
        return DB::table('conference_runtime_bindings')
            ->join('conferences', 'conferences.id', '=', 'conference_runtime_bindings.conference_id')
            ->where('conference_runtime_bindings.tenant_id', $tenantId)
            ->where('conference_runtime_bindings.runtime_node_id', $nodeId)
            ->where('conference_runtime_bindings.status', 'active')
            ->where('conferences.desired_state', 'open')
            ->exists();
    }

    private function hasUnresolvedFenceOperation(string $tenantId, string $nodeId, string $sourceFenceOperationId): bool
    {
        return DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('operation_type', (string) config('telephony_domain.operation_types.runtime_fence'))
            ->where('id', '<>', $sourceFenceOperationId)
            ->whereIn('status', ['pending', 'leased', 'running', 'retry_scheduled'])
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     * @return list<string>
     */
    private function podUids(array $pods): array
    {
        return array_values(array_filter(array_map(
            static fn (array $pod): string => (string) data_get($pod, 'metadata.uid', ''),
            $pods,
        )));
    }

    /**
     * @return array{status:string,failure_class:string,failure_code:string,failure_message:string}
     */
    private function clientFailure(KubernetesWorkloadClientException $exception): array
    {
        return match ($exception->reason) {
            'unavailable_to_control', 'fence_in_progress' => $this->failure(FailureClass::RuntimeUnavailable, $exception->reason, $exception->getMessage()),
            'permission_denied' => $this->failure(FailureClass::AuthorizationFailed, $exception->reason, $exception->getMessage()),
            'target_mismatch' => $this->failure(FailureClass::InvalidRequest, $exception->reason, $exception->getMessage()),
            default => $this->failure(FailureClass::InternalError, 'runtime_restore_failed', $exception->getMessage()),
        };
    }

    /**
     * @return array{status:string,failure_class:string,failure_code:string,failure_message:string}
     */
    private function failure(FailureClass $class, string $code, string $message): array
    {
        return [
            'status' => 'failed',
            'failure_class' => $class->value,
            'failure_code' => $code,
            'failure_message' => $message,
        ];
    }
}
