<?php

/**
 * CORS config.
 *
 * Reads the comma-separated `CORS_ALLOWED_ORIGINS` env var so each
 * environment can whitelist its own front-end without code changes.
 * Credentials are enabled to keep the door open for cookie-based auth.
 */

declare(strict_types=1);

return [
    'paths'                    => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => false,
];
