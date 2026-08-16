<?php

return [
    'catalog_version' => 'c5.2026-07-15',

    'runtime_families' => [
        'asterisk' => [
            'display_name' => 'Asterisk',
            'adapter_keys' => ['asterisk-ari'],
        ],
        'freeswitch' => [
            'display_name' => 'FreeSWITCH',
            'adapter_keys' => ['freeswitch-esl'],
        ],
        'simulator' => [
            'display_name' => 'Deterministic simulator',
            'adapter_keys' => ['simulator-deterministic'],
        ],
    ],

    'adapter_keys' => [
        'asterisk-ari' => [
            'runtime_family' => 'asterisk',
            'display_name' => 'Asterisk ARI',
            'description' => 'Asterisk ARI observation and conference execution adapter',
            'supported_capabilities' => ['event.stream', 'runtime.observation', 'conference.lifecycle', 'conference.participation'],
            'required_capabilities' => ['event.stream', 'runtime.observation'],
            'endpoint_requirements' => [
                ['purpose' => 'control', 'transports' => ['http', 'https'], 'required' => true],
                ['purpose' => 'events', 'transports' => ['ws', 'wss'], 'required' => true],
            ],
            'credentials_required' => true,
            'adapter_configuration_available' => true,
        ],
        'freeswitch-esl' => [
            'runtime_family' => 'freeswitch',
            'display_name' => 'FreeSWITCH ESL',
            'description' => 'Planned FreeSWITCH ESL adapter',
            'supported_capabilities' => [],
            'required_capabilities' => [],
            'endpoint_requirements' => [],
            'credentials_required' => true,
            'adapter_configuration_available' => false,
        ],
        'simulator-deterministic' => [
            'runtime_family' => 'simulator',
            'display_name' => 'Deterministic simulator',
            'description' => 'Deterministic runtime-neutral simulator',
            'supported_capabilities' => ['event.stream', 'runtime.configuration', 'runtime.observation', 'conference.lifecycle', 'conference.participation'],
            'required_capabilities' => ['event.stream', 'runtime.configuration', 'runtime.observation'],
            'endpoint_requirements' => [],
            'credentials_required' => false,
            'adapter_configuration_available' => true,
        ],
    ],

    'desired_states' => ['draft', 'active', 'draining', 'drained', 'disabled', 'retired'],
    'observed_states' => ['unobserved', 'unknown'],

    'endpoint_purposes' => ['control', 'events', 'health', 'sip'],
    'endpoint_transports' => ['http', 'https', 'tcp', 'tls', 'udp', 'ws', 'wss'],
    'endpoint_tls_modes' => ['disabled', 'opportunistic', 'required', 'verify'],

    'runtime_capabilities' => [
        'conference.execution' => 'Conference execution',
        'conference.lifecycle' => 'Conference lifecycle',
        'conference.participation' => 'Conference participation',
        'channel.control' => 'Channel control',
        'event.stream' => 'Runtime event stream',
        'runtime.observation' => 'Runtime observation',
        'runtime.configuration' => 'Runtime configuration',
        'registration.observation' => 'Registration observation',
        'recording' => 'Recording support',
    ],
];
