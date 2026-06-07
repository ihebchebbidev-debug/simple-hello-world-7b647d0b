<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Support\Concerns\HasSchemaAwareColumns;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class UserRoleRepository
{
    use HasSchemaAwareColumns;

    public function tableExists(): bool
    {
        return Schema::hasTable('user_roles');
    }

    /** @return array<int, string> */
    public function forUser(User $user): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role')
            ->values()
            ->all();
    }

    public function userIdsForRole(string $role): Builder
    {
        return DB::table('user_roles')->where('role', $role)->select('user_id');
    }

    /** @param array<int, string> $roles */
    public function sync(User $user, array $roles, ?string $assignedBy = null): void
    {
        if (! $this->tableExists()) {
            return;
        }

        // Resolve schema OUTSIDE any transaction so a poisoned connection
        // (Postgres 25P02) cannot mask the real cause.
        $allowedKeys = \Illuminate\Support\Facades\Schema::getColumnListing('user_roles');
        $allowed = array_flip($allowedKeys);

        // Ensure the connection is in a clean state before we start. If a
        // previous statement on this pooled connection left an aborted
        // transaction, ROLLBACK clears it; if there is no active txn this
        // is a harmless no-op on Postgres.
        try { DB::statement('ROLLBACK'); } catch (\Throwable) { /* ignore */ }

        // Do NOT wrap in DB::transaction. On Postgres, if the DELETE fails
        // for any reason (FK, trigger, RLS), the txn aborts and every
        // subsequent INSERT is rejected with 25P02 which hides the real
        // error. Running each statement standalone lets the real exception
        // propagate.
        try {
            DB::table('user_roles')->where('user_id', $user->id)->delete();

            foreach (array_values(array_unique($roles)) as $role) {
                $row = array_intersect_key([
                    'id'          => (string) Str::uuid(),
                    'user_id'     => $user->id,
                    'role'        => $role,
                    'assigned_at' => now(),
                    'assigned_by' => $assignedBy,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ], $allowed);

                try {
                    DB::table('user_roles')->insert($row);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('user_roles.insert_failed', [
                        'user_id' => $user->id,
                        'role'    => $role,
                        'columns' => array_keys($row),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    // Make sure the connection is usable for the next request.
                    try { DB::statement('ROLLBACK'); } catch (\Throwable) {}
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('user_roles.sync_failed', [
                'user_id' => $user->id,
                'roles' => $roles,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
