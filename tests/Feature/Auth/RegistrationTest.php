<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeEmail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail; // branded subclass of the framework notification
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
        $response->assertRedirect(route('verification.notice', absolute: false));
    }

    // ── User record integrity ───────────────────────────────────────────

    public function test_registration_hashes_the_password_and_applies_safe_defaults(): void
    {
        $this->post('/register', [
            'name' => 'Defaults User',
            'email' => 'defaults@example.com',
            'password' => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
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

    // ── Duplicate email + race handling ─────────────────────────────────

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

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        // The injected competitor row rides the same connection, so the atomic
        // registration transaction rolls it back along with our own writes.
        $this->assertSame(0, User::where('email', 'race@example.com')->count());
    }

    // ── Intended-URL sanitization ───────────────────────────────────────

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

    // ── Session / verification state ────────────────────────────────────

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
        Notification::assertSentTo($user, VerifyEmail::class);
        Mail::assertQueued(WelcomeEmail::class, function (WelcomeEmail $mailable) use ($user) {
            return $mailable->hasTo($user->email);
        });
    }

    // ── Invitation-based registration ─────────────────────────────────────

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
            // The DB stores the HASH; the invitation link carries plaintext.
            'token' => TeamInvitation::hashToken($plaintext),
            'expires_at' => now()->addDays(7),
        ]);

        // The invitation page links here with the PLAINTEXT token.
        $show = $this->get('/register?invitation='.$plaintext);
        $show->assertOk();
        $show->assertSee('You were invited to join a team');
        $show->assertSee('name="invitation_token" value="'.$plaintext.'"', false);

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

    public function test_registration_route_is_rate_limited(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', ['name' => '', 'email' => "thr{$i}@example.com"]);
        }

        $this->post('/register', ['name' => '', 'email' => 'thr11@example.com'])
            ->assertTooManyRequests();
    }

    // ── Registration: validation boundaries ─────────────────────────────

    public function test_registration_requires_the_name_email_and_password_fields(): void
    {
        $response = $this->from('/register')->post('/register', []);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_registration_rejects_invalid_email_addresses(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name'                 => 'Bad Email',
            'email'                => 'not-an-email',
            'password'             => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_registration_rejects_passwords_below_the_minimum_length(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name'                 => 'Short Pass',
            'email'                => 'shortpass@example.com',
            'password'             => 'Short1!',
            'password_confirmation' => 'Short1!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'shortpass@example.com')->count());
    }

    public function test_registration_with_a_mismatched_confirmation_creates_no_account(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name'                 => 'Mismatch',
            'email'                => 'mismatch@example.com',
            'password'             => 'GoodPass123',
            'password_confirmation' => 'Different999',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'mismatch@example.com')->count());
    }

    public function test_an_authenticated_user_is_redirected_away_from_registration(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/register')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    // ── Registration: invitation edge cases ─────────────────────────────

    public function test_registration_via_invitation_for_an_existing_account_is_rejected_without_consuming_the_invitation(): void
    {
        User::factory()->create(['email' => 'already@example.com']);

        $owner = User::factory()->create();
        $team = Team::create([
            'name'     => 'Dup Team',
            'slug'     => 'dup-team',
            'owner_id' => $owner->id,
        ]);

        $plaintext = TeamInvitation::generateToken();
        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => 'already@example.com',
            'role'       => 'viewer',
            'token'      => TeamInvitation::hashToken($plaintext),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->from('/register')->post('/register', [
            'name'                 => 'Late Joiner',
            'email'                => 'posted@example.com',
            'password'             => 'GoodPass123',
            'password_confirmation' => 'GoodPass123',
            'invitation_token'     => $plaintext,
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotNull(TeamInvitation::find($invitation->id), 'invitation must survive a failed signup');
        $this->assertSame(0, $team->members()->count());
    }

    public function test_invitation_registration_rolls_back_completely_when_the_team_join_fails(): void
    {
        $owner = User::factory()->create();
        $team = Team::create([
            'name'     => 'Atomic Team',
            'slug'     => 'atomic-team',
            'owner_id' => $owner->id,
        ]);

        $plaintext = TeamInvitation::generateToken();
        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => 'atomic@example.com',
            'role'       => 'editor',
            'token'      => TeamInvitation::hashToken($plaintext),
            'expires_at' => now()->addDays(7),
        ]);

        $failTeamJoin = true;
        DB::beforeExecuting(function (string $sql) use (&$failTeamJoin) {
            if ($failTeamJoin && str_contains(strtolower($sql), 'insert into "team_user"')) {
                throw new \RuntimeException('simulated team-join failure');
            }
        });

        $threw = false;
        $response = null;

        try {
            $response = $this->post('/register', [
                'name'                 => 'Atomic User',
                'email'                => 'posted@example.com',
                'password'             => 'GoodPass123',
                'password_confirmation' => 'GoodPass123',
                'invitation_token'     => $plaintext,
            ]);
        } catch (\Throwable) {
            $threw = true;
        } finally {
            $failTeamJoin = false;
        }

        if (! $threw) {
            $response->assertServerError();
        }

        $this->assertSame(0, User::where('email', 'atomic@example.com')->count(), 'the user row must roll back');
        $this->assertNotNull(TeamInvitation::find($invitation->id), 'the invitation must survive the failure');
        $this->assertSame(0, $team->members()->count(), 'no team membership may remain');
        $this->assertGuest();
    }
}
