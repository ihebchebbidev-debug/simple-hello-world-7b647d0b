<?php

/**
 * Auth extras: forgot-password (always 200), reset-password, and the
 * PATCH /auth/me profile update flow including current_password gating.
 */

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class AuthExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_200_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['message']]);
    }

    public function test_forgot_then_reset_password_updates_credentials(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make('OldPassword1!'),
        ]);

        // Trigger the reset flow & retrieve the token.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@example.com'])
            ->assertOk();

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => 'user@example.com',
            'token'                 => $token,
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword1!', $user->password));
    }

    public function test_reset_password_with_invalid_token_returns_422(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => Hash::make('xxx12345')]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email'                 => 'a@example.com',
            'token'                 => 'totally-invalid-token',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertStatus(422);
    }

    public function test_patch_me_updates_profile_fields(): void
    {
        $user = $this->actingAsRole('manager', ['name' => 'Old', 'preferred_lang' => 'fr']);

        $this->patchJson('/api/v1/auth/me', [
            'name'           => 'New Name',
            'preferred_lang' => 'en',
        ])->assertOk()
          ->assertJsonPath('data.user.name', 'New Name')
          ->assertJsonPath('data.user.preferred_lang', 'en');

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_patch_me_password_requires_current_password(): void
    {
        $this->actingAsRole('technician', ['password' => Hash::make('CurrentPass1!')]);

        $this->patchJson('/api/v1/auth/me', [
            'current_password'      => 'WrongPass',
            'password'              => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertStatus(422);
    }

    public function test_patch_me_password_succeeds_with_correct_current_password(): void
    {
        $user = $this->actingAsRole('admin', ['password' => Hash::make('CurrentPass1!')]);

        $this->patchJson('/api/v1/auth/me', [
            'current_password'      => 'CurrentPass1!',
            'password'              => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass1!', $user->refresh()->password));
    }

    public function test_patch_me_unauthenticated_rejected(): void
    {
        $this->patchJson('/api/v1/auth/me', ['name' => 'X'])->assertUnauthorized();
    }
}
