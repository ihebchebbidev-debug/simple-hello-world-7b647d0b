<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

/**
 * Structured API response helper.
 *
 * Every endpoint returns one of two envelopes:
 *
 *   Success:  { "data": ..., "meta": ... }
 *   Failure:  { "error": { "code": "...", "message": "...", "details": ... } }
 *
 * Centralising the shape here keeps it consistent across controllers,
 * middleware, and the global exception handler.
 */
final class ApiResponse
{
    public static function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    public static function created(mixed $data): JsonResponse
    {
        return self::ok($data, 201);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $payload = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        return response()->json(['error' => $payload], $status);
    }
}
