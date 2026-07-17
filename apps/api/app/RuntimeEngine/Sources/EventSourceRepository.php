<?php

namespace App\RuntimeEngine\Sources;

use App\RuntimeEngine\EngineIds;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EventSourceRepository
{
    public const KIND_RUNTIME_NODE = 'runtime-node';

    public const KIND_KAMAILIO_REGISTRATION = 'kamailio-registration';

    public const KAMAILIO_REGISTRATION_KEY = 'local-shared-registrar';

    /**
     * @return list<string>
     */
    public function supportedSourceKinds(): array
    {
        return [
            self::KIND_RUNTIME_NODE,
            self::KIND_KAMAILIO_REGISTRATION,
        ];
    }

    public function ensureRuntimeNodeSource(string $tenantId, string $runtimeNodeId, ?string $adapterKey = null): object
    {
        $node = DB::table('runtime_nodes')
            ->where('id', $runtimeNodeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($node === null) {
            throw new DomainException('runtime node does not match event source');
        }

        if ($adapterKey !== null && $node->adapter_key !== $adapterKey) {
            throw new DomainException('runtime node does not match event source');
        }

        return $this->ensure(self::KIND_RUNTIME_NODE, $runtimeNodeId, $runtimeNodeId);
    }

    public function ensure(string $sourceKind, string $sourceKey, ?string $runtimeNodeId = null): object
    {
        $this->assertValidShape($sourceKind, $runtimeNodeId);

        return DB::transaction(function () use ($sourceKind, $sourceKey, $runtimeNodeId): object {
            $existing = DB::table('event_sources')
                ->where('source_kind', $sourceKind)
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((string) ($existing->runtime_node_id ?? '') !== (string) ($runtimeNodeId ?? '')) {
                    throw new DomainException('event source identity shape does not match existing source');
                }

                return $existing;
            }

            $id = EngineIds::new();
            DB::table('event_sources')->insert([
                'id' => $id,
                'source_kind' => $sourceKind,
                'source_key' => $sourceKey,
                'runtime_node_id' => $runtimeNodeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('event_sources')->where('id', $id)->first();
        });
    }

    public function find(string $eventSourceId): ?object
    {
        return DB::table('event_sources')->where('id', $eventSourceId)->first();
    }

    private function assertValidShape(string $sourceKind, ?string $runtimeNodeId): void
    {
        if (! in_array($sourceKind, $this->supportedSourceKinds(), true)) {
            throw new DomainException('event source kind is not supported');
        }

        if ($sourceKind === self::KIND_RUNTIME_NODE && $runtimeNodeId === null) {
            throw new DomainException('runtime-node event sources require a runtime node');
        }

        if ($sourceKind !== self::KIND_RUNTIME_NODE && $runtimeNodeId !== null) {
            throw new DomainException('platform event sources must not reference a runtime node');
        }
    }
}
