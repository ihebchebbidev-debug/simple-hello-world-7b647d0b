<?php

/**
 * Role authorization middleware.
 *
 * Checks roles from the separate user_roles table. Admin is hierarchical and
 * automatically passes manager and technician checks.
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AppRole;
use App\Support\Http\ApiResponse;
use App\Support\UserRoleRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RoleMiddleware
{
    public function __construct(private readonly UserRoleRepository $roles) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if (! $this->roles->tableExists()) {
            return ApiResponse::error('forbidden', 'Role assignments are unavailable.', 403);
        }

        $userRoles = $this->roles->forUser($user);

        if (in_array(AppRole::Admin->value, $userRoles, true) || array_intersect($roles, $userRoles) !== []) {
            return $next($request);
        }

        return ApiResponse::error('forbidden', 'You do not have permission to perform this action.', 403, [
            'required_roles' => array_values($roles),
        ]);
    }
}
