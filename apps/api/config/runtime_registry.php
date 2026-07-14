<?php

return [
    'catalog_version' => 'c4.2026-07-14',

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
        ],
        'freeswitch-esl' => [
            'runtime_family' => 'freeswitch',
            'display_name' => 'FreeSWITCH ESL',
        ],
        'simulator-deterministic' => [
            'runtime_family' => 'simulator',
            'display_name' => 'Deterministic simulator',
        ],
    ],

    'desired_states' => ['draft', 'active', 'draining', 'disabled'],
    'observed_states' => ['unobserved', 'unknown'],

    'endpoint_purposes' => ['control', 'events', 'health'],
    'endpoint_transports' => ['http', 'https', 'tcp', 'tls', 'ws', 'wss'],
    'endpoint_tls_modes' => ['disabled', 'opportunistic', 'required', 'verify'],

    'runtime_capabilities' => [
        'conference.execution' => 'Conference execution',
        'channel.control' => 'Channel control',
        'event.stream' => 'Runtime event stream',
        'runtime.observation' => 'Runtime observation',
        'runtime.configuration' => 'Runtime configuration',
        'registration.observation' => 'Registration observation',
        'recording' => 'Recording support',
    ],
];
