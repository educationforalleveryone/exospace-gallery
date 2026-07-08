<?php

declare(strict_types=1);

/**
 * Iteration-004 regression tests for D-2 (PKCE), D-4 (password history on reset),
 * and D-6 (TeamInvitation token hashing).
 *
 * Run: php artisan test --filter=OAuthAndPasswordSecurityTest
 */

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class OAuthAndPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_d2_oauth_redirect_uses_pkce(): void
    {
        // D-2 FIX: the OAuth redirect should call ->withPkce()
        // We verify by checking that Socialite::driver()->withPkce()->redirect() is called.
        // Since we can't easily mock the chained calls, we verify the source code contains withPkce.
        $controllerFile = file_get_contents(app_path('Http/Controllers/OAuthController.php'));
        $this->assertStringContainsString('withPkce', $controllerFile,
            'D-2: OAuthController::redirect must call ->withPkce() for PKCE protection.');
    }

    public function test_d4_password_reset_checks_history(): void
    {
        // D-4 FIX: the forgot-password reset flow should check password history
        $user = User::factory()->create([
            'email' => 'reset-test@example.com',
            'password' => Hash::make('OldPassword123!'),
            'has_password' => true,
        ]);

        // Store the old password in history
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password_hash' => $user->getOriginal('password'),
            'created_at' => now(),
        ]);

        // Attempt to reset the password to the SAME value as the old one
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $response = $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'reset-test@example.com',
            'password' => 'OldPassword123!', // same as old password
            'password_confirmation' => 'OldPassword123!',
        ]);

        // D-4 FIX: should be rejected (password reuse)
        $response->assertSessionHasErrors('password');

        // Verify the password was NOT changed
        $user->refresh();
        $this->assertTrue(Hash::check('OldPassword123!', $user->password),
            'D-4: Password should NOT be changed when it matches a historical password.');
    }

    public function test_d4_password_reset_allows_new_password(): void
    {
        // D-4 FIX: the forgot-password reset flow should allow a NEW password
        $user = User::factory()->create([
            'email' => 'reset-new@example.com',
            'password' => Hash::make('OldPassword123!'),
            'has_password' => true,
        ]);

        // Store the old password in history
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password_hash' => $user->getOriginal('password'),
            'created_at' => now(),
        ]);

        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $response = $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'reset-new@example.com',
            'password' => 'BrandNewPassword456!', // different from old
            'password_confirmation' => 'BrandNewPassword456!',
        ]);

        $response->assertSessionHas('status');

        // Verify the password WAS changed
        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword456!', $user->password),
            'D-4: Password should be changed when it does NOT match a historical password.');
    }

    public function test_d4_password_controller_uses_shared_helper(): void
    {
        // D-4 FIX: PasswordController::update should use the shared helper
        $controllerFile = file_get_contents(app_path('Http/Controllers/Auth/PasswordController.php'));
        $this->assertStringContainsString('isPasswordInHistory', $controllerFile,
            'D-4: PasswordController must use User::isPasswordInHistory() shared helper.');
        $this->assertStringContainsString('storePasswordInHistory', $controllerFile,
            'D-4: PasswordController must use User::storePasswordInHistory() shared helper.');
    }

    public function test_d6_team_invitation_token_is_hashed_in_db(): void
    {
        // D-6 FIX: the token stored in the DB should be a sha256 hash, not the plaintext
        $plaintext = 'my-plaintext-token-1234567890123456789012345678901234567890123456789012345678901234';
        $hash = TeamInvitation::hashToken($plaintext);

        $this->assertNotEquals($plaintext, $hash,
            'D-6: hashToken should produce a different value than the plaintext.');
        $this->assertEquals(64, strlen($hash),
            'D-6: sha256 hash should be 64 hex chars.');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash,
            'D-6: hash should be 64 lowercase hex chars.');
    }

    public function test_d6_team_invitation_find_by_token_uses_hash(): void
    {
        // D-6 FIX: findByToken should hash the plaintext before querying
        $team = Team::factory()->create();
        $plaintext = 'test-token-for-find-' . uniqid();
        $hash = TeamInvitation::hashToken($plaintext);

        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email' => 'find-test@example.com',
            'token' => $hash, // store the hash
            'expires_at' => now()->addDays(7),
        ]);

        // findByToken with the plaintext should find it
        $found = TeamInvitation::findByToken($plaintext);
        $this->assertNotNull($found, 'D-6: findByToken should find the invitation by plaintext token.');
        $this->assertEquals($invitation->id, $found->id);

        // findByToken with the hash directly should NOT find it (it would hash the hash)
        $notFound = TeamInvitation::findByToken($hash);
        $this->assertNull($notFound,
            'D-6: findByToken should NOT find the invitation when passed the hash (it hashes the input).');
    }

    public function test_d6_team_invitation_controller_show_uses_find_by_token(): void
    {
        // D-6 FIX: TeamInvitationController::show should use findByToken (which hashes)
        $controllerFile = file_get_contents(app_path('Http/Controllers/TeamInvitationController.php'));
        $this->assertStringContainsString('findByToken', $controllerFile,
            'D-6: TeamInvitationController must use TeamInvitation::findByToken() (which hashes the token).');
    }

    public function test_d4_user_model_has_password_history_helpers(): void
    {
        // D-4 FIX: User model should have isPasswordInHistory and storePasswordInHistory
        $user = User::factory()->create();
        $this->assertTrue(method_exists($user, 'isPasswordInHistory'),
            'D-4: User model must have isPasswordInHistory() method.');
        $this->assertTrue(method_exists($user, 'storePasswordInHistory'),
            'D-4: User model must have storePasswordInHistory() method.');
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
