<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Random\RandomException;
use Symfony\Component\HttpFoundation\Response;

final class RequestIdMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws RandomException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        if ($request->is('api/*')) {
            Log::info('api request completed', array_filter([
                'timestamp' => now()->toJSON(),
                'service' => 'utcp',
                'component' => 'api',
                'environment' => config('app.env'),
                'request_id' => $requestId,
                'route' => $request->path(),
                'method' => $request->method(),
                'status_code' => $response->getStatusCode(),
            ], static fn ($value): bool => $value !== null && $value !== ''));
        }

        return $response;
    }

    /**
     * @throws RandomException
     */
    private function requestId(Request $request): string
    {
        $candidate = $request->headers->get('X-Request-ID');

        if (is_string($candidate) && preg_match('/\A[a-f0-9]{32}\z/i', $candidate) === 1) {
            return strtolower($candidate);
        }

        return bin2hex(random_bytes(16));
    }
}
