<?php

namespace App\RuntimeRegistry;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\OperationStatus;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\Identity\IdentityContext;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityValidator;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeRegistry\RuntimeNodeWorkloadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RuntimeRegistryService
{
    public function __construct(
        private readonly RuntimeRegistryCatalog $catalog,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
        private readonly RuntimeOperationRepository $operations,
        private readonly ReconciliationRepository $reconciliation,
        private readonly RuntimeNodeDrainRepository $drains,
        private readonly RuntimeNodeWorkloadService $workload,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createNode(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $input['slug'] = $this->normalizeSlug($input['slug']);
        $fingerprint = ['tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $input['slug'], 'runtime_family' => $input['runtime_family'], 'adapter_key' => $input['adapter_key']];
        if ($key !== null && ($existing = $this->beginIdempotent('runtime_registry.nodes.create', $key, $fingerprint)) !== null) {
            return $existing;
        }

        try {
            $result = DB::transaction(function () use ($request, $tenantId, $input): array {
                $this->catalog->assertAdapterForFamily($input['runtime_family'], $input['adapter_key']);
                $id = RuntimeRegistryIds::new();
                $labels = $this->validatedLabels($input['labels'] ?? []);
                DB::table('runtime_nodes')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name' => $input['name'],
                    'slug' => $input['slug'],
                    'runtime_family' => $input['runtime_family'],
                    'adapter_key' => $input['adapter_key'],
                    'desired_state' => 'draft',
                    'observed_state' => 'unobserved',
                    'configuration_version' => 1,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'placement_region' => $input['placement_region'] ?? null,
                    'placement_zone' => $input['placement_zone'] ?? null,
                    'placement_priority' => $input['placement_priority'] ?? 100,
                    'capacity_weight' => $input['capacity_weight'] ?? 100,
                    'labels' => $labels === [] ? null : json_encode($labels, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->emit($request, $tenantId, $id, 'runtime_node.created', [
                    'runtime_family' => $input['runtime_family'],
                    'adapter_key' => $input['adapter_key'],
                    'desired_state' => 'draft',
                    'configuration_version' => 1,
                ]);

                return ['runtime_node' => $this->node($id, $tenantId)];
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
                throw new InvalidArgumentException('A runtime with this name or identifier already exists.', 0, $exception);
            }

            throw $exception;
        }

        if ($key !== null) {
            $this->idempotency->complete('runtime_registry.nodes.create', $key, $result);
        }

        return $result;
    }

    public function assertManualMutationAllowed(string $tenantId, string $nodeId): void
    {
        if (DB::table('runtime_provisioning_requests')->where('tenant_id', $tenantId)->where('runtime_node_id', $nodeId)->exists()) {
            throw new InvalidArgumentException('This runtime is managed by UTCP. Its generated runtime configuration cannot be edited manually.');
        }
    }

    public function isManagedNode(string $tenantId, string $nodeId): bool
    {
        return DB::table('runtime_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNodes(string $tenantId): array
    {
        return DB::table('runtime_nodes')->where('tenant_id', $tenantId)->orderBy('name')->get()
            ->map(fn (object $node): array => $this->serializeNode($node))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function node(string $nodeId, string $tenantId): array
    {
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');

        return $this->serializeNode($node);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateNode(Request $request, string $tenantId, string $nodeId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $input): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $runtimeFamily = $input['runtime_family'] ?? $node->runtime_family;
            $adapterKey = $input['adapter_key'] ?? $node->adapter_key;
            $this->catalog->assertAdapterForFamily($runtimeFamily, $adapterKey);
            if ($runtimeFamily !== $node->runtime_family || $adapterKey !== $node->adapter_key) {
                $this->assertIdentityChangeAllowed($node, $tenantId);
            }
            $labels = array_key_exists('labels', $input) ? $this->validatedLabels($input['labels'] ?? []) : null;
            $version = ((int) $node->configuration_version) + 1;
            $update = [
                'name' => $input['name'] ?? $node->name,
                'runtime_family' => $runtimeFamily,
                'adapter_key' => $adapterKey,
                'placement_region' => $input['placement_region'] ?? $node->placement_region,
                'placement_zone' => $input['placement_zone'] ?? $node->placement_zone,
                'placement_priority' => $input['placement_priority'] ?? $node->placement_priority,
                'capacity_weight' => $input['capacity_weight'] ?? $node->capacity_weight,
                'configuration_version' => $version,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ];
            if (array_key_exists('labels', $input)) {
                $update['labels'] = $labels === [] ? null : json_encode($labels, JSON_THROW_ON_ERROR);
            }
            DB::table('runtime_nodes')->where('id', $nodeId)->update($update);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.updated', ['configuration_version' => $version]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function changeDesiredState(Request $request, string $tenantId, string $nodeId, string $desiredState, ?string $reason = null): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $desiredState, $reason): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($desiredState === 'drained') {
                throw new InvalidArgumentException('Runtime node can only become drained after the drain coordinator proves zero remaining work.');
            }
            if ($node->desired_state === 'drained' && $desiredState === 'retired') {
                throw new InvalidArgumentException('A drained runtime node must be decommissioned through the canonical decommission action.');
            }
            $this->catalog->assertDesiredTransition($node->desired_state, $desiredState);
            if ($desiredState === 'retired' && $this->activeTelephonyWorkCount($tenantId, $nodeId) > 0) {
                throw new InvalidArgumentException('Runtime node cannot be retired while active runtime bindings exist.');
            }
            $context = null;
            if ($node->desired_state === 'disabled' && $desiredState === 'retired') {
                $context = IdentityContext::fromRequest($request, $tenantId);
            }
            if ($node->desired_state === 'disabled' && $desiredState === 'active' && ($sourceFence = $this->latestSuccessfulFenceForDisabledNode($tenantId, $nodeId)) !== null) {
                $context = $context ?? IdentityContext::fromRequest($request, $tenantId);
                $operationId = $this->requestRestoreOperation($context, $node, $sourceFence, $request->user()?->getAuthIdentifier(), $reason);

                return [
                    'runtime_node' => $this->node($nodeId, $tenantId),
                    'runtime_operation' => $this->serializeOperation($operationId),
                ];
            }
            if ($context !== null) {
                $this->retireActiveCredentialsForTerminalRetirement($context, $tenantId, $nodeId, 'disabled_to_retired');
            }
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_state' => $desiredState,
                'configuration_version' => ((int) $node->configuration_version) + 1,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            if ($desiredState === 'draining') {
                $this->drains->start($tenantId, $nodeId, $this->activeTelephonyWorkCount($tenantId, $nodeId));
                $this->reconciliation->ensureTarget($tenantId, 'runtime_node_drain', $nodeId, (int) $node->configuration_version + 1, 0);
            } elseif ($node->desired_state === 'draining' && $desiredState !== 'draining') {
                $this->drains->cancel($tenantId, $nodeId);
                $this->reconciliation->wakeTarget($tenantId, 'runtime_node_drain', $nodeId, (int) $node->configuration_version + 1, 0);
            }
            if ($desiredState === 'retired') {
                DB::table('runtime_reconciliation_states')
                    ->whereIn('target_type', ['runtime_node', 'runtime_node_drain'])
                    ->where('target_id', $nodeId)
                    ->delete();
                DB::table('runtime_listener_leases')
                    ->whereIn('event_source_id', DB::table('event_sources')->where('runtime_node_id', $nodeId)->select('id'))
                    ->where('status', 'claimed')
                    ->update([
                        'status' => 'released',
                        'released_at' => now(),
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
                $this->scheduleManagedDeprovisionIfApplicable(
                    $context ?? ExecutionContext::system(tenantId: $tenantId, reason: 'managed runtime retired', origin: 'runtime-registry'),
                    $tenantId,
                    $nodeId,
                );
            }
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.desired_state_changed', ['from' => $node->desired_state, 'to' => $desiredState]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    public function completeDrain(ExecutionContext $context, string $tenantId, string $nodeId): bool
    {
        return DB::transaction(function () use ($context, $tenantId, $nodeId): bool {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($node->desired_state === 'drained') {
                return true;
            }
            if ($node->desired_state !== 'draining' || $this->activeTelephonyWorkCount($tenantId, $nodeId) !== 0) {
                return false;
            }
            $this->catalog->assertDesiredTransition('draining', 'drained');
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_state' => 'drained',
                'configuration_version' => ((int) $node->configuration_version) + 1,
                'updated_by' => null,
                'updated_at' => now(),
            ]);
            DB::table('runtime_node_drains')->where('tenant_id', $tenantId)->where('runtime_node_id', $nodeId)->update([
                'status' => 'completed',
                'remaining_work' => 0,
                'last_evaluated_at' => now(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.desired_state_changed', [
                'from' => 'draining',
                'to' => 'drained',
                'reason' => 'canonical_runtime_bindings_empty',
            ]);

            return true;
        });
    }

    /** Internal managed-provisioning seam. Plaintext is returned only to the infrastructure worker. */
    public function ensureManagedCredential(ExecutionContext $context, string $tenantId, string $nodeId, string $type): array
    {
        return DB::transaction(function () use ($context, $tenantId, $nodeId, $type): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $credential = DB::table('runtime_node_credentials')
                ->where('runtime_node_id', $nodeId)->where('credential_type', $type)->where('status', 'active')
                ->orderByDesc('version')->lockForUpdate()->first();
            if ($credential !== null) {
                return [
                    'identifier' => (string) $credential->identifier,
                    'secret' => Crypt::decryptString((string) $credential->encrypted_secret),
                    'version' => (int) $credential->version,
                ];
            }

            $identifier = 'utcp_'.Str::lower(Str::random(24));
            $secret = Str::random(64);
            $version = ((int) DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $type)->max('version')) + 1;
            $id = RuntimeRegistryIds::new();
            DB::table('runtime_node_credentials')->insert([
                'id' => $id, 'runtime_node_id' => $nodeId, 'credential_type' => $type,
                'identifier' => $identifier, 'encrypted_secret' => Crypt::encryptString($secret),
                'secret_fingerprint' => $this->fingerprint($secret), 'version' => $version,
                'status' => 'active', 'rotated_at' => now(), 'expires_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->bumpNodeContext($context, $tenantId, $nodeId);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.credential_rotated', [
                'change' => 'created', 'registry_item_id' => $id, 'type' => $type, 'version' => $version,
            ]);

            return ['identifier' => $identifier, 'secret' => $secret, 'version' => $version];
        });
    }

    /** @param array<string,mixed> $endpoint */
    public function ensureManagedEndpoint(ExecutionContext $context, string $tenantId, string $nodeId, array $endpoint): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $endpoint): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $this->catalog->assertEndpoint($endpoint['purpose'], $endpoint['transport'], (int) $endpoint['port'], $endpoint['tls_mode'], $endpoint['path']);
            $existing = DB::table('runtime_node_endpoints')->where('runtime_node_id', $nodeId)->where('purpose', $endpoint['purpose'])->first();
            if ($existing !== null && $existing->host === $endpoint['host'] && (int) $existing->port === (int) $endpoint['port'] && $existing->path === $endpoint['path'] && $existing->transport === $endpoint['transport']) {
                return;
            }
            if ($existing !== null) {
                DB::table('runtime_node_endpoints')->where('id', $existing->id)->update([...$endpoint, 'updated_at' => now()]);
            } else {
                DB::table('runtime_node_endpoints')->insert(['id' => RuntimeRegistryIds::new(), 'runtime_node_id' => $nodeId, ...$endpoint, 'metadata' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->bumpNodeContext($context, $tenantId, $nodeId);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'managed_provisioning', 'purpose' => $endpoint['purpose']]);
        });
    }

    /** @param list<string> $capabilities */
    public function ensureManagedCapabilities(ExecutionContext $context, string $tenantId, string $nodeId, array $capabilities): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $capabilities): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->catalog->assertCapabilitiesForAdapter((string) $node->adapter_key, $capabilities);
            $current = DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->orderBy('capability_key')->pluck('capability_key')->all();
            sort($capabilities);
            if ($current === $capabilities) {
                return;
            }
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->delete();
            foreach ($capabilities as $capability) {
                DB::table('runtime_node_capabilities')->insert(['id' => RuntimeRegistryIds::new(), 'runtime_node_id' => $nodeId, 'capability_key' => $capability, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->bumpNodeContext($context, $tenantId, $nodeId);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.capabilities_changed', ['capabilities' => $capabilities]);
        });
    }

    /** @param array<string,mixed> $labels */
    public function ensureManagedWorkloadIdentity(ExecutionContext $context, string $tenantId, string $nodeId, array $labels): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $labels): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $current = $node->labels === null ? [] : json_decode((string) $node->labels, true, 512, JSON_THROW_ON_ERROR);
            $merged = array_replace($current, $labels);
            if ($merged === $current) {
                return;
            }
            DB::table('runtime_nodes')->where('id', $nodeId)->update(['labels' => json_encode($merged, JSON_THROW_ON_ERROR), 'configuration_version' => ((int) $node->configuration_version) + 1, 'updated_by' => null, 'updated_at' => now()]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.updated', ['configuration_version' => ((int) $node->configuration_version) + 1, 'managed_workload_identity' => true]);
        });
    }

    public function ensureManagedExecutionImage(ExecutionContext $context, string $tenantId, string $nodeId, string $image): void
    {
        if (RuntimeExecutionContract::digest($image) === null) {
            throw new InvalidArgumentException('Managed runtime execution images must be qualified by an immutable sha256 digest.');
        }

        DB::transaction(function () use ($context, $tenantId, $nodeId, $image): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ((string) ($node->desired_execution_image ?? '') === $image) {
                return;
            }
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_execution_image' => $image,
                'updated_by' => null,
                'updated_at' => now(),
            ]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.execution_image_changed', [
                'desired_execution_image' => $image,
            ]);
        });
    }

    public function activateManagedNode(ExecutionContext $context, string $tenantId, string $nodeId): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->catalog->assertDesiredTransition((string) $node->desired_state, 'active');
            DB::table('runtime_nodes')->where('id', $nodeId)->update(['desired_state' => 'active', 'configuration_version' => ((int) $node->configuration_version) + 1, 'updated_by' => null, 'updated_at' => now()]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.desired_state_changed', ['from' => $node->desired_state, 'to' => 'active']);
        });
    }

    /**
     * Request the durable, system-executed decommission of a drained node.
     *
     * @return array{runtime_node: array<string,mixed>, runtime_operation: array<string,mixed>}
     */
    public function requestDecommission(Request $request, string $tenantId, string $nodeId, ?IdempotencyKey $key = null, ?string $reason = null): array
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $operationType = (string) config('telephony_domain.operation_types.runtime_node_decommission', 'runtime.node.decommission');

        return DB::transaction(function () use ($context, $tenantId, $nodeId, $key, $reason, $operationType): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($node->desired_state !== 'drained') {
                throw new InvalidArgumentException('Only a drained runtime node can be decommissioned.');
            }
            if ($this->activeTelephonyWorkCount($tenantId, $nodeId) > 0) {
                throw new InvalidArgumentException('Runtime node cannot be decommissioned while active runtime bindings exist.');
            }

            $existing = DB::table('runtime_operations')
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $nodeId)
                ->where('operation_type', $operationType)
                ->whereIn('status', [
                    OperationStatus::Pending->value,
                    OperationStatus::Leased->value,
                    OperationStatus::Running->value,
                    OperationStatus::RetryScheduled->value,
                ])
                ->orderByDesc('created_at')
                ->first();
            $operationId = $existing?->id;

            if ($operationId === null) {
                $operationId = $this->operations->create(
                    $operationType,
                    'runtime_node',
                    $nodeId,
                    [
                        'tenant_id' => $tenantId,
                        'runtime_node_id' => $nodeId,
                        'requested_desired_state' => 'retired',
                        'reason' => $reason,
                        'expected_configuration_version' => (int) $node->configuration_version,
                        'physical_runtime_destroyed' => false,
                    ],
                    $context,
                    idempotencyKey: $key,
                    maxAttempts: (int) config('telephony_domain.operation_max_attempts.runtime_node_decommission', 8),
                    runtimeNodeId: $nodeId,
                );
                $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.decommission_requested', [
                    'operation_id' => $operationId,
                    'desired_state' => 'drained',
                    'reason' => $reason,
                ]);
            }

            return [
                'runtime_node' => $this->node($nodeId, $tenantId),
                'runtime_operation' => $this->serializeOperation($operationId),
            ];
        });
    }

    /**
     * Complete decommission under the canonical desired-state authority.
     * The caller must already own a running runtime operation; the node lock
     * prevents reactivation from racing with credential retirement.
     */
    public function completeDecommission(ExecutionContext $context, string $tenantId, string $nodeId, string $operationId): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $operationId): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($node->desired_state === 'retired') {
                return;
            }
            if ($node->desired_state !== 'drained') {
                throw new InvalidArgumentException('Runtime node decommission authority is stale because the node is no longer drained.');
            }
            if ($this->activeTelephonyWorkCount($tenantId, $nodeId) > 0) {
                throw new InvalidArgumentException('Runtime node cannot be retired while active runtime bindings exist.');
            }

            $operation = DB::table('runtime_operations')
                ->where('id', $operationId)
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $nodeId)
                ->where('operation_type', (string) config('telephony_domain.operation_types.runtime_node_decommission', 'runtime.node.decommission'))
                ->lockForUpdate()
                ->first();
            if ($operation === null || ! in_array((string) $operation->status, [OperationStatus::Leased->value, OperationStatus::Running->value], true)) {
                throw new InvalidArgumentException('Runtime node decommission operation is not current.');
            }

            $this->retireActiveCredentialsForTerminalRetirement($context, $tenantId, $nodeId, 'decommission_retired', $operationId);

            DB::table('runtime_reconciliation_states')
                ->whereIn('target_type', ['runtime_node', 'runtime_node_drain'])
                ->where('target_id', $nodeId)
                ->delete();
            DB::table('runtime_listener_leases')
                ->whereIn('event_source_id', DB::table('event_sources')->where('runtime_node_id', $nodeId)->select('id'))
                ->where('status', 'claimed')
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            $this->catalog->assertDesiredTransition('drained', 'retired');
            DB::table('runtime_nodes')->where('id', $nodeId)->update([
                'desired_state' => 'retired',
                'configuration_version' => ((int) $node->configuration_version) + 1,
                'updated_by' => null,
                'updated_at' => now(),
            ]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.desired_state_changed', [
                'from' => 'drained',
                'to' => 'retired',
                'reason' => 'decommission_completed',
                'operation_id' => $operationId,
                'physical_runtime_destroyed' => false,
            ]);
            $this->scheduleManagedDeprovisionIfApplicable($context, $tenantId, $nodeId);
        });
    }

    private function scheduleManagedDeprovisionIfApplicable(ExecutionContext $context, string $tenantId, string $nodeId): void
    {
        $request = DB::table('runtime_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->get();
        if ($request->count() !== 1) {
            return;
        }

        $provisioningRequest = $request->first();
        $operationType = (string) config('telephony_domain.operation_types.runtime_node_deprovision', 'runtime.node.deprovision');
        $operationId = $this->operations->create(
            operationType: $operationType,
            aggregateType: 'runtime_provisioning_request',
            aggregateId: (string) $provisioningRequest->id,
            payload: [
                'provisioning_request_id' => (string) $provisioningRequest->id,
                'runtime_node_id' => $nodeId,
                'adapter_key' => (string) $provisioningRequest->adapter_key,
            ],
            context: $context,
            idempotencyKey: IdempotencyKey::fromString('runtime-node-deprovision:'.$provisioningRequest->id),
            maxAttempts: (int) config('telephony_domain.operation_max_attempts.runtime_node_deprovision', 8),
            runtimeNodeId: $nodeId,
        );
        $this->emitContext($context, $tenantId, $nodeId, 'runtime_deprovision.requested', [
            'operation_id' => $operationId,
            'provisioning_request_id' => (string) $provisioningRequest->id,
            'desired_state' => 'retired',
        ]);
    }

    public function disableAfterSuccessfulFence(ExecutionContext $context, string $tenantId, string $nodeId, string $sourceFenceOperationId): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $sourceFenceOperationId): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($node->desired_state !== 'disabled') {
                $this->catalog->assertDesiredTransition($node->desired_state, 'disabled');
                DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update([
                    'desired_state' => 'disabled',
                    'configuration_version' => ((int) $node->configuration_version) + 1,
                    'updated_by' => null,
                    'updated_at' => now(),
                ]);
                $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.desired_state_changed', [
                    'from' => $node->desired_state,
                    'to' => 'disabled',
                    'source_runtime_operation_id' => $sourceFenceOperationId,
                ]);
            }

            $source = DB::table('runtime_operations')
                ->where('id', $sourceFenceOperationId)
                ->where('tenant_id', $tenantId)
                ->where('runtime_node_id', $nodeId)
                ->lockForUpdate()
                ->first();
            if ($source !== null) {
                $payload = json_decode((string) $source->payload, true, 512, JSON_THROW_ON_ERROR);
                $payload['runtime_fence_provenance']['runtime_node_disabled'] = [
                    'by_operation' => true,
                    'operation_id' => $sourceFenceOperationId,
                    'runtime_node_id' => $nodeId,
                    'disabled_at' => now()->toISOString(),
                ];
                DB::table('runtime_operations')->where('id', $sourceFenceOperationId)->update([
                    'payload' => StableJson::encode(PayloadSafety::assertSafe($payload)),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function completeRestorationActivation(ExecutionContext $context, string $tenantId, string $nodeId, string $restoreOperationId): void
    {
        DB::transaction(function () use ($context, $tenantId, $nodeId, $restoreOperationId): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            if ($node->desired_state === 'active') {
                return;
            }
            $this->catalog->assertDesiredTransition($node->desired_state, 'active');
            DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update([
                'desired_state' => 'active',
                'configuration_version' => ((int) $node->configuration_version) + 1,
                'updated_by' => null,
                'updated_at' => now(),
            ]);
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.desired_state_changed', [
                'from' => $node->desired_state,
                'to' => 'active',
                'source_runtime_operation_id' => $restoreOperationId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addEndpoint(Request $request, string $tenantId, string $nodeId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $input): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $this->catalog->assertEndpoint($input['purpose'], $input['transport'], (int) $input['port'], $input['tls_mode'] ?? 'disabled', $input['path'] ?? null);
            $id = RuntimeRegistryIds::new();
            DB::table('runtime_node_endpoints')->insert([
                'id' => $id,
                'runtime_node_id' => $nodeId,
                'purpose' => $input['purpose'],
                'transport' => $input['transport'],
                'host' => $this->validateHost($input['host']),
                'port' => (int) $input['port'],
                'path' => $input['path'] ?? null,
                'tls_mode' => $input['tls_mode'] ?? 'disabled',
                'priority' => $input['priority'] ?? 100,
                'enabled' => $input['enabled'] ?? true,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'added', 'endpoint_id' => $id, 'purpose' => $input['purpose']]);

            return ['endpoint' => $this->endpoint($id, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateEndpoint(Request $request, string $tenantId, string $nodeId, string $endpointId, array $input): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $endpointId, $input): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $endpoint = DB::table('runtime_node_endpoints')->where('id', $endpointId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
            abort_unless($endpoint !== null, 404, 'Endpoint not found.');
            $purpose = $input['purpose'] ?? $endpoint->purpose;
            $transport = $input['transport'] ?? $endpoint->transport;
            $port = (int) ($input['port'] ?? $endpoint->port);
            $tlsMode = $input['tls_mode'] ?? $endpoint->tls_mode;
            $path = $input['path'] ?? $endpoint->path;
            $this->catalog->assertEndpoint($purpose, $transport, $port, $tlsMode, $path);
            DB::table('runtime_node_endpoints')->where('id', $endpointId)->update([
                'purpose' => $purpose,
                'transport' => $transport,
                'host' => array_key_exists('host', $input) ? $this->validateHost($input['host']) : $endpoint->host,
                'port' => $port,
                'path' => $path,
                'tls_mode' => $tlsMode,
                'priority' => $input['priority'] ?? $endpoint->priority,
                'enabled' => $input['enabled'] ?? $endpoint->enabled,
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'updated', 'endpoint_id' => $endpointId, 'purpose' => $purpose]);

            return ['endpoint' => $this->endpoint($endpointId, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    public function removeEndpoint(Request $request, string $tenantId, string $nodeId, string $endpointId): void
    {
        DB::transaction(function () use ($request, $tenantId, $nodeId, $endpointId): void {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $deleted = DB::table('runtime_node_endpoints')->where('id', $endpointId)->where('runtime_node_id', $nodeId)->delete();
            abort_unless($deleted === 1, 404, 'Endpoint not found.');
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.endpoints_changed', ['change' => 'removed', 'endpoint_id' => $endpointId]);
        });
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    public function setCapabilities(Request $request, string $tenantId, string $nodeId, array $capabilities): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $capabilities): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $capabilities = array_values(array_unique($capabilities));
            sort($capabilities);
            $this->catalog->assertCapabilitiesForAdapter((string) $node->adapter_key, $capabilities);
            DB::table('runtime_node_capabilities')->where('runtime_node_id', $nodeId)->delete();
            foreach ($capabilities as $capability) {
                DB::table('runtime_node_capabilities')->insert([
                    'id' => RuntimeRegistryIds::new(),
                    'runtime_node_id' => $nodeId,
                    'capability_key' => $capability,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.capabilities_changed', ['capabilities' => $capabilities]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCredential(Request $request, string $tenantId, string $nodeId, array $input, ?IdempotencyKey $key = null): array
    {
        return $this->writeCredential($request, $tenantId, $nodeId, null, $input, $key, 'runtime_registry.credentials.create');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rotateCredential(Request $request, string $tenantId, string $nodeId, string $credentialId, array $input, ?IdempotencyKey $key = null): array
    {
        return $this->writeCredential($request, $tenantId, $nodeId, $credentialId, $input, $key, 'runtime_registry.credentials.rotate');
    }

    /**
     * @return array<string, mixed>
     */
    public function retireCredential(Request $request, string $tenantId, string $nodeId, string $credentialId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $nodeId, $credentialId): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $credential = DB::table('runtime_node_credentials')->where('id', $credentialId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
            abort_unless($credential !== null, 404, 'Credential not found.');
            if ($credential->status === 'active') {
                $activeCount = DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $credential->credential_type)->where('status', 'active')->count();
                if ($activeCount <= 1) {
                    throw new InvalidArgumentException('Cannot retire the last active credential of this type.');
                }
            }
            $updated = DB::table('runtime_node_credentials')->where('id', $credentialId)->where('runtime_node_id', $nodeId)->update(['status' => 'retired', 'updated_at' => now()]);
            abort_unless($updated === 1, 404, 'Credential not found.');
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.credential_retired', ['change' => 'retired', 'registry_item_id' => $credentialId, 'type' => $credential->credential_type]);

            return ['runtime_node' => $this->node($nodeId, $tenantId)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function writeCredential(Request $request, string $tenantId, string $nodeId, ?string $previousCredentialId, array $input, ?IdempotencyKey $key, string $scope): array
    {
        $type = $this->slugLike($input['credential_type']);
        $fingerprint = [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeId,
            'previous_id' => $previousCredentialId,
            'type' => $type,
            'identifier' => $input['identifier'] ?? null,
            'fingerprint_sha256' => $this->fingerprint($input['secret']),
        ];
        if ($key !== null && ($existing = $this->beginIdempotent($scope, $key, $fingerprint)) !== null) {
            return $existing;
        }

        $result = DB::transaction(function () use ($request, $tenantId, $nodeId, $previousCredentialId, $input, $type): array {
            $node = $this->nodeForUpdate($nodeId, $tenantId);
            $this->assertNodeNotRetired($node);
            $secret = trim((string) $input['secret']);
            if ($secret === '') {
                throw new InvalidArgumentException('Credential secret is required.');
            }
            if ($previousCredentialId !== null) {
                $previous = DB::table('runtime_node_credentials')->where('id', $previousCredentialId)->where('runtime_node_id', $nodeId)->lockForUpdate()->first();
                abort_unless($previous !== null, 404, 'Credential not found.');
            }
            $version = ((int) DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $type)->max('version')) + 1;
            $id = RuntimeRegistryIds::new();
            DB::table('runtime_node_credentials')->where('runtime_node_id', $nodeId)->where('credential_type', $type)->where('status', 'active')->update(['status' => 'retired', 'updated_at' => now()]);
            DB::table('runtime_node_credentials')->insert([
                'id' => $id,
                'runtime_node_id' => $nodeId,
                'credential_type' => $type,
                'identifier' => $input['identifier'] ?? null,
                'encrypted_secret' => Crypt::encryptString($secret),
                'secret_fingerprint' => $this->fingerprint($secret),
                'version' => $version,
                'status' => 'active',
                'rotated_at' => now(),
                'expires_at' => $input['expires_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->bumpNode($request, $nodeId, $tenantId);
            $this->emit($request, $tenantId, $nodeId, 'runtime_node.credential_rotated', [
                'change' => $previousCredentialId === null ? 'created' : 'rotated',
                'registry_item_id' => $id,
                'type' => $type,
                'version' => $version,
            ]);

            return ['credential' => $this->credential($id, $tenantId), 'runtime_node' => $this->node($nodeId, $tenantId)];
        });

        if ($key !== null) {
            $this->idempotency->complete($scope, $key, $result);
        }

        return $result;
    }

    private function nodeForUpdate(string $nodeId, string $tenantId): object
    {
        $node = DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');

        return $node;
    }

    private function activeTelephonyWorkCount(string $tenantId, string $nodeId): int
    {
        return $this->workload->activeTelephonyWorkCount($tenantId, $nodeId);
    }

    private function assertIdentityChangeAllowed(object $node, string $tenantId): void
    {
        if (! in_array((string) $node->desired_state, ['draft', 'disabled'], true) || $this->activeTelephonyWorkCount($tenantId, (string) $node->id) > 0) {
            throw new InvalidArgumentException('Runtime family and adapter identity can only change on a draft or disabled node with no active runtime bindings.');
        }
    }

    private function assertNodeNotRetired(object $node): void
    {
        if ((string) $node->desired_state === 'retired') {
            throw new InvalidArgumentException('Retired runtime nodes are read-only historical records.');
        }
    }

    private function retireActiveCredentialsForTerminalRetirement(ExecutionContext $context, string $tenantId, string $nodeId, string $change, ?string $operationId = null): void
    {
        $activeCredentials = DB::table('runtime_node_credentials')
            ->where('runtime_node_id', $nodeId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();
        foreach ($activeCredentials as $credential) {
            DB::table('runtime_node_credentials')->where('id', $credential->id)->update([
                'status' => 'retired',
                'updated_at' => now(),
            ]);
            $payload = [
                'change' => $change,
                'registry_item_id' => $credential->id,
                'type' => $credential->credential_type,
            ];
            if ($operationId !== null) {
                $payload['operation_id'] = $operationId;
            }
            $this->emitContext($context, $tenantId, $nodeId, 'runtime_node.credential_retired', $payload);
        }
    }

    private function latestSuccessfulFenceForDisabledNode(string $tenantId, string $nodeId): ?object
    {
        return DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('operation_type', (string) config('telephony_domain.operation_types.runtime_fence'))
            ->where('status', 'succeeded')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at')
            ->get()
            ->first(function (object $operation): bool {
                $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);

                return data_get($payload, 'runtime_fence_provenance.runtime_node_disabled.by_operation') === true;
            });
    }

    private function requestRestoreOperation(ExecutionContext $context, object $node, object $sourceFence, ?string $actorId, ?string $reason): string
    {
        $sourcePayload = json_decode((string) $sourceFence->payload, true, 512, JSON_THROW_ON_ERROR);
        $scale = data_get($sourcePayload, 'runtime_fence_provenance.scale_to_zero_requested');
        if (! is_array($scale)) {
            abort(response()->json(['message' => 'Source fence operation is missing scale provenance.'], 409));
        }
        $targetReplicas = filter_var($scale['pre_scale_replicas'] ?? null, FILTER_VALIDATE_INT);
        if ($targetReplicas === false || $targetReplicas < 1) {
            abort(response()->json(['message' => 'Source fence operation has invalid target replicas.'], 409));
        }
        $namespace = $scale['namespace'] ?? null;
        $deployment = $scale['deployment'] ?? null;
        if (! is_string($namespace) || ! is_string($deployment) || $namespace === '' || $deployment === '') {
            abort(response()->json(['message' => 'Source fence operation has invalid workload provenance.'], 409));
        }
        $sourceGeneration = filter_var($sourcePayload['configuration_generation'] ?? null, FILTER_VALIDATE_INT);
        if ($sourceGeneration === false || $sourceGeneration < 1) {
            abort(response()->json(['message' => 'Source fence operation has invalid generation provenance.'], 409));
        }

        $operationType = (string) config('telephony_domain.operation_types.runtime_node_restore');
        $predecessor = $this->latestRestoreOperationForAuthority((string) $node->tenant_id, (string) $node->id, (string) $sourceFence->id, $sourceGeneration, (int) $node->configuration_version);
        if ($predecessor !== null && $this->restoreStatusIsReusable((string) $predecessor->status)) {
            return (string) $predecessor->id;
        }
        $attemptGeneration = $predecessor === null ? 1 : $this->restoreAttemptGeneration($predecessor) + 1;
        $idempotency = $this->restoreIdempotencyKey((string) $node->id, (string) $sourceFence->id, $sourceGeneration, (int) $node->configuration_version, $predecessor?->id);
        if (($existing = $this->operations->findIdempotent($operationType, $idempotency)) !== null) {
            return $existing;
        }

        $payload = [
            'tenant_id' => $node->tenant_id,
            'runtime_node_id' => $node->id,
            'requested_desired_state' => 'active',
            'source_fence_operation_id' => $sourceFence->id,
            'source_fence_generation' => $sourceGeneration,
            'workload_namespace' => $namespace,
            'deployment' => $deployment,
            'target_replicas' => $targetReplicas,
            'requesting_actor' => $actorId,
            'reason' => $reason,
            'expected_runtime_node_configuration_version' => (int) $node->configuration_version,
            'supersedes_restore_operation_id' => $predecessor?->id,
            'restore_attempt_generation' => $attemptGeneration,
        ];

        $operationId = $this->operations->create(
            $operationType,
            'runtime_node',
            (string) $node->id,
            $payload,
            $context,
            idempotencyKey: $idempotency,
            maxAttempts: (int) config('telephony_domain.operation_max_attempts.runtime_node_restore', 8),
            runtimeNodeId: (string) $node->id,
        );
        $predecessorProvenance = $this->restoreScaleProvenanceFromPredecessor($predecessor, (string) $sourceFence->id, $namespace, $deployment, $targetReplicas);
        if ($predecessorProvenance !== null) {
            $this->recordInheritedRestoreScaleProvenance($operationId, $predecessorProvenance, (string) $predecessor->id);
        }

        return $operationId;
    }

    private function restoreIdempotencyKey(string $nodeId, string $sourceFenceOperationId, int $sourceGeneration, int $configurationVersion, ?string $predecessorId): IdempotencyKey
    {
        $base = sprintf(
            'runtime-node-restore:%s:%s:%s:%s:active',
            $nodeId,
            $sourceFenceOperationId,
            $sourceGeneration,
            $configurationVersion,
        );
        if ($predecessorId !== null) {
            $base .= ':after:'.$predecessorId;
        }

        return IdempotencyKey::fromString($base);
    }

    private function latestRestoreOperationForAuthority(string $tenantId, string $nodeId, string $sourceFenceOperationId, int $sourceGeneration, int $configurationVersion): ?object
    {
        return DB::table('runtime_operations')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $nodeId)
            ->where('operation_type', (string) config('telephony_domain.operation_types.runtime_node_restore'))
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->get()
            ->first(function (object $operation) use ($tenantId, $nodeId, $sourceFenceOperationId, $sourceGeneration, $configurationVersion): bool {
                $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    return false;
                }

                return ($payload['requested_desired_state'] ?? null) === 'active'
                    && ($payload['tenant_id'] ?? null) === $tenantId
                    && ($payload['runtime_node_id'] ?? null) === $nodeId
                    && ($payload['source_fence_operation_id'] ?? null) === $sourceFenceOperationId
                    && (int) ($payload['source_fence_generation'] ?? 0) === $sourceGeneration
                    && (int) ($payload['expected_runtime_node_configuration_version'] ?? 0) === $configurationVersion;
            });
    }

    private function restoreStatusIsReusable(string $status): bool
    {
        return in_array($status, [
            OperationStatus::Pending->value,
            OperationStatus::Leased->value,
            OperationStatus::Running->value,
            OperationStatus::RetryScheduled->value,
            OperationStatus::Succeeded->value,
        ], true);
    }

    private function restoreAttemptGeneration(object $operation): int
    {
        $payload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $generation = is_array($payload) ? filter_var($payload['restore_attempt_generation'] ?? null, FILTER_VALIDATE_INT) : false;

        return $generation === false || $generation < 1 ? 1 : (int) $generation;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreScaleProvenanceFromPredecessor(?object $predecessor, string $sourceFenceOperationId, string $namespace, string $deployment, int $targetReplicas): ?array
    {
        if ($predecessor === null) {
            return null;
        }
        $payload = json_decode((string) $predecessor->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            return null;
        }
        $scale = $payload['runtime_restore_provenance']['scale_to_target_requested'] ?? null;
        if (! is_array($scale)
            || ($scale['by_operation'] ?? null) !== true
            || (string) ($scale['source_fence_operation_id'] ?? '') !== $sourceFenceOperationId
            || (string) ($scale['namespace'] ?? '') !== $namespace
            || (string) ($scale['deployment'] ?? '') !== $deployment
            || (int) ($scale['pre_scale_replicas'] ?? -1) !== 0
            || (int) ($scale['target_replicas'] ?? 0) !== $targetReplicas
        ) {
            return null;
        }

        return $scale;
    }

    /**
     * @param  array<string, mixed>  $predecessorProvenance
     */
    private function recordInheritedRestoreScaleProvenance(string $operationId, array $predecessorProvenance, string $predecessorId): void
    {
        DB::transaction(function () use ($operationId, $predecessorProvenance, $predecessorId): void {
            $row = DB::table('runtime_operations')->where('id', $operationId)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || isset($payload['runtime_restore_provenance']['scale_to_target_requested'])) {
                return;
            }
            $scale = $predecessorProvenance;
            $scale['operation_id'] = $operationId;
            $scale['scale_requested_by_restore_operation_id'] = (string) ($predecessorProvenance['scale_requested_by_restore_operation_id'] ?? $predecessorProvenance['operation_id'] ?? $predecessorId);
            $scale['inherited_from_restore_operation_id'] = $predecessorId;
            $scale['inherited_at'] = now()->toJSON();
            $payload['runtime_restore_provenance']['scale_to_target_requested'] = $scale;

            DB::table('runtime_operations')->where('id', $operationId)->update([
                'payload' => StableJson::encode(PayloadSafety::assertSafe($payload)),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOperation(string $operationId): array
    {
        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        if ($operation === null) {
            throw new \RuntimeException('runtime restore operation was not persisted');
        }

        return [
            'id' => $operation->id,
            'tenant_id' => $operation->tenant_id,
            'runtime_node_id' => $operation->runtime_node_id,
            'operation_type' => $operation->operation_type,
            'aggregate_type' => $operation->aggregate_type,
            'aggregate_id' => $operation->aggregate_id,
            'status' => $operation->status,
            'idempotency_key' => $operation->idempotency_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNode(object $node): array
    {
        return [
            'id' => $node->id,
            'tenant_id' => $node->tenant_id,
            'name' => $node->name,
            'slug' => $node->slug,
            'runtime_family' => $node->runtime_family,
            'adapter_key' => $node->adapter_key,
            'desired_state' => $node->desired_state,
            'observed_state' => $node->observed_state,
            'observed_at' => $node->observed_at,
            'configuration_version' => (int) $node->configuration_version,
            'observed_configuration_version' => $node->observed_configuration_version === null ? null : (int) $node->observed_configuration_version,
            'desired_execution_image' => $node->desired_execution_image,
            'observed_execution_image' => $node->observed_execution_image,
            'execution_image_current' => RuntimeExecutionContract::isCurrent($node->desired_execution_image, $node->observed_execution_image),
            'execution_contract_current' => $node->observed_state === 'ready'
                && $node->observed_configuration_version !== null
                && (int) $node->observed_configuration_version >= (int) $node->configuration_version
                && RuntimeExecutionContract::isCurrent($node->desired_execution_image, $node->observed_execution_image),
            'placement' => [
                'region' => $node->placement_region,
                'zone' => $node->placement_zone,
                'priority' => (int) $node->placement_priority,
                'capacity_weight' => (int) $node->capacity_weight,
                'labels' => $node->labels === null ? [] : json_decode($node->labels, true, 512, JSON_THROW_ON_ERROR),
            ],
            'endpoints' => DB::table('runtime_node_endpoints')->where('runtime_node_id', $node->id)->orderBy('purpose')->orderBy('priority')->get()->map(fn (object $endpoint): array => $this->serializeEndpoint($endpoint))->all(),
            'credentials' => DB::table('runtime_node_credentials')->where('runtime_node_id', $node->id)->orderBy('credential_type')->orderByDesc('version')->get()->map(fn (object $credential): array => $this->serializeCredential($credential))->all(),
            'capabilities' => DB::table('runtime_node_capabilities')->where('runtime_node_id', $node->id)->orderBy('capability_key')->pluck('capability_key')->all(),
            'management' => $this->managedRuntimeMetadata($node),
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
        ];
    }

    /**
     * This is a read-only projection of the canonical RNP relationship. The
     * presence of a provisioning request, rather than runtime technology or
     * Kubernetes labels, is the managed-runtime authority.
     *
     * @return array<string, mixed>
     */
    private function managedRuntimeMetadata(object $node): array
    {
        $request = DB::table('runtime_provisioning_requests as provisioning')
            ->join('deployment_targets as targets', 'targets.id', '=', 'provisioning.deployment_target_id')
            ->where('provisioning.tenant_id', $node->tenant_id)
            ->where('provisioning.runtime_node_id', $node->id)
            ->select([
                'provisioning.id',
                'provisioning.status',
                'provisioning.deployment_target_id',
                'targets.name as deployment_target_name',
                'targets.slug as deployment_target_slug',
                'targets.kind as deployment_target_kind',
            ])
            ->first();

        if ($request === null) {
            return [
                'mode' => 'external',
                'provisioning' => null,
                'deprovisioning' => null,
            ];
        }

        $provisionType = (string) config('telephony_domain.operation_types.runtime_node_provision', 'runtime.node.provision');
        $deprovisionType = (string) config('telephony_domain.operation_types.runtime_node_deprovision', 'runtime.node.deprovision');
        $provisioning = DB::table('runtime_operations')
            ->where('tenant_id', $node->tenant_id)
            ->where('aggregate_type', 'runtime_provisioning_request')
            ->where('aggregate_id', $request->id)
            ->where('operation_type', $provisionType)
            ->orderByDesc('created_at')
            ->first();
        $deprovisioning = DB::table('runtime_operations')
            ->where('tenant_id', $node->tenant_id)
            ->where('runtime_node_id', $node->id)
            ->where('operation_type', $deprovisionType)
            ->orderByDesc('created_at')
            ->first();

        return [
            'mode' => 'managed',
            'provisioning_request' => [
                'id' => (string) $request->id,
                'status' => (string) $request->status,
                'deployment_target' => [
                    'id' => (string) $request->deployment_target_id,
                    'name' => (string) $request->deployment_target_name,
                    'slug' => (string) $request->deployment_target_slug,
                    'kind' => (string) $request->deployment_target_kind,
                ],
            ],
            'provisioning' => $this->managedOperationMetadata($provisioning),
            'deprovisioning' => $this->managedOperationMetadata($deprovisioning),
        ];
    }

    /** @return array<string, mixed>|null */
    private function managedOperationMetadata(?object $operation): ?array
    {
        if ($operation === null) {
            return null;
        }

        return [
            'id' => (string) $operation->id,
            'status' => (string) $operation->status,
            'failure' => $operation->last_failure_class === null && $operation->last_failure_code === null ? null : [
                'class' => $operation->last_failure_class === null ? null : (string) $operation->last_failure_class,
                'code' => $operation->last_failure_code === null ? null : (string) $operation->last_failure_code,
            ],
            'started_at' => $operation->started_at,
            'completed_at' => $operation->completed_at,
            'updated_at' => $operation->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endpoint(string $endpointId, string $tenantId): array
    {
        $endpoint = DB::table('runtime_node_endpoints')->join('runtime_nodes', 'runtime_nodes.id', '=', 'runtime_node_endpoints.runtime_node_id')
            ->where('runtime_node_endpoints.id', $endpointId)->where('runtime_nodes.tenant_id', $tenantId)->select('runtime_node_endpoints.*')->first();
        abort_unless($endpoint !== null, 404, 'Endpoint not found.');

        return $this->serializeEndpoint($endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function credential(string $credentialId, string $tenantId): array
    {
        $credential = DB::table('runtime_node_credentials')->join('runtime_nodes', 'runtime_nodes.id', '=', 'runtime_node_credentials.runtime_node_id')
            ->where('runtime_node_credentials.id', $credentialId)->where('runtime_nodes.tenant_id', $tenantId)->select('runtime_node_credentials.*')->first();
        abort_unless($credential !== null, 404, 'Credential not found.');

        return $this->serializeCredential($credential);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEndpoint(object $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'purpose' => $endpoint->purpose,
            'transport' => $endpoint->transport,
            'host' => $endpoint->host,
            'port' => (int) $endpoint->port,
            'path' => $endpoint->path,
            'tls_mode' => $endpoint->tls_mode,
            'priority' => (int) $endpoint->priority,
            'enabled' => (bool) $endpoint->enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCredential(object $credential): array
    {
        return [
            'id' => $credential->id,
            'type' => $credential->credential_type,
            'identifier' => $credential->identifier,
            'fingerprint' => $credential->secret_fingerprint,
            'version' => (int) $credential->version,
            'status' => $credential->status,
            'rotated_at' => $credential->rotated_at,
            'expires_at' => $credential->expires_at,
        ];
    }

    private function bumpNode(Request $request, string $nodeId, string $tenantId): void
    {
        DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update([
            'configuration_version' => DB::raw('configuration_version + 1'),
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
    }

    private function bumpNodeContext(ExecutionContext $context, string $tenantId, string $nodeId): void
    {
        DB::table('runtime_nodes')->where('id', $nodeId)->where('tenant_id', $tenantId)->update([
            'configuration_version' => DB::raw('configuration_version + 1'),
            'updated_by' => $context->actorId,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(Request $request, string $tenantId, string $nodeId, string $eventType, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $this->emitContext($context, $tenantId, $nodeId, $eventType, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitContext(ExecutionContext $context, string $tenantId, string $nodeId, string $eventType, array $payload): void
    {
        $this->audit->append($context, $eventType, 'runtime_node', $nodeId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($eventType, 1, 'runtime_node', $nodeId, $payload, $context));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function beginIdempotent(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try {
            $existing = $this->idempotency->begin($scope, $key, $payload);
        } catch (IdempotencyConflict) {
            abort(response()->json(['message' => 'Idempotency key conflict.'], 409));
        }
        if ($existing === null) {
            return null;
        }
        if ($existing->status === 'completed' && $existing->result !== null) {
            return $existing->result;
        }

        abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }

    private function normalizeSlug(string $slug): string
    {
        $value = Str::slug($slug);
        if ($value === '' || strlen($value) > 100) {
            throw new InvalidArgumentException('Invalid runtime-node slug.');
        }

        return $value;
    }

    private function slugLike(string $value): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $value)) {
            throw new InvalidArgumentException('Invalid credential type.');
        }

        return $value;
    }

    private function validateHost(string $host): string
    {
        if (str_contains($host, '@') || str_contains($host, '?') || str_contains($host, '/')) {
            throw new InvalidArgumentException('Endpoint host must not contain credentials, path, or query.');
        }
        if (! preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
            throw new InvalidArgumentException('Invalid endpoint host.');
        }

        return strtolower($host);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedLabels(mixed $labels): array
    {
        if ($labels === null || $labels === []) {
            return [];
        }
        if (! is_array($labels)) {
            throw new InvalidArgumentException('Labels must be an object.');
        }
        $validated = [];
        foreach ($labels as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $key)) {
                throw new InvalidArgumentException('Invalid placement label.');
            }
            if ($key === RuntimeNodeWorkloadIdentityValidator::RESERVED_LABEL_KEY) {
                $identity = RuntimeNodeWorkloadIdentityValidator::fromLabelValue($value);
                $validated[$key] = [
                    'namespace' => $identity->namespace,
                    'deployment' => $identity->deployment,
                ];

                continue;
            }
            if (! is_string($value) || strlen($value) > 80) {
                throw new InvalidArgumentException('Invalid placement label.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    private function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
