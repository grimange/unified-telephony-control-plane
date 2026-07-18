<?php

return [
    'catalog_version' => 'c3.2026-07-14',

    'roles' => [
        'control-plane-outbox-dispatcher',
        'telephony-command-worker',
        'telephony-infrastructure-worker',
        'telephony-event-normalizer',
        'telephony-reconciler',
        'asterisk-ari-events',
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
    'kubernetes' => [
        'service_host' => env('KUBERNETES_SERVICE_HOST'),
        'service_port' => env('KUBERNETES_SERVICE_PORT_HTTPS', env('KUBERNETES_SERVICE_PORT', 443)),
        'token_path' => env('KUBERNETES_SERVICEACCOUNT_TOKEN_PATH', '/var/run/secrets/kubernetes.io/serviceaccount/token'),
        'ca_path' => env('KUBERNETES_SERVICEACCOUNT_CA_PATH', '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt'),
        'connect_timeout_seconds' => env('UTCP_KUBERNETES_CONNECT_TIMEOUT_SECONDS', 2),
        'request_timeout_seconds' => env('UTCP_KUBERNETES_REQUEST_TIMEOUT_SECONDS', 5),
    ],
];
