<?php

return [
    'catalog_version' => 'c2.2026-07-14',

    'capabilities' => [
        'platform.tenants.view' => ['scope' => 'platform', 'description' => 'View tenants'],
        'platform.tenants.manage' => ['scope' => 'platform', 'description' => 'Manage tenants'],
        'platform.users.view' => ['scope' => 'platform', 'description' => 'View users'],
        'platform.users.manage' => ['scope' => 'platform', 'description' => 'Manage users'],
        'tenant.memberships.view' => ['scope' => 'tenant', 'description' => 'View tenant memberships'],
        'tenant.memberships.manage' => ['scope' => 'tenant', 'description' => 'Manage tenant memberships'],
        'tenant.roles.view' => ['scope' => 'tenant', 'description' => 'View tenant roles'],
        'tenant.roles.assign' => ['scope' => 'tenant', 'description' => 'Assign tenant roles'],
        'runtime.nodes.view' => ['scope' => 'tenant', 'description' => 'View tenant runtime-node registry configuration'],
        'runtime.nodes.manage' => ['scope' => 'tenant', 'description' => 'Manage tenant runtime-node registry configuration'],
        'runtime.credentials.rotate' => ['scope' => 'tenant', 'description' => 'Create, rotate, and retire runtime-node credentials'],
    ],

    'roles' => [
        'platform-admin' => [
            'scope' => 'platform',
            'display_name' => 'Platform administrator',
            'capabilities' => [
                'platform.tenants.view',
                'platform.tenants.manage',
                'platform.users.view',
                'platform.users.manage',
            ],
        ],
        'tenant-admin' => [
            'scope' => 'tenant',
            'display_name' => 'Tenant administrator',
            'capabilities' => [
                'tenant.memberships.view',
                'tenant.memberships.manage',
                'tenant.roles.view',
                'tenant.roles.assign',
                'runtime.nodes.view',
                'runtime.nodes.manage',
                'runtime.credentials.rotate',
            ],
        ],
        'tenant-member' => [
            'scope' => 'tenant',
            'display_name' => 'Tenant member',
            'capabilities' => [
                'tenant.memberships.view',
                'tenant.roles.view',
            ],
        ],
    ],
];
