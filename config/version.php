<?php

return [
    'application_name' => env('APP_DISPLAY_NAME', 'Prime Healers OS'),
    'version' => env('APP_VERSION', 'v1.0.0-rc1'),
    'build' => env('APP_BUILD', '20260707'),
    'release_date' => env('APP_RELEASE_DATE', '2026-07-07'),
    'environment' => env('APP_RELEASE_ENV', env('APP_ENV', 'production')),
    'git_commit' => env('APP_GIT_COMMIT'),
    'branch' => env('APP_GIT_BRANCH'),
    'whats_new' => [
        'Current Release',
        'UI Stabilization',
        'Inventory Intelligence redesign',
        'Product Master redesign',
        'Asset Register redesign',
        'Stock History redesign',
        'Dashboard refinement',
        'Business Partner redesign',
        'Import Wizard redesign',
    ],
];
