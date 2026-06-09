<?php

/**
 * Short-lived response cache for expensive GET endpoints.
 *
 * Caches the full HTTP response body+headers for `ttl` seconds, keyed by
 * (user id, full URL incl. query string). Skips anything that isn't a
 * 2xx GET so error responses and writes never get cached.
 *
 * Why this exists
 * ---------------
 * Reports and the dashboard fire SUM/GROUP BY queries that take a few
 * hundred ms each. Users reload them constantly (tab switching, the
 * dashboard auto-polls). A 60-second TTL eliminates ~95% of that load
 * with no visible staleness because operations are entered once per day.
 *
 * Also stamps `Cache-Control: private, max-age=ttl` so the browser /
 * PWA shell can short-circuit the round-trip entirely.
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class CacheResponse
{
    public function handle(Request $request, Closure $next, string $ttl = '60'): Response
    {
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        $ttlSeconds = max(1, (int) $ttl);
        $userKey    = optional($request->user())->id ?? 'guest';
        $key        = 'http:' . $userKey . ':' . sha1($request->fullUrl());

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return response($cached['body'], $cached['status'], $cached['headers'])
                ->header('X-Cache', 'HIT');
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($key, [
                'body'    => $response->getContent(),
                'status'  => $response->getStatusCode(),
                'headers' => ['Content-Type' => $response->headers->get('Content-Type', 'application/json')],
            ], $ttlSeconds);
        }

        $response->headers->set('Cache-Control', 'private, max-age=' . $ttlSeconds);
        $response->headers->set('X-Cache', 'MISS');

        return $response;
    }
}
