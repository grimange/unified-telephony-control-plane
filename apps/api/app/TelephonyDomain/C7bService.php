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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class C7bService
{
    private const STATES = ['draft', 'active', 'disabled', 'retired'];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
        private readonly C7aService $c7a,
    ) {}

    public function listInbound(string $tenantId): array
    {
        return DB::table('inbound_routes')->where('tenant_id', $tenantId)->orderBy('priority')->orderBy('id')->get()->map(fn (object $row): array => $this->serialize($row, 'inbound'))->all();
    }

    public function listOutbound(string $tenantId): array
    {
        return DB::table('outbound_routes')->where('tenant_id', $tenantId)->orderBy('priority')->orderBy('id')->get()->map(fn (object $row): array => $this->serialize($row, 'outbound'))->all();
    }

    public function createInbound(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $destination = DestinationRef::from($input['destination_ref']);
        $this->address($tenantId, $input['telephony_address_id']);
        $this->trunk($tenantId, $input['external_trunk_id']);
        $fingerprint = ['tenant_id' => $tenantId, 'slug' => Str::slug($input['slug']), 'trunk' => $input['external_trunk_id'], 'address' => $input['telephony_address_id'], 'destination' => $destination->canonical()];
        if ($key !== null && ($existing = $this->begin('c7b.inbound_routes.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        try {
            $result = DB::transaction(function () use ($request, $tenantId, $input, $destination): array {
                $id = (string) Str::uuid();
                $slug = $this->slug($input['slug']);
                $this->assertInboundAssociation($tenantId, $input['external_trunk_id'], $input['telephony_address_id']);
                if ($destination->type() === 'telephony_address') {
                    $this->address($tenantId, $destination->value());
                }
                DB::table('inbound_routes')->insert($this->routeValues($request, $tenantId, $input, $id, $destination->canonical(), null, $slug));
                $this->emit($request, $tenantId, $id, 'inbound_route.created', ['route_type' => 'inbound', 'priority' => (int) ($input['priority'] ?? 100)]);

                return ['inbound_route' => $this->serialize($this->row('inbound_routes', $tenantId, $id), 'inbound')];
            });
        } catch (QueryException $e) {
            $this->duplicate($e, 'An inbound route with this slug already exists.');
            throw $e;
        }
        if ($key !== null) {
            $this->idempotency->complete('c7b.inbound_routes.create', $key, $result);
        }

        return $result;
    }

    public function createOutbound(Request $request, string $tenantId, array $input, ?IdempotencyKey $key = null): array
    {
        $this->address($tenantId, $input['telephony_address_id']);
        $this->trunk($tenantId, $input['external_trunk_id']);
        if (($input['caller_identity_id'] ?? null) !== null) {
            $this->identity($tenantId, $input['caller_identity_id']);
        }
        $fingerprint = ['tenant_id' => $tenantId, 'slug' => Str::slug($input['slug']), 'trunk' => $input['external_trunk_id'], 'address' => $input['telephony_address_id'], 'caller' => $input['caller_identity_id'] ?? null];
        if ($key !== null && ($existing = $this->begin('c7b.outbound_routes.create', $key, $fingerprint)) !== null) {
            return $existing;
        }
        try {
            $result = DB::transaction(function () use ($request, $tenantId, $input): array {
                $id = (string) Str::uuid();
                $slug = $this->slug($input['slug']);
                $this->assertOutboundAssociation($tenantId, $input['external_trunk_id'], $input['telephony_address_id'], $input['caller_identity_id'] ?? null);
                DB::table('outbound_routes')->insert($this->routeValues($request, $tenantId, $input, $id, null, $input['caller_identity_id'] ?? null, $slug));
                $this->emit($request, $tenantId, $id, 'outbound_route.created', ['route_type' => 'outbound', 'priority' => (int) ($input['priority'] ?? 100)]);

                return ['outbound_route' => $this->serialize($this->row('outbound_routes', $tenantId, $id), 'outbound')];
            });
        } catch (QueryException $e) {
            $this->duplicate($e, 'An outbound route with this slug already exists.');
            throw $e;
        }
        if ($key !== null) {
            $this->idempotency->complete('c7b.outbound_routes.create', $key, $result);
        }

        return $result;
    }

    public function changeState(Request $request, string $tenantId, string $kind, string $id, string $state): array
    {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Invalid route lifecycle state.');
        }
        $table = $this->table($kind);
        $row = $this->row($table, $tenantId, $id);
        if ($row->desired_state === 'retired' && $state !== 'retired') {
            throw new InvalidArgumentException('A retired route cannot be reactivated.');
        }
        if ($state === 'active') {
            $this->assertRouteCanActivate($tenantId, $kind, $row);
        }
        DB::table($table)->where('id', $id)->update(['desired_state' => $state, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $this->emit($request, $tenantId, $id, $kind.'_route.desired_state_changed', ['route_type' => $kind, 'from' => $row->desired_state, 'to' => $state]);

        return [$kind.'_route' => $this->serialize($this->row($table, $tenantId, $id), $kind)];
    }

    public function evaluateInbound(string $tenantId, string $trunkId, string $addressId): RouteDecision
    {
        $address = $this->address($tenantId, $addressId);
        $this->trunk($tenantId, $trunkId);
        $candidates = DB::table('inbound_routes')->where('tenant_id', $tenantId)->where('external_trunk_id', $trunkId)->where('telephony_address_id', $addressId)->where('desired_state', 'active')->orderBy('priority')->orderBy('id')->get();
        $rejections = [];
        foreach ($candidates as $route) {
            $checks = [RouteConstraint::passed('route_active')];
            if (! $this->directionAllowed($trunkId, $addressId, 'inbound')) {
                $rejections[] = RouteConstraint::failed('address_direction_denied', 'The trunk/address association is not inbound-capable.');

                continue;
            }
            $eligibility = $this->c7a->routingEligibility($tenantId, $trunkId, 'inbound');
            if (! $eligibility['eligible']) {
                $rejections[] = RouteConstraint::failed($eligibility['code'], 'External trunk is not eligible.');

                continue;
            }
            $checks[] = RouteConstraint::passed($eligibility['code']);

            return RouteDecision::selected('inbound', $route->id, $trunkId, DestinationRef::from($route->destination_ref), null, $checks);
        }

        return RouteDecision::failed('inbound', $candidates->isEmpty() ? 'no_matching_route' : 'no_eligible_route', $rejections);
    }

    public function evaluateOutbound(string $tenantId, mixed $destination, ?string $callerIdentityId = null): RouteDecision
    {
        try {
            $destinationRef = DestinationRef::from($destination);
            $addressId = $this->addressIdFromDestination($tenantId, $destinationRef);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'destination_not_routable') {
                return RouteDecision::failed('outbound', 'destination_not_routable');
            }

            return RouteDecision::failed('outbound', 'destination_invalid');
        }
        $query = DB::table('outbound_routes')->where('tenant_id', $tenantId)->where('telephony_address_id', $addressId)->where('desired_state', 'active')->orderBy('priority')->orderBy('id');
        $candidates = $query->get();
        $rejections = [];
        foreach ($candidates as $route) {
            $checks = [RouteConstraint::passed('route_active')];
            if (! $this->directionAllowed($route->external_trunk_id, $addressId, 'outbound')) {
                $rejections[] = RouteConstraint::failed('address_direction_denied', 'The trunk/address association is not outbound-capable.');

                continue;
            }
            $eligibility = $this->c7a->routingEligibility($tenantId, $route->external_trunk_id, 'outbound');
            if (! $eligibility['eligible']) {
                $rejections[] = RouteConstraint::failed($eligibility['code'], 'External trunk is not eligible.');

                continue;
            }
            $identityId = $callerIdentityId ?? $route->caller_identity_id;
            if ($identityId === null) {
                $rejections[] = RouteConstraint::failed('caller_identity_required', 'An authorized caller identity is required.');

                continue;
            }
            if (! $this->callerAllowed($tenantId, $identityId, $route->external_trunk_id)) {
                $rejections[] = RouteConstraint::failed('caller_identity_not_authorized', 'Caller identity is not authorized on this external trunk.');

                continue;
            }
            if ($route->caller_identity_id !== null && $callerIdentityId !== null && $route->caller_identity_id !== $callerIdentityId) {
                $rejections[] = RouteConstraint::failed('caller_identity_route_mismatch', 'Requested caller identity does not match the route.');

                continue;
            }
            $checks[] = RouteConstraint::passed($eligibility['code']);
            $checks[] = RouteConstraint::passed('caller_identity_authorized');

            return RouteDecision::selected('outbound', $route->id, $route->external_trunk_id, $destinationRef, $identityId, $checks);
        }

        return RouteDecision::failed('outbound', $candidates->isEmpty() ? 'no_matching_route' : 'no_eligible_route', $rejections);
    }

    /** Resolve the execution endpoint after a route has already been selected. */
    public function resolveOutboundEndpoint(string $tenantId, RouteDecision $decision): object
    {
        if (! $decision->isSelected() || $decision->externalTrunkId() === null) {
            throw new InvalidArgumentException('outbound_route_not_selected');
        }
        $endpoints = DB::table('trunk_endpoints')
            ->where('tenant_id', $tenantId)
            ->where('external_trunk_id', $decision->externalTrunkId())
            ->where('desired_state', 'active')
            ->orderBy('priority')->orderBy('id')->get()
            ->filter(function (object $endpoint): bool {
                if (($endpoint->signaling_mode ?? 'static') !== 'outbound_registration') {
                    return true;
                }

                return DB::table('external_trunk_registration_observations')
                    ->where('trunk_endpoint_id', $endpoint->id)
                    ->where('state', 'registered')->exists();
            })->values();
        if ($endpoints->count() === 0) {
            throw new InvalidArgumentException('outbound_endpoint_unavailable');
        }
        if ($endpoints->count() !== 1) {
            throw new InvalidArgumentException('outbound_endpoint_ambiguous');
        }

        return $endpoints->first();
    }

    public function executionDestination(string $tenantId, RouteDecision $decision): string
    {
        $destination = $decision->destination();
        if ($destination === null) {
            throw new InvalidArgumentException('outbound_destination_missing');
        }
        if ($destination->type() === 'opaque') {
            return $destination->value();
        }
        $value = DB::table('telephony_addresses')
            ->where('tenant_id', $tenantId)->where('id', $destination->value())
            ->value('normalized_value');
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('outbound_destination_missing');
        }

        return trim($value);
    }

    private function routeValues(Request $request, string $tenantId, array $input, string $id, ?string $destination, ?string $caller, string $slug): array
    {
        return ['id' => $id, 'tenant_id' => $tenantId, 'name' => $input['name'], 'slug' => $slug, 'external_trunk_id' => $input['external_trunk_id'], 'telephony_address_id' => $input['telephony_address_id'], 'destination_ref' => $destination, 'caller_identity_id' => $caller, 'priority' => (int) ($input['priority'] ?? 100), 'desired_state' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function assertInboundAssociation(string $tenantId, string $trunkId, string $addressId): void
    {
        if (! $this->directionAllowed($trunkId, $addressId, 'inbound')) {
            throw new InvalidArgumentException('The trunk/address association is not inbound-capable.');
        }
    }

    private function assertOutboundAssociation(string $tenantId, string $trunkId, string $addressId, ?string $identityId): void
    {
        if (! $this->directionAllowed($trunkId, $addressId, 'outbound')) {
            throw new InvalidArgumentException('The trunk/address association is not outbound-capable.');
        } if ($identityId !== null && ! $this->callerAllowed($tenantId, $identityId, $trunkId)) {
            throw new InvalidArgumentException('Caller identity is not authorized on this external trunk.');
        }
    }

    private function assertRouteCanActivate(string $tenantId, string $kind, object $route): void
    {
        $this->address($tenantId, $route->telephony_address_id);
        $this->trunk($tenantId, $route->external_trunk_id);
        if ($kind === 'inbound') {
            $this->assertInboundAssociation($tenantId, $route->external_trunk_id, $route->telephony_address_id);
        } else {
            $this->assertOutboundAssociation($tenantId, $route->external_trunk_id, $route->telephony_address_id, $route->caller_identity_id);
        }
    }

    private function callerAllowed(string $tenantId, string $identityId, string $trunkId): bool
    {
        return DB::table('caller_identities as i')->join('telephony_addresses as a', 'a.id', '=', 'i.telephony_address_id')->where('i.tenant_id', $tenantId)->where('i.id', $identityId)->where('i.desired_state', 'active')->where('a.desired_state', 'active')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('caller_identity_policies as p')->whereColumn('p.caller_identity_id', 'i.id')->where('p.external_trunk_id', $trunkId)->where('p.tenant_id', $tenantId)->where('p.desired_state', 'active'))->exists();
    }

    private function directionAllowed(string $trunkId, string $addressId, string $direction): bool
    {
        return DB::table('external_trunk_addresses')->where('external_trunk_id', $trunkId)->where('telephony_address_id', $addressId)->whereIn('direction', [$direction, 'both'])->exists();
    }

    private function addressIdFromDestination(string $tenantId, DestinationRef $destination): string
    {
        if ($destination->type() !== 'telephony_address') {
            throw new InvalidArgumentException('destination_not_routable');
        } $this->address($tenantId, $destination->value());

        return $destination->value();
    }

    private function identity(string $tenantId, string $id): object
    {
        $row = DB::table('caller_identities')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Caller identity not found.');

        return $row;
    }

    private function address(string $tenantId, string $id): object
    {
        $row = DB::table('telephony_addresses')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Telephony address not found.');

        return $row;
    }

    private function trunk(string $tenantId, string $id): object
    {
        $row = DB::table('external_trunks')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'External trunk not found.');

        return $row;
    }

    private function row(string $table, string $tenantId, string $id): object
    {
        $row = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Route not found.');

        return $row;
    }

    private function table(string $kind): string
    {
        if (! in_array($kind, ['inbound', 'outbound'], true)) {
            throw new InvalidArgumentException('Invalid route kind.');
        }

return $kind.'_routes';
    }

    private function serialize(object $row, string $kind): array
    {
        return ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'slug' => $row->slug, 'external_trunk_id' => $row->external_trunk_id, 'telephony_address_id' => $row->telephony_address_id, 'destination_ref' => $row->destination_ref === null ? null : DestinationRef::from($row->destination_ref)->toArray(), 'caller_identity_id' => $row->caller_identity_id, 'priority' => (int) $row->priority, 'desired_state' => $row->desired_state, 'direction' => $kind];
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value);
        if ($slug === '' || strlen($slug) > 100) {
            throw new InvalidArgumentException('Invalid route slug.');
        }

return $slug;
    }

    private function emit(Request $request, string $tenantId, string $id, string $event, array $payload): void
    {
        $context = IdentityContext::fromRequest($request, $tenantId);
        $this->audit->append($context, $event, 'c7b_route', $id, $payload);
        $this->outbox->append(EventEnvelope::forAggregate($event, 1, 'c7b_route', $id, $payload, $context));
    }

    private function begin(string $scope, IdempotencyKey $key, array $payload): ?array
    {
        try {
            $existing = $this->idempotency->begin($scope, $key, $payload);
        } catch (IdempotencyConflict) {
            abort(response()->json(['message' => 'Idempotency key conflict.'], 409));
        } if ($existing === null) {
            return null;
        } if ($existing->status === 'completed' && $existing->result !== null) {
            return $existing->result;
        } abort(response()->json(['message' => 'Request is already in progress.'], 409));
    }

    private function duplicate(QueryException $exception, string $message): void
    {
        if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
            throw new InvalidArgumentException($message, 0, $exception);
        }
    }
}
