<?php

/**
 * Admin user management endpoints.
 *
 * List/create/show/update/deactivate APIs for administrator users. Roles are
 * stored only in `user_roles`, never on the `users` table.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Concerns\HasSchemaAwareColumns;
use App\Support\UserRoleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class UserController extends Controller
{
    use HasSchemaAwareColumns;
    use PaginatesResources;
    use RespondsWithResource;

    public function __construct(private readonly UserRoleRepository $roles) {}

    public function index(IndexUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = User::query()->orderByDesc('created_at');

        if (array_key_exists('is_active', $data) && Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if (! empty($data['role']) && $this->roles->tableExists()) {
            $query->whereIn('id', $this->roles->userIdsForRole($data['role']));
        }

        $paginator = $query->paginate($data['per_page'] ?? 25);

        // Preload all roles for this page in one query to eliminate N+1.
        $this->roles->preloadForUsers(
            collect($paginator->items())->pluck('id')->all(),
        );

        return $this->paginatedResponse($paginator, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = strtolower($data['email']);
        $id = (string) Str::uuid();

        // Build the payload from columns that actually exist on the deployed
        // schema. Schema introspection MUST happen outside the transaction
        // so a failed INSERT cannot leave the connection in PostgreSQL's
        // 25P02 "current transaction is aborted" state and mask the real
        // underlying error (NOT NULL / CHECK / unique violation).
        $payload = $this->onlyExistingColumns('users', [
            'id' => $id,
            'name' => $data['name'],
            'email' => $email,
            'preferred_lang' => $data['preferred_lang'] ?? 'fr',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $passwordColumn = $this->passwordColumn();
        if ($passwordColumn === null) {
            // Fail loudly instead of silently creating a passwordless account
            // that nobody could ever log in with.
            abort(500, 'users table is missing a password/password_hash column');
        }
        $payload[$passwordColumn] = Hash::make($data['password']);

        // Insert first, OUTSIDE the role-sync transaction. We already know
        // the UUID we generated, so there's no need to re-SELECT the row
        // (the previous `firstOrFail` happened inside the same transaction
        // and any prior failure surfaced as 25P02 instead of the real error).
        // Clear any leftover aborted txn state on a pooled connection.
        try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

        try {
            DB::table('users')->insert($payload);
        } catch (\Throwable $e) {
            try { DB::statement('ROLLBACK'); } catch (\Throwable) {}
            \Illuminate\Support\Facades\Log::error('users.insert_failed', [
                'email' => $email,
                'columns' => array_keys($payload),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $user = User::findOrFail($id);
        $this->roles->sync($user, $data['roles'], $request->user()?->id);

        return $this->resourceResponse(UserResource::class, $user, 201);
    }




    public function show(string $id): JsonResponse
    {
        return $this->resourceResponse(UserResource::class, User::findOrFail($id));
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validated();

        $payload = $this->onlyExistingColumns('users', [
            'name' => $data['name'] ?? $user->name,
            'email' => isset($data['email']) ? strtolower($data['email']) : $user->email,
            'preferred_lang' => $data['preferred_lang'] ?? $user->preferred_lang,
            'is_active' => $data['is_active'] ?? $user->is_active,
            'updated_at' => now(),
        ]);

        if (isset($data['password']) && ($passwordColumn = $this->passwordColumn()) !== null) {
            $payload[$passwordColumn] = Hash::make($data['password']);
            $user->tokens()->delete();
        }

        DB::table('users')->where('id', $user->id)->update($payload);

        if (isset($data['roles'])) {
            $this->roles->sync($user, $data['roles'], $request->user()?->id);
        }

        return $this->resourceResponse(UserResource::class, $user->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Revoke all personal access tokens first so the user can no longer
        // authenticate. Keep this outside any multi-statement transaction to
        // avoid masking errors on pooled Postgres connections.
        $user->tokens()->delete();

        // Remove any role assignments for this user. Syncing with an empty
        // array effectively deletes role rows for the user.
        try {
            if ($this->roles->tableExists()) {
                try { DB::statement('ROLLBACK'); } catch (\Throwable) {}
                DB::table('user_roles')->where('user_id', $user->id)->delete();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('user_roles.delete_failed', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Finally delete the user row from the users table. This performs a
        // permanent delete (no soft deletes are used in this project).
        try {
            try { DB::statement('ROLLBACK'); } catch (\Throwable) {}
            DB::table('users')->where('id', $user->id)->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('users.delete_failed', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Return 204 No Content — the client does not need the deleted resource.
        return response()->json(null, 204);
    }

    private function passwordColumn(): ?string
    {
        if (Schema::hasColumn('users', 'password')) {
            return 'password';
        }

        return Schema::hasColumn('users', 'password_hash') ? 'password_hash' : null;
    }
}
