<?php

return [
    'catalog_version' => 'c5.2026-07-15',

    'session_statuses' => ['pending', 'active', 'ending', 'ended', 'expired', 'failed'],
    'conference_desired_states' => ['draft', 'open', 'draining', 'closed'],
    'conference_observed_states' => ['unobserved', 'provisioning', 'ready', 'degraded', 'unavailable', 'closed'],
    'participant_desired_states' => ['admitted', 'removed'],
    'participant_observed_states' => ['unobserved', 'joining', 'joined', 'leaving', 'left', 'failed'],
    'participant_roles' => ['host', 'participant'],

    'session_lifetime_minutes' => env('UTCP_TELEPHONY_SESSION_LIFETIME_MINUTES', 60),

    'operation_types' => [
        'conference_ensure' => 'conference.ensure',
        'conference_close' => 'conference.close',
        'participant_ensure' => 'conference.participant.ensure',
        'participant_remove' => 'conference.participant.remove',
        'verify_conference_absent' => 'runtime.node.verify_conference_absent',
        'runtime_fence' => 'runtime.node.runtime.fence',
        'runtime_node_restore' => 'runtime.node.restore',
        'runtime_node_decommission' => 'runtime.node.decommission',
        'runtime_node_provision' => 'runtime.node.provision',
        'runtime_node_deprovision' => 'runtime.node.deprovision',
    ],

    'operation_max_attempts' => [
        'runtime_node_restore' => 8,
        'runtime_node_decommission' => 8,
        'runtime_node_provision' => 8,
        'runtime_node_deprovision' => 8,
    ],

    'runtime_capabilities' => [
        'conference_lifecycle' => 'conference.lifecycle',
        'conference_participation' => 'conference.participation',
    ],
];
