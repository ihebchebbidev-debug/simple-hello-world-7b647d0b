<?php

/**
 * Application bootstrap.
 *
 * Wires routing (web, api, console), the health probe and the global
 * middleware/exception behaviour. Every API failure is normalised into a
 * structured `{ error: { code, message, details? } }` envelope so the SPA
 * never has to parse an HTML error page or guess the failure shape.
 */

declare(strict_types=1);

use App\Http\Middleware\DeveloperToken;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [
            SetLocale::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'locale' => SetLocale::class,
            'developer.token' => DeveloperToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'validation_failed',
                    __('errors.validation_failed'),
                    422,
                    ['fields' => $e->errors()],
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'unauthenticated', __('errors.unauthenticated'), 401,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    'forbidden', __('errors.forbidden'), 403,
                ),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(
                    'not_found', __('errors.not_found'), 404,
                ),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                    'method_not_allowed', __('errors.method_not_allowed'), 405,
                ),
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    'too_many_requests', __('errors.too_many_requests'), 429,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    'http_error',
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode(),
                ),
                default => tap(ApiResponse::error(
                    'server_error',
                    config('app.debug') ? $e->getMessage() : 'An unexpected server error occurred.',
                    500,
                ), function () use ($e, $request): void {
                    Log::error('api.unhandled_exception', [
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }),
            };
        });
    })
    ->create();
