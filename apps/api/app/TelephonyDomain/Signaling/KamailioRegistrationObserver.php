<?php

namespace App\TelephonyDomain\Signaling;

use App\ControlPlane\Shared\PayloadSafety;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Sources\EventSourceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class KamailioRegistrationObserver
{
    public const LISTENER_KIND = 'kamailio-registration-observer';

    public const ADAPTER_KEY = 'kamailio-registration';

    public const PROJECTOR = 'kamailio-registration-observer';

    public function __construct(
        private readonly EventSourceRepository $sources,
        private readonly RuntimeListenerLeaseRepository $leases,
        private readonly RuntimeEventReceiptRepository $receipts,
        private readonly KamailioRegistrationSnapshotDiffer $differ,
    ) {}

    /**
     * @return array{status:string,receipts:int,checkpoint_advanced:bool}
     */
    public function pollOnce(string $owner, int $leaseSeconds = 30, int $limit = 1000): array
    {
        $source = $this->sources->ensure(EventSourceRepository::KIND_KAMAILIO_REGISTRATION, EventSourceRepository::KAMAILIO_REGISTRATION_KEY);
        $lease = $this->leases->claimSource((string) $source->id, self::LISTENER_KIND, $owner, $leaseSeconds);
        if ($lease === null) {
            return ['status' => 'lease_unavailable', 'receipts' => 0, 'checkpoint_advanced' => false];
        }

        $this->receipts->closeStaleEpochsForSource((string) $source->id, $owner);
        $epochId = $this->openEpochForOwner((string) $source->id, $owner);
        $collectedAt = now();
        $current = $this->snapshot($limit);

        return DB::transaction(function () use ($source, $lease, $owner, $leaseSeconds, $epochId, $collectedAt, $current): array {
            if (! $this->leases->isCurrent((string) $lease->id, $owner, (string) $lease->fencing_token)) {
                throw new RuntimeException('kamailio registration observer lease was superseded');
            }

            $checkpoint = $this->checkpoint((string) $source->id);
            $previous = is_array($checkpoint['identities'] ?? null) ? $checkpoint['identities'] : [];
            $events = $this->differ->diff($this->normalizeCheckpointRows($previous), $current, $collectedAt);
            $accepted = 0;

            foreach ($events as $event) {
                PayloadSafety::assertSafe($event);
                $result = $this->receipts->ingestSource(
                    (string) $source->id,
                    self::ADAPTER_KEY,
                    $epochId,
                    $this->dedupeKey($event),
                    (string) $event['event_type'],
                    1,
                    $event,
                );
                if ($result['status'] === 'accepted') {
                    $accepted++;
                }
            }

            $this->advanceCheckpoint((string) $source->id, $epochId, $collectedAt, $current);
            $this->leases->renew((string) $lease->id, $owner, (string) $lease->fencing_token, $leaseSeconds);

            return ['status' => 'polled', 'receipts' => $accepted, 'checkpoint_advanced' => true];
        });
    }

    private function openEpochForOwner(string $eventSourceId, string $owner): string
    {
        $existing = DB::table('runtime_event_connection_epochs')
            ->where('event_source_id', $eventSourceId)
            ->where('adapter_key', self::ADAPTER_KEY)
            ->where('owner', $owner)
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->first();

        if ($existing !== null) {
            return (string) $existing->id;
        }

        return $this->receipts->openSourceEpoch($eventSourceId, self::ADAPTER_KEY, $owner);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function snapshot(int $limit): array
    {
        $rows = $this->locationRows($limit);

        $snapshot = [];
        foreach ($rows as $row) {
            $identity = $this->identity((string) $row->username, is_string($row->domain ?? null) ? (string) $row->domain : null);
            if ($identity === null || isset($snapshot[$identity])) {
                continue;
            }

            $snapshot[$identity] = [
                'ruid' => mb_substr((string) $row->ruid, 0, 64),
                'expires_at' => Carbon::parse((string) $row->expires)->toISOString(),
                'last_modified_at' => Carbon::parse((string) $row->last_modified)->toISOString(),
                'contact_fingerprint' => hash('sha256', (string) $row->contact),
            ];
        }

        return $snapshot;
    }

    /**
     * @return iterable<object>
     */
    private function locationRows(int $limit): iterable
    {
        if (DB::getDriverName() !== 'pgsql') {
            return DB::table('location')
                ->select(['username', 'domain', 'ruid', 'contact', 'expires', 'last_modified'])
                ->where('expires', '>', now()->subSeconds(1))
                ->orderBy('username')
                ->orderByDesc('last_modified')
                ->limit($limit)
                ->get();
        }

        $user = (string) env('KAMAILIO_OBSERVER_DB_USER', '');
        $password = (string) env('KAMAILIO_OBSERVER_DB_PASSWORD', '');
        if ($user === '' || $password === '') {
            throw new RuntimeException('kamailio observer database credentials are not configured');
        }

        $host = (string) config('database.connections.pgsql.host', '127.0.0.1');
        $port = (string) config('database.connections.pgsql.port', '5432');
        $database = (string) config('database.connections.pgsql.database');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $statement = $pdo->prepare(
            'select username, domain, ruid, contact, expires, last_modified
             from location
             where expires > now() - interval \'1 second\'
             order by username asc, last_modified desc
             limit :limit'
        );
        $statement->bindValue(':limit', max(1, min($limit, 5000)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function identity(string $username, ?string $domain): ?string
    {
        $username = mb_substr(trim($username), 0, 160);
        $domain = trim((string) $domain);
        if ($username === '') {
            return null;
        }

        return $username;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkpoint(string $eventSourceId): array
    {
        $row = DB::table('runtime_projection_checkpoints')
            ->where('projector', self::PROJECTOR)
            ->where('partition_key', EventSourceRepository::KAMAILIO_REGISTRATION_KEY)
            ->where('event_source_id', $eventSourceId)
            ->lockForUpdate()
            ->first();

        if ($row === null || ! is_string($row->checkpoint_payload ?? null)) {
            return ['identities' => []];
        }

        $decoded = json_decode($row->checkpoint_payload, true);

        return is_array($decoded) ? $decoded : ['identities' => []];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, string>>
     */
    private function normalizeCheckpointRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $identity => $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[(string) $identity] = [
                'ruid' => (string) ($row['ruid'] ?? ''),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'last_modified_at' => (string) ($row['last_modified_at'] ?? ''),
                'contact_fingerprint' => (string) ($row['contact_fingerprint'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, string>>  $snapshot
     */
    private function advanceCheckpoint(string $eventSourceId, string $epochId, Carbon $collectedAt, array $snapshot): void
    {
        $payload = [
            'collected_at' => $collectedAt->toISOString(),
            'identities' => $snapshot,
        ];
        $safePayload = PayloadSafety::redact($payload);
        PayloadSafety::assertSafe($safePayload);
        $encoded = StableJson::encode($safePayload);
        $hash = hash('sha256', $encoded);

        $existing = DB::table('runtime_projection_checkpoints')
            ->where('projector', self::PROJECTOR)
            ->where('partition_key', EventSourceRepository::KAMAILIO_REGISTRATION_KEY)
            ->where('event_source_id', $eventSourceId)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            DB::table('runtime_projection_checkpoints')->insert([
                'id' => EngineIds::new(),
                'event_source_id' => $eventSourceId,
                'projector' => self::PROJECTOR,
                'partition_key' => EventSourceRepository::KAMAILIO_REGISTRATION_KEY,
                'runtime_node_id' => null,
                'last_source_event_id' => $epochId,
                'last_observed_at' => $collectedAt,
                'checkpoint_payload' => $encoded,
                'checkpoint_hash' => $hash,
                'sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($existing->last_observed_at !== null && Carbon::parse((string) $existing->last_observed_at)->greaterThan($collectedAt)) {
            throw new RuntimeException('stale kamailio registration snapshot rejected');
        }

        DB::table('runtime_projection_checkpoints')->where('id', $existing->id)->update([
            'last_source_event_id' => $epochId,
            'last_observed_at' => $collectedAt,
            'checkpoint_payload' => $encoded,
            'checkpoint_hash' => $hash,
            'sequence' => ((int) $existing->sequence) + 1,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function dedupeKey(array $event): string
    {
        return hash('sha256', implode(':', [
            self::ADAPTER_KEY,
            (string) ($event['event_type'] ?? ''),
            (string) ($event['signaling_identity'] ?? ''),
            (string) ($event['ruid'] ?? ''),
            (string) ($event['last_modified_at'] ?? ''),
            (string) ($event['observed_expires_at'] ?? ''),
        ]));
    }
}
