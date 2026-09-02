<?php

namespace App\TelephonyDomain;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Idempotency\IdempotencyConflict;
use App\ControlPlane\Idempotency\IdempotencyStore;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\IdempotencyKey;
use App\Identity\IdentityContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MediaArchiveTargetService
{
    private const STATES = ['draft', 'active', 'disabled', 'retired'];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
    ) {}

    public function listTargets(string $tenantId): array
    {
        return DB::table('media_archive_targets')->where('tenant_id', $tenantId)->orderBy('name')->get()->map(fn (object $row): array => $this->serialize($row))->all();
    }

    public function target(string $tenantId, string $id): array
    {
        return $this->serialize($this->targetForTenant($tenantId, $id));
    }

    public function createTarget(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $input = $this->validatedTarget($input, true);
        $fingerprint = ['tenant_id' => $tenantId, 'input' => $input];
        if ($key !== null && ($existing = $this->beginIdempotent('rma-d.targets.create', $key, $fingerprint)) !== null) return $existing;

        try {
            $result = DB::transaction(function () use ($request, $tenantId, $input): array {
                $id = (string) Str::uuid();
                DB::table('media_archive_targets')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $input['slug'],
                    'description' => $input['description'] ?? null, 'target_kind' => 's3_compatible',
                    'endpoint_url' => $input['endpoint_url'], 'region' => $input['region'] ?? null,
                    'bucket' => $input['bucket'], 'object_prefix' => $input['object_prefix'] ?? null,
                    'desired_state' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->emit($request, $tenantId, $id, 'media_archive_target.created', ['target_id' => $id, 'slug' => $input['slug'], 'target_kind' => 's3_compatible', 'bucket' => $input['bucket'], 'endpoint_url' => $input['endpoint_url']]);
                return ['recording_archive_target' => $this->serialize($this->targetForTenant($tenantId, $id))];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) throw new InvalidArgumentException('An archive target with this slug already exists.');
            throw $e;
        }
        if ($key !== null) $this->idempotency->complete('rma-d.targets.create', $key, $result);
        return $result;
    }

    public function updateTarget(Request $request, string $tenantId, string $id, array $input): array
    {
        try {
            return DB::transaction(function () use ($request, $tenantId, $id, $input): array {
            $target = $this->targetForUpdate($tenantId, $id);
            if ($target->desired_state === 'retired') throw new InvalidArgumentException('A retired archive target cannot be changed.');
            $input = $this->validatedTarget($input, false);
            $update = ['updated_by' => $request->user()->id, 'updated_at' => now()];
            foreach (['name', 'description', 'slug', 'endpoint_url', 'region', 'bucket', 'object_prefix'] as $field) if (array_key_exists($field, $input)) $update[$field] = $input[$field];
            if (count($update) === 2) return ['recording_archive_target' => $this->serialize($target)];
            DB::table('media_archive_targets')->where('id', $id)->update($update);
            $this->emit($request, $tenantId, $id, 'media_archive_target.updated', ['target_id' => $id, 'changed_fields' => array_values(array_diff(array_keys($update), ['updated_by', 'updated_at']))]);
            return ['recording_archive_target' => $this->serialize($this->targetForTenant($tenantId, $id))];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) throw new InvalidArgumentException('An archive target with this slug already exists.');
            throw $e;
        }
    }

    public function changeTargetState(Request $request, string $tenantId, string $id, string $state): array
    {
        if (! in_array($state, self::STATES, true)) throw new InvalidArgumentException('Invalid archive target lifecycle state.');
        return DB::transaction(function () use ($request, $tenantId, $id, $state): array {
            $target = $this->targetForUpdate($tenantId, $id);
            if ($target->desired_state === 'retired' && $state !== 'retired') throw new InvalidArgumentException('A retired archive target cannot be reactivated.');
            if ($target->desired_state === $state) throw new InvalidArgumentException('Archive target is already in this lifecycle state.');
            $allowed = ['draft' => ['active', 'retired'], 'active' => ['disabled', 'retired'], 'disabled' => ['active', 'retired'], 'retired' => []];
            if (! in_array($state, $allowed[$target->desired_state] ?? [], true)) throw new InvalidArgumentException('Invalid archive target lifecycle transition.');
            if ($state === 'active' && DB::table('media_archive_credential_references')->where('tenant_id', $tenantId)->where('media_archive_target_id', $id)->exists() === false) throw new InvalidArgumentException('An archive target requires a credential reference before activation.');
            DB::table('media_archive_targets')->where('id', $id)->update(['desired_state' => $state, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $this->emit($request, $tenantId, $id, 'media_archive_target.state_changed', ['target_id' => $id, 'from' => $target->desired_state, 'to' => $state]);
            return ['recording_archive_target' => $this->serialize($this->targetForTenant($tenantId, $id))];
        });
    }

    public function putCredential(Request $request, string $tenantId, string $id, array $input, ?IdempotencyKey $key = null): array
    {
        $identifier = $input['identifier'] ?? null;
        $secret = $input['secret'] ?? null;
        if ($identifier !== null && (! is_string($identifier) || mb_strlen($identifier) > 160)) throw new InvalidArgumentException('Invalid credential identifier.');
        if (! is_string($secret) || mb_strlen($secret) < 8 || mb_strlen($secret) > 4096) throw new InvalidArgumentException('Invalid credential secret.');
        $fingerprint = ['tenant_id' => $tenantId, 'target_id' => $id, 'identifier' => $identifier, 'secret_fingerprint' => hash('sha256', $secret)];
        if ($key !== null && ($existing = $this->beginIdempotent('rma-d.targets.credential', $key, $fingerprint)) !== null) return $existing;
        $result = DB::transaction(function () use ($request, $tenantId, $id, $identifier, $secret): array {
            $target = $this->targetForUpdate($tenantId, $id);
            if ($target->desired_state === 'retired') throw new InvalidArgumentException('A retired archive target cannot receive a credential.');
            $current = DB::table('media_archive_credential_references')->where('tenant_id', $tenantId)->where('media_archive_target_id', $id)->lockForUpdate()->first();
            $credentialId = $current?->id ?? (string) Str::uuid();
            DB::table('media_archive_credential_references')->updateOrInsert(
                ['media_archive_target_id' => $id],
                ['id' => $credentialId, 'tenant_id' => $tenantId, 'identifier' => $identifier, 'encrypted_secret' => Crypt::encryptString($secret), 'secret_fingerprint' => hash('sha256', $secret), 'created_at' => $current?->created_at ?? now(), 'updated_at' => now()],
            );
            $this->emit($request, $tenantId, $id, 'media_archive_target.credential_set', ['target_id' => $id, 'credential_reference_id' => $credentialId, 'identifier' => $identifier, 'secret_fingerprint' => hash('sha256', $secret), 'replaced' => $current !== null]);
            return ['recording_archive_target' => $this->serialize($this->targetForTenant($tenantId, $id))];
        });
        if ($key !== null) $this->idempotency->complete('rma-d.targets.credential', $key, $result);
        return $result;
    }

    private function targetForTenant(string $tenantId, string $id): object
    {
        $row = DB::table('media_archive_targets')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Recording archive target not found.');
        return $row;
    }

    private function targetForUpdate(string $tenantId, string $id): object
    {
        $row = DB::table('media_archive_targets')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row !== null, 404, 'Recording archive target not found.');
        return $row;
    }

    private function serialize(object $row): array
    {
        $credential = DB::table('media_archive_credential_references')->where('tenant_id', $row->tenant_id)->where('media_archive_target_id', $row->id)->first(['id', 'identifier', 'secret_fingerprint']);
        return ['id' => $row->id, 'name' => $row->name, 'slug' => $row->slug, 'description' => $row->description, 'target_kind' => $row->target_kind, 'endpoint_url' => $row->endpoint_url, 'region' => $row->region, 'bucket' => $row->bucket, 'object_prefix' => $row->object_prefix, 'desired_state' => $row->desired_state, 'credential_reference' => $credential === null ? null : ['id' => $credential->id, 'identifier' => $credential->identifier, 'secret_fingerprint' => $credential->secret_fingerprint], 'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
    }

    private function validatedTarget(array $input, bool $create): array
    {
        foreach (['name', 'slug', 'endpoint_url', 'bucket'] as $field) if ($create && (! isset($input[$field]) || trim((string) $input[$field]) === '')) throw new InvalidArgumentException('Archive target '.$field.' is required.');
        foreach (['name' => 160, 'slug' => 100, 'endpoint_url' => 255, 'region' => 64, 'bucket' => 255, 'object_prefix' => 255] as $field => $max) if (array_key_exists($field, $input) && (! is_string($input[$field]) || mb_strlen($input[$field]) > $max)) throw new InvalidArgumentException('Invalid archive target '.$field.'.');
        if (array_key_exists('description', $input) && $input['description'] !== null && (! is_string($input['description']) || mb_strlen($input['description']) > 2000)) throw new InvalidArgumentException('Invalid archive target description.');
        if (isset($input['endpoint_url']) && ! preg_match('/^https?:\/\//', (string) $input['endpoint_url'])) throw new InvalidArgumentException('Archive target endpoint must use HTTP or HTTPS.');
        if (array_key_exists('bucket', $input) && trim((string) $input['bucket']) === '') throw new InvalidArgumentException('Archive target bucket is required.');
        if (isset($input['slug'])) { $input['slug'] = Str::slug((string) $input['slug']); if ($input['slug'] === '') throw new InvalidArgumentException('Invalid archive target slug.'); }
        if (isset($input['target_kind']) && $input['target_kind'] !== 's3_compatible') throw new InvalidArgumentException('Invalid archive target kind.');
        return $input;
    }

    private function emit(Request $request, string $tenantId, string $id, string $event, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $this->audit->append($context, $event, 'media_archive_authority', $id, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, 'media_archive_authority', $id, $payload, $context));
    }

    private function beginIdempotent(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try { $existing = $this->idempotency->begin($scope, $key, $payload); } catch (IdempotencyConflict) { abort(response()->json(['message' => 'Idempotency key conflict.'], 409)); }
        if ($existing === null) return null;
        if ($existing->status === 'completed' && $existing->result !== null) return $existing->result;
        abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'media_archive_targets_tenant_slug_unique');
    }
}
