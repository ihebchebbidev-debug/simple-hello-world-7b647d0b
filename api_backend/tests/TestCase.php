<?php

/**
 * Base TestCase.
 *
 * Every feature/unit test should extend this class. It boots the Laravel
 * application using bootstrap/app.php and exposes a few convenience
 * helpers used by report + CRUD tests (authenticated users with a role
 * assigned from the user_roles pivot).
 */

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Create a user, assign them the requested role(s) in user_roles,
     * and authenticate them on the API (sanctum) guard for the test.
     *
     * @param  array<int, string>|string  $roles
     */
    protected function actingAsRole(array|string $roles, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $now = now();
        foreach ((array) $roles as $role) {
            DB::table('user_roles')->insert([
                'id'          => (string) Str::uuid(),
                'user_id'     => $user->id,
                'role'        => $role,
                'assigned_at' => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
