<?php

return [
    'enabled' => env('LARAVEL_HOOKS_ENABLED', true),
    'debug' => env('LARAVEL_HOOKS_DEBUG', false),
    'queue_connection' => env('LARAVEL_HOOKS_QUEUE_CONNECTION', null),
    'default_queue' => env('LARAVEL_HOOKS_DEFAULT_QUEUE', 'default'),
    'batch_queue' => env('LARAVEL_HOOKS_BATCH_QUEUE', 'batch'),
    'include_request_metadata' => env('LARAVEL_HOOKS_INCLUDE_REQUEST_METADATA', false),
    'batch_cache_ttl_multiplier' => env('LARAVEL_HOOKS_BATCH_CACHE_TTL_MULTIPLIER', 3),
    'batch_cache_min_ttl' => env('LARAVEL_HOOKS_BATCH_CACHE_MIN_TTL', 300),
    'generation_directory' => 'App\\Hooks',
    'default_service_hooks' => [
        'global' => true,
        'performance' => false,
        'caching' => false,
    ],
    'global_hooks' => [],
];
