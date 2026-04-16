<?php

return [
    'enabled' => env('LARAVEL_HOOKS_ENABLED', true),
    'debug' => env('LARAVEL_HOOKS_DEBUG', false),
    'queue_connection' => env('LARAVEL_HOOKS_QUEUE_CONNECTION', null),
    'default_queue' => env('LARAVEL_HOOKS_DEFAULT_QUEUE', 'default'),
    'batch_queue' => env('LARAVEL_HOOKS_BATCH_QUEUE', 'batch'),
    'generation_directory' => 'App\\Hooks',
    'default_service_hooks' => [
        'global' => true,
        'performance' => false,
        'caching' => false,
    ],
    'global_hooks' => [],
];
