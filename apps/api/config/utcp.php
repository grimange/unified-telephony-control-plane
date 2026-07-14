<?php

$requiredDependencies = array_values(array_filter(array_map(
    static fn (string $dependency): string => trim($dependency),
    explode(',', (string) env('UTCP_READINESS_REQUIRED_DEPENDENCIES', ''))
)));

return [
    'service' => [
        'name' => env('UTCP_SERVICE_NAME', 'utcp-api'),
    ],

    'build' => [
        'version' => env('UTCP_APP_VERSION', '0.1.0-dev'),
        'commit' => env('UTCP_BUILD_COMMIT', 'unknown'),
        'built_at' => env('UTCP_BUILD_TIMESTAMP', 'unknown'),
    ],

    'readiness' => [
        'required_dependencies' => $requiredDependencies,
    ],
];
