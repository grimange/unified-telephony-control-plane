<?php

namespace App\Identity;

use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Http\Request;

final class IdentityContext
{
    public static function fromRequest(Request $request, ?string $tenantId = null): ExecutionContext
    {
        $base = ExecutionContext::fromRequest($request);
        $user = $request->user();

        return new ExecutionContext(
            requestId: $base->requestId,
            correlationId: $base->correlationId,
            causationId: $base->causationId,
            actorType: $user === null ? 'anonymous' : 'user',
            actorId: $user?->getAuthIdentifier(),
            tenantId: $tenantId,
            reason: null,
            origin: 'http',
            occurredAt: $base->occurredAt,
        );
    }
}
