<?php

return [
    'catalog_version' => 'c4.2026-07-14',

    'runtime_family' => 'simulator',
    'adapter_key' => 'simulator-deterministic',

    'roles' => [
        'simulator-event-source',
    ],

    'operation_types' => [
        'inspect' => 'runtime.node.inspect',
        'apply_configuration' => 'runtime.node.apply_configuration',
    ],

    'scenarios' => [
        'steady-ready',
        'transient-failure-then-ready',
        'terminal-failure',
        'timeout-then-ready',
        'duplicate-observation',
        'disconnect-reconnect',
        'configuration-drift-then-converge',
    ],

    'event_types' => [
        'connection_opened' => 'simulator.connection.opened',
        'connection_closed' => 'simulator.connection.closed',
        'readiness_changed' => 'simulator.readiness.changed',
        'capabilities_observed' => 'simulator.capabilities.observed',
        'configuration_observed' => 'simulator.configuration.observed',
    ],

    'batch_size' => env('UTCP_SIMULATOR_BATCH_SIZE', 10),
    'lease_seconds' => env('UTCP_SIMULATOR_LEASE_SECONDS', 60),
    'poll_seconds' => env('UTCP_SIMULATOR_POLL_SECONDS', 3),
];
