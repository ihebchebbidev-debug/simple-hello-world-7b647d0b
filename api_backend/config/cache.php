<?php

/**
 * Cache config.
 *
 * File store by default — works on every host without extra setup. A
 * `database` store is wired so we can switch to it (or to redis) without
 * touching application code, just by flipping `CACHE_STORE`.
 */

declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],
        'file' => [
            'driver'    => 'file',
            'path'      => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
        'database' => [
            'driver'          => 'database',
            'table'           => 'cache',
            'connection'      => env('DB_CACHE_CONNECTION'),
            'lock_table'      => 'cache_locks',
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'agritrack_cache_'),
];
