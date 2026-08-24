<?php

return [
    'runtime_family' => 'freeswitch',
    'adapter_key' => 'freeswitch-esl',
    'credential_type' => 'freeswitch-esl',
    'managed_image' => env('UTCP_MANAGED_FREESWITCH_IMAGE'),
    'request_timeout_ms' => 5000,
];
