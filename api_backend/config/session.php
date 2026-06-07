<?php

/**
 * Session config.
 *
 * Sessions are barely used (the API is token-based) but Laravel still
 * needs a working store for things like flash data and the password
 * reset broker. File driver keeps it dependency-free.
 */

declare(strict_types=1);

return [
    'driver'          => env('SESSION_DRIVER', 'file'),
    'lifetime'        => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt'         => false,
    'files'           => storage_path('framework/sessions'),
    'connection'      => env('SESSION_CONNECTION'),
    'table'           => 'sessions',
    'store'           => env('SESSION_STORE'),
    'lottery'         => [2, 100],
    'cookie'          => env('SESSION_COOKIE', 'agritrack_session'),
    'path'            => '/',
    'domain'          => env('SESSION_DOMAIN'),
    'secure'          => env('SESSION_SECURE_COOKIE'),
    'http_only'       => true,
    'same_site'       => 'lax',
    'partitioned'     => false,
];
