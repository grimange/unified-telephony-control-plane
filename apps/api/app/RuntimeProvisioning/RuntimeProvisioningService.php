<?php

namespace App\RuntimeProvisioning;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\IdempotencyKey;
use App\ControlPlane\Shared\StableJson;
use App\Identity\IdentityContext;
use App\Identity\IdentityIds;
use App\RuntimeRegistry\RuntimeRegistryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RuntimeProvisioningService
{
    private const LOCAL_TARGET_KIND = 'local_kubernetes';

    private const LOCAL_TARGET_NAME = 'Local UTCP Kubernetes';

    private const LOCAL_TARGET_SLUG = 'local-kubernetes';

    /** @var array<string, array{runtime_family:string, adapter_key:string}> */
    private const MANAGED_RUNTIMES = [
        'asterisk-ari' => ['runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari'],
        'freeswitch-esl' => ['runtime_family' => 'freeswitch', 'adapter_key' => 'freeswitch-esl'],
    ];

    public function __construct(
        private readonly RuntimeRegistryService $registry,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly RuntimeOperationRepository $operations,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listDeploymentTargets(Request $request, string $tenantId): array
    {
        return DB::transaction(function () use ($request, $tenantId): array {
            $this->ensureLocalTarget($request, $tenantId);

            return DB::table('deployment_targets')
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get()
                ->map(fn (object $target): array => $this->serializeTarget($target))
                ->all();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deploymentTarget(Request $request, string $tenantId, string $targetId): array
    {
        return DB::transaction(function () use ($request, $tenantId, $targetId): array {
            $this->ensureLocalTarget($request, $tenantId);
            $target = DB::table('deployment_targets')
                ->where('id', $targetId)
                ->where('tenant_id', $tenantId)
                ->first();
            abort_unless($target !== null, 404, 'Deployment target not found.');

            return ['deployment_target' => $this->serializeTarget($target)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function requestProvisioning(Request $request, string $tenantId, array $input, IdempotencyKey $idempotencyKey): array
    {
        $runtimeFamily = (string) ($input['runtime_family'] ?? '');
        $adapterKey = (string) ($input['adapter_key'] ?? '');
        $managedRuntime = self::MANAGED_RUNTIMES[$adapterKey] ?? null;
        if ($managedRuntime === null || $runtimeFamily !== $managedRuntime['runtime_family']) {
            throw new InvalidArgumentException('Unsupported managed runtime family and adapter combination.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 160) {
            throw new InvalidArgumentException('Invalid managed runtime name.');
        }

        $requestedSlug = trim((string) ($input['slug'] ?? ''));
        $slug = Str::slug($requestedSlug !== '' ? $requestedSlug : $name);
        if ($slug === '' || strlen($slug) > 100) {
            throw new InvalidArgumentException('Invalid managed runtime name or identifier.');
        }

        $fingerprint = StableJson::fingerprint([
            'deployment_target_id' => (string) ($input['deployment_target_id'] ?? ''),
            'runtime_family' => $runtimeFamily,
            'adapter_key' => $adapterKey,
            'name' => $name,
            'slug' => $slug,
        ]);

        return DB::transaction(function () use ($request, $tenantId, $input, $idempotencyKey, $fingerprint, $runtimeFamily, $adapterKey, $name, $slug): array {
            $target = DB::table('deployment_targets')
                ->where('id', $input['deployment_target_id'] ?? null)
                ->where('tenant_id', $tenantId)
                ->first();
            if ($target === null || $target->kind !== self::LOCAL_TARGET_KIND) {
                throw new InvalidArgumentException('Deployment target is not available for this tenant.');
            }

            $existing = DB::table('runtime_provisioning_requests')
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey->value())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new InvalidArgumentException('Idempotency key conflict.');
                }

                $this->ensureProvisionOperation($request, $tenantId, $existing);

                return ['provisioning_request' => $this->serializeRequest($existing, $tenantId)];
            }

            $nodeResult = $this->registry->createNode($request, $tenantId, [
                'name' => $name,
                'slug' => $slug,
                'runtime_family' => $runtimeFamily,
                'adapter_key' => $adapterKey,
            ]);
            $node = $nodeResult['runtime_node'];
            $requestId = IdentityIds::new();

            DB::table('runtime_provisioning_requests')->insert([
                'id' => $requestId,
                'tenant_id' => $tenantId,
                'deployment_target_id' => $target->id,
                'runtime_node_id' => $node['id'],
                'runtime_family' => $runtimeFamily,
                'adapter_key' => $adapterKey,
                'requested_name' => $name,
                'requested_slug' => $slug,
                'idempotency_key' => $idempotencyKey->value(),
                'request_fingerprint' => $fingerprint,
                'status' => 'requested',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $savedRequest = DB::table('runtime_provisioning_requests')->where('id', $requestId)->first();
            $this->ensureProvisionOperation($request, $tenantId, $savedRequest);

            $this->emit(IdentityContext::fromRequest($request, $tenantId), 'runtime_provisioning.requested', 'runtime_provisioning_request', $requestId, [
                'deployment_target_id' => $target->id,
                'runtime_node_id' => $node['id'],
                'runtime_family' => $runtimeFamily,
                'adapter_key' => $adapterKey,
                'requested_name' => $name,
                'requested_slug' => $slug,
                'status' => 'requested',
            ]);

            $saved = DB::table('runtime_provisioning_requests')->where('id', $requestId)->first();

            return ['provisioning_request' => $this->serializeRequest($saved, $tenantId)];
        });
    }

    private function ensureProvisionOperation(Request $request, string $tenantId, object $provisioningRequest): void
    {
        $operationType = (string) config('telephony_domain.operation_types.runtime_node_provision', 'runtime.node.provision');
        $this->operations->create(
            operationType: $operationType,
            aggregateType: 'runtime_provisioning_request',
            aggregateId: (string) $provisioningRequest->id,
            payload: [
                'provisioning_request_id' => (string) $provisioningRequest->id,
                'deployment_target_id' => (string) $provisioningRequest->deployment_target_id,
                'runtime_node_id' => (string) $provisioningRequest->runtime_node_id,
                'adapter_key' => (string) $provisioningRequest->adapter_key,
            ],
            context: IdentityContext::fromRequest($request, $tenantId),
            idempotencyKey: IdempotencyKey::fromString('runtime-node-provision:'.$provisioningRequest->id),
            maxAttempts: (int) config('telephony_domain.operation_max_attempts.runtime_node_provision', 8),
            runtimeNodeId: (string) $provisioningRequest->runtime_node_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function provisioningRequest(string $tenantId, string $requestId): array
    {
        $provisioningRequest = DB::table('runtime_provisioning_requests')
            ->where('id', $requestId)
            ->where('tenant_id', $tenantId)
            ->first();
        abort_unless($provisioningRequest !== null, 404, 'Provisioning request not found.');

        return ['provisioning_request' => $this->serializeRequest($provisioningRequest, $tenantId)];
    }

    private function ensureLocalTarget(Request $request, string $tenantId): object
    {
        $targetId = IdentityIds::new();
        $inserted = DB::table('deployment_targets')->insertOrIgnore([
            'id' => $targetId,
            'tenant_id' => $tenantId,
            'name' => self::LOCAL_TARGET_NAME,
            'slug' => self::LOCAL_TARGET_SLUG,
            'kind' => self::LOCAL_TARGET_KIND,
            'configuration' => StableJson::encode([
                'cluster' => 'utcp-local',
                'context' => 'k3d-utcp-local',
                'namespace' => 'utcp-runtime',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target = DB::table('deployment_targets')->where('tenant_id', $tenantId)->where('kind', self::LOCAL_TARGET_KIND)->first();
        if ($target === null) {
            throw new InvalidArgumentException('Local Kubernetes deployment target could not be registered.');
        }
        if ($inserted === 1) {
            $this->emit(IdentityContext::fromRequest($request, $tenantId), 'deployment_target.registered', 'deployment_target', $target->id, [
                'kind' => $target->kind,
                'slug' => $target->slug,
                'configuration' => json_decode($target->configuration, true, 512, JSON_THROW_ON_ERROR),
            ]);
        }

        return $target;
    }

    /** @return array<string, mixed> */
    private function serializeTarget(object $target): array
    {
        return [
            'id' => $target->id,
            'name' => $target->name,
            'slug' => $target->slug,
            'kind' => $target->kind,
            'configuration' => $target->configuration === null ? [] : json_decode($target->configuration, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => $target->created_at,
            'updated_at' => $target->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRequest(object $provisioningRequest, string $tenantId): array
    {
        return [
            'id' => $provisioningRequest->id,
            'deployment_target_id' => $provisioningRequest->deployment_target_id,
            'runtime_family' => $provisioningRequest->runtime_family,
            'adapter_key' => $provisioningRequest->adapter_key,
            'requested_name' => $provisioningRequest->requested_name,
            'requested_slug' => $provisioningRequest->requested_slug,
            'status' => $provisioningRequest->status,
            'runtime_node' => $this->registry->node($provisioningRequest->runtime_node_id, $tenantId),
            'created_at' => $provisioningRequest->created_at,
            'updated_at' => $provisioningRequest->updated_at,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function emit(ExecutionContext $context, string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        $this->audit->append($context, $eventType, $aggregateType, $aggregateId, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($eventType, 1, $aggregateType, $aggregateId, $payload, $context));
    }
}
