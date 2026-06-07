<?php

/**
 * PATCH /auth/me — profile updates, password rotation rules, and the
 * required current_password check before changing the password.
 */

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_name_email_and_language(): void
    {
        $user = $this->actingAsRole('technician', [
            'email' => 'old@agrysync.test',
            'name'  => 'Old',
        ]);

        $payload = ['name' => 'New', 'email' => 'NEW@agrysync.test', 'preferred_lang' => 'en'];

        $body = $this->patchJson('/api/v1/auth/me', $payload)
            ->assertOk()->json('data.user');

        $this->assertSame('New',                  $body['name']);
        $this->assertSame('new@agrysync.test',    $body['email']);
        $this->assertSame('en',                   $body['preferred_lang']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@agrysync.test']);
    }

    public function test_password_rotation_requires_current_password(): void
    {
        $this->actingAsRole('technician', [
            'password' => Hash::make('correct-old'),
        ]);

        $this->patchJson('/api/v1/auth/me', [
            'current_password'      => 'wrong',
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
          ->assertJsonPath('error.code', 'invalid_password');
    }

    public function test_password_rotation_succeeds_with_correct_current_password(): void
    {
        $user = $this->actingAsRole('manager', [
            'password' => Hash::make('correct-old'),
        ]);

        $this->patchJson('/api/v1/auth/me', [
            'current_password'      => 'correct-old',
            'password'              => 'brand-new-pass-123',
            'password_confirmation' => 'brand-new-pass-123',
        ])->assertOk();

        $this->assertTrue(
            Hash::check('brand-new-pass-123', User::find($user->id)->password)
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->patchJson('/api/v1/auth/me', ['name' => 'x'])->assertUnauthorized();
    }
}
