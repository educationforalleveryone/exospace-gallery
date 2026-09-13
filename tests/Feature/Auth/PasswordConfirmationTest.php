<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_the_confirmation_screen_requires_authentication(): void
    {
        $this->get('/confirm-password')->assertRedirect(route('login'));

        $this->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect(route('login'));
    }

    public function test_a_missing_password_fails_safely(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/confirm-password')
            ->post('/confirm-password', [])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/confirm-password');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_client_supplied_confirmation_state_is_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/confirm-password', [
                'password' => 'wrong-password',
                'auth.password_confirmed_at' => now()->unix(),
            ])
            ->assertSessionHasErrors();

        $this->assertNull(session('auth.password_confirmed_at'));

        $this->actingAs($user)
            ->post('/confirm-password', [
                'password' => 'wrong-password',
                'auth' => ['password_confirmed_at' => now()->unix()],
            ])
            ->assertSessionHasErrors();

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_a_successful_confirmation_is_recorded_server_side(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $confirmedAt = session('auth.password_confirmed_at');

        $this->assertIsInt($confirmedAt);
        $this->assertLessThanOrEqual(2, now()->unix() - $confirmedAt);
    }

    public function test_confirmation_expires_after_the_configured_timeout(): void
    {
        [$admin, $target] = $this->superAdminAndTargetUser();

        $this->actingAs($admin)
            ->withSession($this->validMfaSession($admin))
            ->from(route('super.index'));

        $this->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        // Just inside the configured window, the protected action executes.
        $this->travel(config('auth.password_timeout') - 60)->seconds();
        $this->withSession($this->validMfaSession($admin));

        $this->from(route('super.index'))
            ->post(route('super.banUser', $target))
            ->assertRedirect(route('super.index'));

        $this->assertNotNull($target->refresh()->banned_at);

        // Past the window, the action is gated again and nothing executes.
        $this->travel(120)->seconds();
        $other = User::factory()->create();

        $this->withSession($this->validMfaSession($admin));

        $this->from(route('super.index'))
            ->post(route('super.banUser', $other))
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($other->refresh()->banned_at);
    }

    public function test_unconfirmed_access_to_a_protected_action_is_rejected(): void
    {
        [$admin, $target] = $this->superAdminAndTargetUser();

        $this->actingAs($admin)
            ->withSession($this->validMfaSession($admin))
            ->from(route('super.index'))
            ->post(route('super.banUser', $target))
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($target->refresh()->banned_at);
    }

    public function test_confirmed_access_reaches_the_protected_action(): void
    {
        [$admin, $target] = $this->superAdminAndTargetUser();

        $this->actingAs($admin)
            ->withSession($this->validMfaSession($admin))
            ->from(route('super.index'));

        $this->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->from(route('super.index'))
            ->post(route('super.banUser', $target))
            ->assertRedirect(route('super.index'))
            ->assertSessionHas('success');

        $this->assertNotNull($target->refresh()->banned_at);
    }

    public function test_confirming_returns_the_user_to_the_intended_protected_action(): void
    {
        [$admin, $target] = $this->superAdminAndTargetUser();

        $this->actingAs($admin)
            ->withSession($this->validMfaSession($admin))
            ->from(route('super.index'))
            ->post(route('super.banUser', $target))
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($target->refresh()->banned_at);

        // Confirming hands the user back to the action they were interrupted on.
        $this->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect(route('super.index'));
    }

    public function test_the_confirmation_screen_renders_the_submit_guard_and_recovery_path(): void
    {
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get('/confirm-password');

        $page->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee(route('password.confirm'), false)
            // The app-wide double-submission guard used by every other security form.
            ->assertSee('data-busy', false)
            // Recovery path for a user who cannot confirm because they forgot the password.
            ->assertSee(__('Sign out'))
            ->assertSee(route('logout'), false);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function superAdminAndTargetUser(): array
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['google2fa_secret' => encrypt('GA7W2KUTZQRQW2KUTZQRQW2KUTZQRQW2')])->save();

        $target = User::factory()->create();

        return [$admin, $target];
    }

    /**
     * @return array<string, mixed>
     */
    private function validMfaSession(User $user): array
    {
        return [
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
            'mfa_verified_user_id' => $user->id,
        ];
    }
}
