<?php

namespace App\Infrastructure\RuntimeFencing;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\RuntimeEngine\Commands\RuntimeOperationHandler;
use App\TelephonyDomain\TelephonyDomainService;
use Illuminate\Support\Facades\DB;

final class RuntimeFenceOperationHandler implements RuntimeOperationHandler
{
    public function __construct(
        private readonly InfrastructureAdapterRegistry $adapters,
        private readonly TelephonyDomainService $domain,
    ) {}

    public function operationType(): string
    {
        return (string) config('telephony_domain.operation_types.runtime_fence', 'runtime.node.runtime.fence');
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
        if (! is_array($payload)
            || ! isset($payload['conference_id'], $payload['former_runtime_binding_id'], $payload['former_runtime_node_id'], $payload['configuration_generation'])
        ) {
            return $this->failure(FailureClass::InvalidRequest, 'invalid_runtime_fence_payload', 'runtime fence operation payload is invalid');
        }

        $node = DB::table('runtime_nodes')
            ->where('id', (string) $payload['former_runtime_node_id'])
            ->where('tenant_id', (string) ($operation['tenant_id'] ?? ''))
            ->first();
        if ($node === null || (string) ($operation['runtime_node_id'] ?? '') !== (string) $node->id) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_fence_node_mismatch', 'runtime fence target node is invalid');
        }

        if (! $this->operationAuthorityCurrent((string) ($operation['tenant_id'] ?? ''), $payload)) {
            return $this->failure(FailureClass::InvalidRequest, 'runtime_fence_authority_stale', 'runtime fence operation authority context is stale');
        }

        if (! $this->domain->hasDistinctEligibleReplacement(
            (string) ($operation['tenant_id'] ?? ''),
            (string) $payload['conference_id'],
            (string) $payload['former_runtime_node_id'],
        )) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'no_replacement_available', 'no distinct eligible replacement runtime node is available for runtime fencing');
        }

        $infra = $this->adapters->get('kubernetes');
        if ($infra === null) {
            return $this->failure(FailureClass::RuntimeUnavailable, 'unavailable_to_control', 'infrastructure fencing adapter is unavailable');
        }

        $result = $infra->fence($node, $payload);
        $status = (string) ($result['status'] ?? 'failed');
        if (in_array($status, ['fenced', 'already_fenced'], true)) {
            return [
                'status' => 'completed',
                'event_type' => 'conference.runtime_fence_terminated',
                'event_payload' => array_merge([
                    'operation_id' => (string) ($operation['id'] ?? ''),
                    'operation_type' => $this->operationType(),
                    'conference_id' => (string) $payload['conference_id'],
                    'former_runtime_binding_id' => (string) $payload['former_runtime_binding_id'],
                    'former_runtime_node_id' => (string) $payload['former_runtime_node_id'],
                    'configuration_generation' => (int) $payload['configuration_generation'],
                    'fence_result' => $status,
                    'infrastructure_adapter' => 'kubernetes',
                ], is_array($result['details'] ?? null) ? $result['details'] : []),
            ];
        }

        return match ($status) {
            'fence_in_progress' => $this->failure(FailureClass::RuntimeUnavailable, 'fence_in_progress', 'runtime fence termination is still in progress'),
            'target_recovered' => $this->failure(FailureClass::RuntimeUnavailable, 'target_recovered', 'runtime node recovered before fencing mutation'),
            'no_replacement_available' => $this->failure(FailureClass::RuntimeUnavailable, 'no_replacement_available', 'no distinct eligible replacement runtime node is available for runtime fencing'),
            'unavailable_to_control' => $this->failure(FailureClass::RuntimeUnavailable, 'unavailable_to_control', 'infrastructure control plane is unavailable'),
            'permission_denied' => $this->failure(FailureClass::AuthorizationFailed, 'permission_denied', 'infrastructure control plane denied the fencing mutation'),
            'target_mismatch' => $this->failure(FailureClass::InvalidRequest, 'target_mismatch', 'runtime fence target did not match trusted RuntimeNode metadata'),
            default => $this->failure(FailureClass::InternalError, 'runtime_fence_failed', 'runtime fence operation failed'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function operationAuthorityCurrent(string $tenantId, array $payload): bool
    {
        $conference = DB::table('conferences')
            ->where('id', (string) $payload['conference_id'])
            ->where('tenant_id', $tenantId)
            ->first();
        if ($conference === null
            || (string) $conference->desired_state !== 'open'
            || (string) $conference->runtime_node_id !== (string) $payload['former_runtime_node_id']
            || (int) $conference->configuration_generation !== (int) $payload['configuration_generation']
        ) {
            return false;
        }

        $binding = DB::table('conference_runtime_bindings')
            ->where('id', (string) $payload['former_runtime_binding_id'])
            ->where('tenant_id', $tenantId)
            ->where('conference_id', (string) $payload['conference_id'])
            ->where('runtime_node_id', (string) $payload['former_runtime_node_id'])
            ->where('status', 'active')
            ->first();

        return $binding !== null;
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
