<?php

namespace App\ControlPlane\RuntimeOperations;

use Illuminate\Support\Facades\Log;

final class OperationLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context): void
    {
        Log::info($message, array_filter([
            'component' => 'runtime_operations',
            'operation_id' => $context['operation_id'] ?? null,
            'operation_type' => $context['operation_type'] ?? null,
            'status' => $context['status'] ?? null,
            'attempt' => $context['attempt'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'failure_class' => $context['failure_class'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }
}
