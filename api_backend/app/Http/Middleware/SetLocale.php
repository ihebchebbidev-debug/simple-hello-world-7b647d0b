<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale for the API request.
 *
 * Priority:
 *   1. Explicit `Accept-Language` header (fr / en).
 *   2. Authenticated user's `preferred_lang`.
 *   3. Application default (fr).
 */
final class SetLocale
{
    private const SUPPORTED = ['fr', 'en'];
    private const DEFAULT = 'fr';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $header = $request->header('Accept-Language');
        if (is_string($header) && $header !== '') {
            // Take the first tag, e.g. "fr-FR,fr;q=0.9,en;q=0.8" -> "fr"
            $primary = strtolower(substr(trim(explode(',', $header)[0]), 0, 2));
            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        $user = $request->user();
        if ($user !== null && isset($user->preferred_lang)
            && in_array($user->preferred_lang, self::SUPPORTED, true)) {
            return $user->preferred_lang;
        }

        return self::DEFAULT;
    }
}
