<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeEmail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail; // VERIFICATION-ITERATION: branded subclass of the framework notification
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        // ITERATION-1 FIX: registration now redirects to the email
        // verification notice (verified email is required for /admin/*).
        $response->assertRedirect(route('verification.notice', absolute: false));
    }

    // ── REGISTRATION-ITERATION: user record integrity ────────────────────

    public function test_registration_hashes_the_password_and_applies_safe_defaults(): void
    {
        $this->post('/register', [
            'name' => 'Defaults User',
            'email' => 'defaults@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            // Mass-assignment probe: a malicious client must not be able to
            // elevate itself or flip billing/verification state on signup.
            'is_super_admin' => '1',
            'plan' => 'studio',
            'max_galleries' => '99999',
            'max_images' => '99999',
            'email_verified_at' => '2026-01-01 00:00:00',
            'has_password' => '0',
        ]);

        $user = User::where('email', 'defaults@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('GoodPass123', $user->password), 'password must be bcrypt-hashed');
        $this->assertStringStartsWith('$2y$', $user->password);
        $this->assertNull($user->email_verified_at, 'normal signup must stay unverified');
        $this->assertFalse($user->is_super_admin);
        $this->assertSame('free', $user->plan);
        $this->assertSame(1, $user->max_galleries);
        $this->assertSame(10, $user->max_images);
        $this->assertTrue($user->has_password);
        $this->assertNotNull($user->password_set_at);
        $this->assertNull($user->banned_at);
        $this->assertFalse($user->marketing_consent);
    }

    public function test_registration_does_not_re_render_the_password_after_validation_failure(): void
    {
        $response = $this->post('/register', [
            'name' => 'Preserved Name',
            'email' => 'preserve@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'Different999',
        ]);

        $response->assertSessionHasErrors('password');
        $back = $this->get('/register');
        $back->assertOk();
        $back->assertSee('value="Preserved Name"', false);
        $back->assertSee('value="preserve@example.com"', false);
        // The password must never appear in the re-rendered form.
        $this->assertStringNotContainsString('GoodPass123', $back->getContent());
    }

    public function test_registration_records_marketing_consent_when_opted_in(): void
    {
        $this->post('/register', [
            'name' => 'Consent User',
            'email' => 'consent@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            'marketing_consent' => '1',
        ]);

        $this->assertTrue(User::where('email', 'consent@example.com')->firstOrFail()->marketing_consent);
    }

    public function test_registration_rejects_boolean_invalid_marketing_consent(): void
    {
        // Tampered clients must not be able to smuggle arbitrary strings
        // through the consent checkbox (nullable|boolean rule).
        $response = $this->from('/register')->post('/register', [
            'name' => 'Bad Consent',
            'email' => 'badconsent@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            'marketing_consent' => 'on',
        ]);

        $response->assertSessionHasErrors('marketing_consent');
        $this->assertGuest();
    }

    // ── REGISTRATION-ITERATION: duplicate email + race handling ─────────

    public function test_registration_rejects_duplicate_email_cleanly(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Second User',
            'email' => 'taken@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'taken@example.com')->count(), 'no duplicate row may be created');
    }

    public function test_registration_survives_a_unique_constraint_race_with_a_clean_validation_error(): void
    {
        // Simulates the race: another row with the same email lands between
        // the `unique` validation and the INSERT (double-click / concurrent
        // requests). The `creating` hook inserts the duplicate so the real
        // INSERT hits the DB constraint, not the validator.
        $commit = false;
        User::creating(function () use (&$commit) {
            if ($commit) {
                return;
            }
            $commit = true;
            DB::table('users')->insert([
                'name' => 'Racer',
                'email' => 'race@example.com',
                'password' => Hash::make('Whatever999'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->from('/register')->post('/register', [
            'name' => 'Racer Two',
            'email' => 'race@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
        ]);

        // The losing request must re-present the standard unique-rule error,
        // NOT surface a 500 QueryException.
        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', 'race@example.com')->count());
    }

    // ── REGISTRATION-ITERATION: intended-URL sanitization (CONV-6) ───────

    public function test_registration_stores_safe_relative_redirect_as_intended(): void
    {
        $this->get('/register?redirect=billing/upgrade/pro');

        $this->assertEquals('/billing/upgrade/pro', session('url.intended'));
    }

    public function test_registration_rejects_unsafe_redirect_targets(): void
    {
        foreach ([
            '/\\evil.example',        // backslash-confused (WHATWG "\" = "/")
            '//evil.example',         // protocol-relative
            'https://evil.example',   // absolute URL
            "/dashboard\r\nX-Inj: 1", // header-injection shaped
            '/'.str_repeat('a', 3000), // absurd length (> 2048)
        ] as $vector) {
            session()->forget('url.intended');

            $this->get('/register?redirect='.rawurlencode($vector));

            $this->assertNull(
                session('url.intended'),
                "unsafe redirect target must not be stored: [{$vector}]"
            );
        }
    }

    // ── REGISTRATION-ITERATION: session / verification state ─────────────

    public function test_registration_regenerates_the_session_id(): void
    {
        $this->get('/register');
        $idBefore = session()->getId();

        $this->post('/register', [
            'name' => 'Session User',
            'email' => 'session@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
        ]);

        $this->assertNotEquals($idBefore, session()->getId(), 'registration must rotate the session ID (fixation guard)');
    }

    public function test_registration_sends_verification_email_and_welcome_email(): void
    {
        Notification::fake();
        Mail::fake();

        $this->post('/register', [
            'name' => 'Mail User',
            'email' => 'mail@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
        ]);

        $user = User::where('email', 'mail@example.com')->first();
        // Verification link — the framework-wired listener on Registered.
        // VERIFICATION-ITERATION: the User model now sends the branded
        // App\Notifications\Auth\VerifyEmail subclass (framework fake
        // matches by exact class, not instanceof).
        Notification::assertSentTo($user, VerifyEmail::class);
        // Welcome email — the discovery-wired SendWelcomeEmail listener.
        // WelcomeEmail implements ShouldQueue, so the fake records it as
        // queued (same pattern as tests/Feature/EmailDispatchTest.php).
        Mail::assertQueued(WelcomeEmail::class, function (WelcomeEmail $mailable) use ($user) {
            return $mailable->hasTo($user->email);
        });
    }

    // ── REGISTRATION-ITERATION: invitation-based registration ────────────

    public function test_registration_via_invitation_joins_the_team_and_is_auto_verified(): void
    {
        Notification::fake();
        Mail::fake();

        $owner = User::factory()->create();
        $team = Team::create([
            'name' => 'Invited Team',
            'slug' => 'invited-team',
            'owner_id' => $owner->id,
        ]);

        $plaintext = TeamInvitation::generateToken();
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'invited@example.com',
            'role' => 'editor',
            // D-6 contract: the DB stores the HASH; the link carries plaintext.
            'token' => TeamInvitation::hashToken($plaintext),
            'expires_at' => now()->addDays(7),
        ]);

        // The invitation page links here with the PLAINTEXT token.
        $show = $this->get('/register?invitation='.$plaintext);
        $show->assertOk();
        $show->assertSee('You were invited to join a team');
        $show->assertSee('name="invitation_token" value="'.$plaintext.'"', false);

        // The form POSTs the plaintext back; the email field is locked to
        // the invited address (server-side merge — posted email is ignored).
        $response = $this->post('/register', [
            'name' => 'Invited User',
            'email' => 'tampered@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            'invitation_token' => $plaintext,
        ]);

        $user = User::where('email', 'invited@example.com')->firstOrFail();

        $this->assertNull(User::where('email', 'tampered@example.com')->first(), 'posted email must not override the invited address');
        $this->assertNotNull($user->email_verified_at, 'invitation proves email ownership → auto-verified');
        $this->assertSame('editor', $team->members()->wherePivot('user_id', $user->id)->first()?->pivot?->role);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertFalse($user->is_super_admin, 'invitation signup must not grant elevated privileges');
        $this->assertNull(TeamInvitation::find($invitation->id), 'invitation must be consumed');

        $response->assertRedirect(route('admin.teams.show', $team, absolute: false));

        // The invited path intentionally fires NO Registered event: no
        // verification email, no welcome email (the invite email served).
        Notification::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_registration_with_an_expired_or_unknown_invitation_falls_back_to_normal_registration(): void
    {
        $owner = User::factory()->create();
        $team = Team::create([
            'name' => 'Stale Team',
            'slug' => 'stale-team',
            'owner_id' => $owner->id,
        ]);

        $expired = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'stale@example.com',
            'role' => 'viewer',
            'token' => TeamInvitation::hashToken('expired-plaintext-token'),
            'expires_at' => now()->subDay(),
        ]);

        // Expired token: the banner must NOT render, the form is normal.
        $show = $this->get('/register?invitation=expired-plaintext-token');
        $show->assertOk();
        $this->assertStringNotContainsString('You were invited to join a team', $show->getContent());

        // ...and the POST must not consume the invitation or auto-verify.
        $this->post('/register', [
            'name' => 'Stale User',
            'email' => 'stale@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            'invitation_token' => 'expired-plaintext-token',
        ]);

        $user = User::where('email', 'stale@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at, 'expired invitation must not auto-verify');
        $this->assertNull($user->current_team_id);
        $this->assertNotNull(TeamInvitation::find($expired->id), 'expired invitation must not be consumed');
    }

    // ── REGISTRATION-ITERATION: throttle ─────────────────────────────────

    public function test_registration_route_is_rate_limited(): void
    {
        // throttle:10,1 on POST /register. 11 rapid requests (all failing
        // validation so no users/sessions churn) → the 11th must be 429.
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', ['name' => '', 'email' => "thr{$i}@example.com"]);
        }

        $this->post('/register', ['name' => '', 'email' => 'thr11@example.com'])
            ->assertTooManyRequests();
    }
}
