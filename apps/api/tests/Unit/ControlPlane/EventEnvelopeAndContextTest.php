<?php

namespace Tests\Unit\ControlPlane;

use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RequestId;
use Illuminate\Http\Request;
use Tests\TestCase;

final class EventEnvelopeAndContextTest extends TestCase
{
    public function test_execution_context_accepts_only_valid_request_ids_from_http(): void
    {
        $request = Request::create('/api/version', 'GET', server: [
            'HTTP_X_REQUEST_ID' => str_repeat('a', 32),
        ]);

        $context = ExecutionContext::fromRequest($request);

        $this->assertSame(str_repeat('a', 32), $context->requestId->value());
        $this->assertSame('system', $context->actorType);
        $this->assertNull($context->tenantId);
    }

    public function test_execution_context_generates_request_id_when_header_is_untrusted(): void
    {
        $request = Request::create('/api/version', 'GET', server: [
            'HTTP_X_REQUEST_ID' => "bad\nheader",
        ]);

        $context = ExecutionContext::fromRequest($request);

        $this->assertNotSame("bad\nheader", $context->requestId->value());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $context->requestId->value());
    }

    public function test_event_envelope_serialization_is_deterministic_and_runtime_neutral(): void
    {
        $context = ExecutionContext::system(RequestId::fromString(str_repeat('b', 32)));
        $event = EventEnvelope::forAggregate(
            'test.operation.accepted',
            1,
            'test.aggregate',
            'aggregate-1',
            ['z' => 1, 'a' => ['b' => true]],
            $context,
        );

        $this->assertStringContainsString('"a":{"b":true}', $event->stablePayload());
        $this->assertStringContainsString('"z":1', $event->stablePayload());
        $this->assertSame('test.operation.accepted', $event->safeLogContext()['event_type']);
        $this->assertArrayNotHasKey('payload', $event->safeLogContext());
    }
}
