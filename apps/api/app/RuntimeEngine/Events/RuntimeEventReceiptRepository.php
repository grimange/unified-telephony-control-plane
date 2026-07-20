<?php

namespace App\RuntimeEngine\Events;

use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Sources\EventSourceRepository;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RuntimeEventReceiptRepository
{
    public function __construct(private readonly ?EventSourceRepository $sources = null) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function openEpoch(string $tenantId, string $runtimeNodeId, string $adapterKey, string $owner): string
    {
        $source = $this->sourceRepository()->ensureRuntimeNodeSource($tenantId, $runtimeNodeId, $adapterKey);

        return $this->openSourceEpoch($source->id, $adapterKey, $owner);
    }

    public function openSourceEpoch(string $eventSourceId, string $adapterKey, string $owner): string
    {
        $source = $this->sourceRepository()->find($eventSourceId);
        if ($source === null) {
            throw new DomainException('event source does not exist');
        }

        $tenantId = $this->tenantIdForSource($source);
        $id = EngineIds::new();
        DB::table('runtime_event_connection_epochs')->insert([
            'id' => $id,
            'event_source_id' => $eventSourceId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $source->runtime_node_id,
            'adapter_key' => $adapterKey,
            'status' => 'open',
            'owner' => $owner,
            'fencing_token' => EngineIds::token(),
            'opened_at' => now(),
            'last_authoritative_signal_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:string,id:string}
     */
    public function ingest(string $tenantId, string $runtimeNodeId, string $adapterKey, string $epochId, ?string $externalEventKey, string $eventType, int $eventVersion, array $payload): array
    {
        $source = $this->sourceRepository()->ensureRuntimeNodeSource($tenantId, $runtimeNodeId, $adapterKey);

        return $this->ingestSource($source->id, $adapterKey, $epochId, $externalEventKey, $eventType, $eventVersion, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:string,id:string}
     */
    public function ingestSource(string $eventSourceId, string $adapterKey, string $epochId, ?string $externalEventKey, string $eventType, int $eventVersion, array $payload): array
    {
        $source = $this->sourceRepository()->find($eventSourceId);
        if ($source === null) {
            throw new DomainException('event source does not exist');
        }

        $epoch = DB::table('runtime_event_connection_epochs')
            ->where('id', $epochId)
            ->where('event_source_id', $eventSourceId)
            ->where('adapter_key', $adapterKey)
            ->first();
        if ($epoch === null || $epoch->status !== 'open') {
            throw new DomainException('runtime event connection epoch is not open');
        }

        $safePayload = PayloadSafety::redact($payload);
        PayloadSafety::assertSafe($safePayload);
        $occurredAt = is_string($safePayload['occurred_at'] ?? null) ? $safePayload['occurred_at'] : now();
        $hash = StableJson::fingerprint($safePayload);
        $key = $externalEventKey ?: 'fingerprint:'.$hash;
        $id = EngineIds::new();

        $inserted = DB::table('runtime_event_receipts')->insertOrIgnore([
            'id' => $id,
            'event_source_id' => $eventSourceId,
            'tenant_id' => $this->tenantIdForSource($source),
            'runtime_node_id' => $source->runtime_node_id,
            'adapter_key' => $adapterKey,
            'connection_epoch_id' => $epochId,
            'external_event_key' => $key,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'payload_hash' => $hash,
            'sanitized_payload' => StableJson::encode($safePayload),
            'occurred_at' => $occurredAt,
            'received_at' => now(),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return ['status' => 'accepted', 'id' => $id];
        }

        $existing = DB::table('runtime_event_receipts')
            ->where('connection_epoch_id', $epochId)
            ->where('external_event_key', $key)
            ->where(function ($query) use ($eventSourceId, $source): void {
                $query->where('event_source_id', $eventSourceId);
                if ($source->runtime_node_id !== null) {
                    $query->orWhere('runtime_node_id', $source->runtime_node_id);
                }
            })
            ->first();
        if ($existing === null) {
            $existing = DB::table('runtime_event_receipts')
                ->where('connection_epoch_id', $epochId)
                ->where('external_event_key', $key)
                ->first();
        }
        if ($existing === null) {
            throw new DomainException('event duplicate detected but original receipt is missing');
        }
        if ($existing->payload_hash !== $hash) {
            DB::table('runtime_event_receipts')->where('id', $existing->id)->update([
                'status' => 'conflict',
                'failure_class' => 'conflict',
                'failure_code' => 'event_key_payload_mismatch',
                'updated_at' => now(),
            ]);
            throw new DomainException('runtime event key reused with different payload');
        }

        return ['status' => 'duplicate', 'id' => $existing->id];
    }

    public function closeEpoch(string $epochId, string $owner): bool
    {
        return DB::table('runtime_event_connection_epochs')
            ->where('id', $epochId)
            ->where('owner', $owner)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Close any epoch left open by a superseded owner for this node. A node's listener
     * lease guarantees a single current owner, so once that owner has been confirmed,
     * any other still-open epoch belongs to a process that died without running its
     * own shutdown path (e.g. a killed Pod) and must not be left reporting as open.
     */
    public function closeStaleEpochs(string $runtimeNodeId, string $currentOwner): int
    {
        $source = DB::table('event_sources')
            ->where('source_kind', EventSourceRepository::KIND_RUNTIME_NODE)
            ->where('source_key', $runtimeNodeId)
            ->first();

        if ($source === null) {
            return DB::table('runtime_event_connection_epochs')
                ->where('runtime_node_id', $runtimeNodeId)
                ->where('status', 'open')
                ->where('owner', '!=', $currentOwner)
                ->update([
                    'status' => 'expired',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $this->closeStaleEpochsForSource($source->id, $currentOwner);
    }

    public function closeStaleEpochsForSource(string $eventSourceId, string $currentOwner): int
    {
        return DB::table('runtime_event_connection_epochs')
            ->where('event_source_id', $eventSourceId)
            ->where('status', 'open')
            ->where('owner', '!=', $currentOwner)
            ->update([
                'status' => 'expired',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Close the current owner's previous still-open epoch before opening a successor.
     */
    public function closeSupersededOwnerEpochs(string $runtimeNodeId, string $currentOwner): int
    {
        $source = DB::table('event_sources')
            ->where('source_kind', EventSourceRepository::KIND_RUNTIME_NODE)
            ->where('source_key', $runtimeNodeId)
            ->first();

        $query = DB::table('runtime_event_connection_epochs')
            ->where('status', 'open')
            ->where('owner', $currentOwner);

        if ($source === null) {
            $query->where('runtime_node_id', $runtimeNodeId);
        } else {
            $query->where('event_source_id', $source->id);
        }

        return $query->update([
            'status' => 'expired',
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordAuthoritativeSignal(string $epochId, string $owner): bool
    {
        return DB::table('runtime_event_connection_epochs')
            ->where('id', $epochId)
            ->where('owner', $owner)
            ->where('status', 'open')
            ->update([
                'last_authoritative_signal_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    public function find(string $id): ?object
    {
        return DB::table('runtime_event_receipts')->where('id', $id)->first();
    }

    /**
     * @return list<object>
     */
    public function claimAvailable(string $leaseOwner, int $batchSize = 10, int $leaseSeconds = 60): array
    {
        return DB::transaction(function () use ($leaseOwner, $batchSize, $leaseSeconds): array {
            $query = DB::table('runtime_event_receipts')
                ->whereIn('status', ['pending', 'retry_scheduled', 'leased'])
                ->where('available_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', now())
                        ->orWhere('status', '!=', 'leased');
                })
                ->orderBy('runtime_node_id')
                ->orderBy('occurred_at')
                ->orderBy('connection_epoch_id')
                ->orderBy('created_at')
                ->limit($batchSize);

            if (DB::getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $row->lease_token = EngineIds::token();
                $row->leaseToken = $row->lease_token;
                $row->attempt = ((int) $row->attempt_count) + 1;
                DB::table('runtime_event_receipts')->where('id', $row->id)->update([
                    'status' => 'leased',
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $row->lease_token,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => $row->attempt,
                    'updated_at' => now(),
                ]);
            }

            return $rows->all();
        });
    }

    public function markProcessed(string $id, string $leaseToken): bool
    {
        return DB::table('runtime_event_receipts')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function markFailed(string $id, string $leaseToken, string $failureClass, string $failureCode, bool $retryable): bool
    {
        return DB::table('runtime_event_receipts')
            ->where('id', $id)
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => $retryable ? 'retry_scheduled' : ($failureClass === 'unsupported_capability' ? 'unsupported' : 'terminal_failed'),
                'available_at' => $retryable ? now()->addSeconds(30) : now(),
                'failure_class' => mb_substr($failureClass, 0, 80),
                'failure_code' => mb_substr($failureCode, 0, 120),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    private function sourceRepository(): EventSourceRepository
    {
        return $this->sources ?? app(EventSourceRepository::class);
    }

    private function tenantIdForSource(object $source): ?string
    {
        if ($source->runtime_node_id === null) {
            return null;
        }

        return DB::table('runtime_nodes')->where('id', $source->runtime_node_id)->value('tenant_id');
    }
}
