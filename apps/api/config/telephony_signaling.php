<?php

return [
    'realm' => env('TELEPHONY_SIGNALING_REALM', 'sip.utcp.local.test'),
    'wss_uri' => env('TELEPHONY_SIGNALING_WSS_URI', 'wss://sip.utcp.local.test/ws'),
    'credential_lifetime_seconds' => (int) env('TELEPHONY_SIGNALING_CREDENTIAL_LIFETIME_SECONDS', 120),
    'contact_max_expires_seconds' => (int) env('TELEPHONY_SIGNALING_CONTACT_MAX_EXPIRES_SECONDS', 120),
];
