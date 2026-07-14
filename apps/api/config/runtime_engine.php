<?php

return [
    'catalog_version' => 'c3.2026-07-14',

    'roles' => [
        'control-plane-outbox-dispatcher',
        'telephony-command-worker',
        'telephony-event-normalizer',
        'telephony-reconciler',
    ],

    'observed_states' => [
        'unobserved',
        'unknown',
        'connecting',
        'ready',
        'degraded',
        'unavailable',
        'stale',
    ],

    'observation_types' => [
        'runtime.connection.observed',
        'runtime.readiness.observed',
        'runtime.capability.observed',
        'runtime.configuration.observed',
        'runtime.resource.observed',
        'runtime.resource.removed',
    ],

    'retryable_failure_classes' => [
        'transient_transport',
        'runtime_unavailable',
        'timeout',
        'internal_error',
    ],

    'terminal_failure_classes' => [
        'invalid_request',
        'unsupported_capability',
        'authentication_failed',
        'authorization_failed',
        'conflict',
        'cancelled',
        'expired',
    ],

    'batch_size' => env('UTCP_RUNTIME_ENGINE_BATCH_SIZE', 10),
    'lease_seconds' => env('UTCP_RUNTIME_ENGINE_LEASE_SECONDS', 60),
    'poll_seconds' => env('UTCP_RUNTIME_ENGINE_POLL_SECONDS', 3),
    'stale_observation_seconds' => env('UTCP_RUNTIME_ENGINE_STALE_OBSERVATION_SECONDS', 300),
];
