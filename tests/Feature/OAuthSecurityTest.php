<?php

declare(strict_types=1);

/**
 * Iteration-001: OAuth security regression tests (audit CR-3 + CR-4 + C-2).
 *
 * These tests verify the three Critical OAuth fixes:
 *   1. CR-3: Account-takeover via unverified-email merge is blocked.
 *      An attacker who registers a GitHub account with the victim's email
 *      as primary (unverified) must NOT be able to log in as the victim.
 *   2. CR-3: New OAuth users only get email_verified_at if the provider
 *      explicitly verified the email. GitHub primary email ≠ verified.
 *   3. CR-4: Session ID is regenerated on every OAuth login (no session
 *      fixation).
 *   4. C-2: has_password column is used for the unlink guard (not the
 *      broken bcrypt comparison).
 *
 * Run: php artisan test --filter=OAuthSecurityTest
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Auth\Notifications\VerifyEmail;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class OAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ITERATION-1 FIX: the OAuth controller gates on configured client
        // IDs; .env.testing leaves them empty so every callback redirected
        // to login with "not available" before reaching the logic under test.
        config([
            'services.google' => [
                'client_id'     => 'test-google-client-id',
                'client_secret' => 'test-google-secret',
                'redirect'      => '/auth/google/callback',
            ],
            'services.github' => [
                'client_id'     => 'test-github-client-id',
                'client_secret' => 'test-github-secret',
                'redirect'      => '/auth/github/callback',
            ],
        ]);
        // Configure fake OAuth credentials so isProviderConfigured() returns true
        config()->set('services.google.client_id', 'test-google-id');
        config()->set('services.google.client_secret', 'test-google-secret');
        config()->set('services.google.redirect', 'http://localhost/auth/google/callback');
        config()->set('services.github.client_id', 'test-github-id');
        config()->set('services.github.client_secret', 'test-github-secret');
        config()->set('services.github.redirect', 'http://localhost/auth/github/callback');
    }

    public function test_cr3_oauth_login_does_not_merge_by_email_when_provider_id_does_not_match(): void
    {
        // Victim has an existing account with email + password
        $victim = User::factory()->create([
            'email' => 'victim@example.com',
            'password' => bcrypt('correct-horse-battery-staple'),
            'github_id' => null,
            'has_password' => true,
        ]);

        // Attacker registers a GitHub account, sets victim's email as primary (unverified)
        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'attacker-github-id-123',
            'email' => 'victim@example.com',
            'name' => 'Attacker',
            'email_verified' => false,
            'provider' => 'github',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        // Attacker attempts OAuth login via GitHub
        $response = $this->withSession(['oauth_action' => 'login'])
            ->get('/auth/github/callback?code=test-code');

        // CR-3 FIX: The OAuth controller must NOT merge by email.
        // The victim's account must not be compromised.
        $victim->refresh();
        $this->assertNull($victim->github_id,
            'CR-3 REGRESSION: Victim\'s github_id must remain null — attacker must not be able to link their GitHub to victim\'s account.');

        // No new user should be created with the attacker's github_id pointing at the victim
        $attackerLinkedUser = User::where('github_id', 'attacker-github-id-123')->first();
        if ($attackerLinkedUser) {
            $this->assertNotEquals($victim->id, $attackerLinkedUser->id,
                'CR-3 REGRESSION: If a new user was created, it must not be the victim\'s account.');
        }

        // The response should redirect to login with an error message
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
    }

    public function test_cr3_new_oauth_user_email_not_verified_if_github_did_not_verify(): void
    {
        Notification::fake();

        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'new-github-id-456',
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'email_verified' => false, // GitHub primary email but NOT verified
            'provider' => 'github',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->withSession(['oauth_action' => 'login'])
            ->get('/auth/github/callback?code=test-code');

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user, 'New user should have been created.');

        // CR-3 FIX: email_verified_at must be null because GitHub did not verify the email
        $this->assertNull($user->email_verified_at,
            'CR-3 REGRESSION: email_verified_at must be null when GitHub did not verify the email. '.
            'The user must go through the standard email verification flow.');

        // A verification email should have been dispatched
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_cr3_new_oauth_user_email_verified_if_google_verified(): void
    {
        Notification::fake();

        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'new-google-id-789',
            'email' => 'verifieduser@gmail.com',
            'name' => 'Verified User',
            'email_verified' => true, // Google verified this email
            'provider' => 'google',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->withSession(['oauth_action' => 'login'])
            ->get('/auth/google/callback?code=test-code');

        $user = User::where('email', 'verifieduser@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at,
            'CR-3 REGRESSION: email_verified_at must be set when Google verified the email.');

        // No verification email should be dispatched (email already verified)
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_cr4_session_id_is_regenerated_on_oauth_login(): void
    {
        // CR-4 FIX: session fixation — session ID must change on login
        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'session-test-id',
            'email' => 'session-test@example.com',
            'name' => 'Session Test',
            'email_verified' => true,
            'provider' => 'google',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->startSession();
        $sessionBefore = Session::getId();

        $this->withSession(['oauth_action' => 'login'])
            ->get('/auth/google/callback?code=test-code');

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'CR-4 REGRESSION: Session ID must be regenerated on OAuth login to prevent session fixation.');
    }

    public function test_cr4_session_id_is_regenerated_on_returning_oauth_login(): void
    {
        // Existing user with linked google_id
        $user = User::factory()->create([
            'email' => 'returning@example.com',
            'google_id' => 'returning-google-id',
            'email_verified_at' => now(),
            'has_password' => true,
        ]);

        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'returning-google-id',
            'email' => 'returning@example.com',
            'name' => 'Returning User',
            'email_verified' => true,
            'provider' => 'google',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->startSession();
        $sessionBefore = Session::getId();

        $this->withSession(['oauth_action' => 'login'])
            ->get('/auth/google/callback?code=test-code');

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'CR-4 REGRESSION: Session ID must be regenerated even for returning OAuth users.');

        $this->assertAuthenticatedAs($user);
    }

    public function test_c2_oauth_only_user_cannot_unlink_last_login_method(): void
    {
        // C-2 FIX: an OAuth-only user (has_password=false) must NOT be able to
        // unlink their only OAuth provider.
        $user = User::factory()->create([
            'email' => 'oauth-only@example.com',
            'password' => Hash::make(\Illuminate\Support\Str::random(32)), // random placeholder
            'github_id' => 'oauth-only-github-id',
            'google_id' => null,
            'has_password' => false, // OAuth-only user
        ]);

        $response = $this->actingAs($user)
            ->post('/auth/github/unlink');

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('error');

        $user->refresh();
        $this->assertNotNull($user->github_id,
            'C-2 REGRESSION: OAuth-only user must NOT be able to unlink their only login method.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'github_id' => 'oauth-only-github-id',
        ]);
    }

    public function test_c2_user_with_password_can_unlink_oauth_provider(): void
    {
        // C-2 FIX: a user who has a real password CAN unlink their OAuth provider
        $user = User::factory()->create([
            'email' => 'has-password@example.com',
            'password' => bcrypt('real-password'),
            'github_id' => 'has-password-github-id',
            'google_id' => null,
            'has_password' => true, // Has a real password
        ]);

        $response = $this->actingAs($user)
            ->post('/auth/github/unlink');

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertNull($user->github_id,
            'C-2: User with a real password should be able to unlink their OAuth provider.');
    }

    public function test_c2_oauth_user_with_multiple_providers_can_unlink_one(): void
    {
        // C-2 FIX: a user with multiple OAuth providers can unlink one (the other remains)
        $user = User::factory()->create([
            'email' => 'multi-oauth@example.com',
            'password' => Hash::make(\Illuminate\Support\Str::random(32)), // placeholder
            'github_id' => 'multi-github-id',
            'google_id' => 'multi-google-id',
            'has_password' => false, // No real password, but two OAuth providers
        ]);

        $response = $this->actingAs($user)
            ->post('/auth/github/unlink');

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertNull($user->github_id, 'GitHub should be unlinked.');
        $this->assertNotNull($user->google_id, 'Google should remain linked.');
    }

    public function test_cr3_link_refused_when_provider_email_does_not_match_account_email(): void
    {
        // CR-3 defense-in-depth: an authenticated user linking a provider whose
        // email differs from their account email should be refused.
        $user = User::factory()->create([
            'email' => 'account@example.com',
            'github_id' => null,
            'has_password' => true,
        ]);

        $socialiteUser = $this->mockSocialiteUser([
            'id' => 'different-email-github-id',
            'email' => 'different@example.com', // Different from account email
            'name' => 'Different Email',
            'email_verified' => true,
            'provider' => 'github',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $response = $this->actingAs($user)
            ->withSession(['oauth_action' => 'link'])
            ->get('/auth/github/callback?code=test-code');

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('error');

        $user->refresh();
        $this->assertNull($user->github_id,
            'CR-3: Provider should NOT be linked when emails do not match.');
    }

    /**
     * Mock a Socialite user with the given attributes.
     */
    private function mockSocialiteUser(array $attrs): SocialiteUserContract
    {
        $user = Mockery::mock(SocialiteUserContract::class)->shouldIgnoreMissing();
        $user->id = $attrs['id'];
        $user->email = $attrs['email'];
        $user->name = $attrs['name'];
        $user->avatar = $attrs['avatar'] ?? 'https://example.com/avatar.png';
        $user->user = [
            'email_verified' => $attrs['email_verified'] ?? false,
            'verified_email' => $attrs['email_verified'] ?? false, // Google's key
            'verified' => $attrs['email_verified'] ?? false,        // GitHub's key
        ];

        // ITERATION-1 FIX: Mockery mocks REJECT unexpected method calls.
        // The controller may call any subset of getters depending on flow
        // path — allow all of them (and any others) to return sensible
        // values instead of hard expectations.
        $user->shouldReceive('getId')->andReturn($attrs['id'])->byDefault();
        $user->shouldReceive('getEmail')->andReturn($attrs['email'])->byDefault();
        $user->shouldReceive('getName')->andReturn($attrs['name'])->byDefault();
        $user->shouldReceive('getNickname')->andReturn($attrs['name'])->byDefault();
        $user->shouldReceive('getAvatar')->andReturn($attrs['avatar'] ?? 'https://example.com/avatar.png')->byDefault();
        $user->shouldReceive('getToken')->andReturn('test-token')->byDefault();
        $user->shouldReceive('getRaw')->andReturn($user->user)->byDefault();

        return $user;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
