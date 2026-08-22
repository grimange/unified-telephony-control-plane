<?php

return [
    'catalog_version' => 'c5.2026-07-15',

    'runtime_family' => 'simulator',
    'adapter_key' => 'simulator-deterministic',

    'roles' => [
        'simulator-event-source',
    ],

    'operation_types' => [
        'inspect' => 'runtime.node.inspect',
        'apply_configuration' => 'runtime.node.apply_configuration',
        'conference_ensure' => 'conference.ensure',
        'conference_close' => 'conference.close',
        'participant_ensure' => 'conference.participant.ensure',
        'participant_remove' => 'conference.participant.remove',
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
        'conference_ready' => 'simulator.conference.ready',
        'conference_closed' => 'simulator.conference.closed',
        'conference_degraded' => 'simulator.conference.degraded',
        'participant_joined' => 'simulator.conference.participant_joined',
        'participant_left' => 'simulator.conference.participant_left',
        'participant_failed' => 'simulator.conference.participant_failed',
        'call_observation' => 'simulator.call.observation',
    ],

    'batch_size' => env('UTCP_SIMULATOR_BATCH_SIZE', 10),
    'lease_seconds' => env('UTCP_SIMULATOR_LEASE_SECONDS', 60),
    'poll_seconds' => env('UTCP_SIMULATOR_POLL_SECONDS', 3),
];
