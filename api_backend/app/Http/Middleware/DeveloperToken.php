<?php

/**
 * Guards the /developer/* endpoints with a single shared token.
 *
 * The dashboard is intentionally signup-free — a developer just pastes
 * the token configured in the DEVELOPER_ACCESS_TOKEN env var.
 *
 * Token can be supplied via header `X-Developer-Token` or `?token=` query.
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeveloperToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('DEVELOPER_ACCESS_TOKEN', '');

        if ($expected === '') {
            return ApiResponse::error(
                'developer_token_not_configured',
                'DEVELOPER_ACCESS_TOKEN is not set on the server.',
                503,
            );
        }

        $provided = $request->header('X-Developer-Token')
            ?? $request->query('token')
            ?? '';

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return ApiResponse::error(
                'invalid_developer_token',
                'Invalid developer token.',
                401,
            );
        }

        return $next($request);
    }
}
