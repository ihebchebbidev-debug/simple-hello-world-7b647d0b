<?php

/**
 * Authentication endpoints.
 *
 * Token-based auth (Sanctum personal access tokens). The plain-text token is
 * returned once at register/login and the client must send it back as
 * `Authorization: Bearer <token>`.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\UserRoleRepository;
use App\Enums\AppRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AuthController extends Controller
{
    public function __construct(private readonly UserRoleRepository $roles) {}

    /**
     * Public probe used by the admin UI to decide whether to show the
     * one-time signup screen or the regular login screen.
     */
    public function setupStatus(): JsonResponse
    {
        return ApiResponse::ok([
            'needs_setup' => ! $this->adminExists(),
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        Log::info('auth.register.started', [
            'email' => strtolower($data['email']),
            'has_user_roles_table' => Schema::hasTable('user_roles'),
            'has_tokens_table' => Schema::hasTable('personal_access_tokens'),
            'users_count' => Schema::hasTable('users') ? DB::table('users')->count() : null,
            'admin_roles_count' => Schema::hasTable('user_roles') ? DB::table('user_roles')->where('role', AppRole::Admin->value)->count() : null,
        ]);

        // Self-service registration is ONLY allowed to bootstrap the very
        // first admin. Once any admin exists, additional users must be
        // created by an admin via POST /users.
        if ($this->adminExists()) {
            Log::warning('auth.register.blocked_admin_exists', [
                'email' => strtolower($data['email']),
            ]);

            return ApiResponse::error('registration_disabled', __('errors.registration_disabled'), 403);
        }

        try {
            return DB::transaction(function () use ($data): JsonResponse {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => $data['password'],
                    'preferred_lang' => $data['preferred_lang'] ?? 'fr',
                    'is_active' => true,
                ]);

                Log::info('auth.register.user_created', ['user_id' => $user->id]);

                // First user to register is automatically promoted to admin.
                $this->roles->sync($user, [AppRole::Admin->value], $user->id);

                Log::info('auth.register.roles_synced', [
                    'user_id' => $user->id,
                    'roles' => $this->roles->forUser($user),
                ]);

                $payload = $this->tokenPayload($user, $data['device_name'] ?? 'api-token');

                Log::info('auth.register.token_created', ['user_id' => $user->id]);

                return ApiResponse::created($payload);
            });
        } catch (Throwable $e) {
            Log::error('auth.register.failed', [
                'email' => strtolower($data['email']),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())->take(8)->all(),
                'schema' => [
                    'users' => Schema::hasTable('users'),
                    'user_roles' => Schema::hasTable('user_roles'),
                    'personal_access_tokens' => Schema::hasTable('personal_access_tokens'),
                    'user_roles_columns' => Schema::hasTable('user_roles') ? Schema::getColumnListing('user_roles') : [],
                    'personal_access_tokens_columns' => Schema::hasTable('personal_access_tokens') ? Schema::getColumnListing('personal_access_tokens') : [],
                ],
            ]);

            return ApiResponse::error(
                'registration_failed',
                config('app.debug') ? $e->getMessage() : 'Could not create the admin account.',
                500,
            );
        }
    }

    private function adminExists(): bool
    {
        if (! Schema::hasTable('user_roles')) {
            return false;
        }
        return DB::table('user_roles')->where('role', AppRole::Admin->value)->exists();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('invalid_credentials', __('errors.invalid_credentials'), 401);
        }

        if ($user->is_active === false) {
            return ApiResponse::error('account_disabled', __('errors.account_disabled'), 403);
        }

        if (Schema::hasColumn('users', 'last_login_at')) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return ApiResponse::ok($this->tokenPayload($user, $data['device_name'] ?? 'api-token'));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(['user' => UserResource::make($request->user())->resolve()]);
    }

    /**
     * Update the authenticated user's profile (name, email, language) and
     * optionally rotate their password (requires current_password).
     */
    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (isset($data['password'])) {
            if (! $user->password || ! Hash::check($data['current_password'] ?? '', $user->password)) {
                return ApiResponse::error('invalid_password', __('errors.invalid_credentials'), 422, [
                    'field' => 'current_password',
                ]);
            }
            $user->password = $data['password'];
            // Rotate other tokens for safety, keep the current one.
            $current = $user->currentAccessToken();
            $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();
        }

        foreach (['name', 'email', 'preferred_lang'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $field === 'email' ? strtolower($data[$field]) : $data[$field];
            }
        }

        $user->save();

        return ApiResponse::ok(['user' => UserResource::make($user->refresh())->resolve()]);
    }

    /**
     * Generate a password-reset token. Always returns 200 to avoid leaking
     * whether an email exists, but only sends a notification when found.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower($request->validated()['email']);

        Password::sendResetLink(['email' => $email]);

        return ApiResponse::ok(['message' => __('passwords.sent')]);
    }

    /**
     * Consume a password-reset token and set a new password. Revokes all
     * existing API tokens so attackers cannot reuse stolen sessions.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['email'] = strtolower($payload['email']);

        $status = Password::reset($payload, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $user->tokens()->delete();
        });

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::ok(['message' => __('passwords.reset')]);
        }

        return ApiResponse::error('invalid_token', __($status), 422);
    }


    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::ok(['message' => 'Logged out']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()?->tokens()->delete();

        return ApiResponse::ok(['message' => 'All sessions revoked']);
    }

    /** @return array<string, mixed> */
    private function tokenPayload(User $user, string $deviceName): array
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user)->resolve(),
        ];
    }
}
