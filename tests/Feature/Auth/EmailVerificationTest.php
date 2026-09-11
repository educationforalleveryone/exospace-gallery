<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail as BrandedVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * VERIFICATION-ITERATION: expanded coverage for the full email-verification
 * lifecycle — new-account state, the branded verification email, the signed
 * link journey (happy path + tampered / wrong-user / expired / already-used
 * failures), resend behavior and its dedicated rate-limit buckets, the
 * verified-middleware gating and the post-verification state.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Verification notice page ─────────────────────────────────────────

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
        $response->assertSee('Resend Email');
        $response->assertSee(route('verification.send'), false);
        // VERIFICATION-ITERATION: the resend form carries the app-wide
        // double-submission guard (data-busy), like every other auth form.
        $response->assertSee('data-busy', false);
    }

    public function test_verification_screen_redirects_verified_users_to_dashboard(): void
    {
        $user = User::factory()->create(); // factory default = verified

        $response = $this->actingAs($user)->get('/verify-email');

        // Already-verified users never see the verification screen again.
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    // ── The verification email ───────────────────────────────────────────

    public function test_verification_notification_uses_the_branded_mailable(): void
    {
        $user = User::factory()->unverified()->create();

        // The User model must send the branded notification subclass...
        Notification::fake();
        $user->sendEmailVerificationNotification();
        Notification::assertSentTo($user, BrandedVerifyEmail::class);
        Notification::assertNotSentTo(User::factory()->make(), BrandedVerifyEmail::class);

        // ...and that notification must present itself through the branded
        // VerifyEmailMail (subject + html/text on emails.partials.layout).
        // The URL is still built by the framework's signed-URL machinery and
        // the recipient is always the account itself.
        $mail = (new BrandedVerifyEmail)->toMail($user);

        $this->assertInstanceOf(VerifyEmailMail::class, $mail);
        $this->assertTrue($mail->hasTo($user->email));
        $this->assertSame('Confirm your Exospace email address', $mail->envelope()->subject);
        $this->assertStringContainsString('/verify-email/'.$user->id.'/', $mail->verificationUrl);
    }

    public function test_branded_verification_email_carries_the_framework_signed_url(): void
    {
        $user = User::factory()->unverified()->create();

        $notification = new BrandedVerifyEmail;
        $reflection = new \ReflectionMethod($notification, 'verificationUrl');
        $reflection->setAccessible(true);
        $url = $reflection->invoke($notification, $user);

        // The URL must still be the framework's signed verification URL:
        // the verification.verify route with the user's id and the
        // sha1(email) hash as PATH segments, plus the HMAC signature and
        // expiry as query parameters.
        $this->assertStringContainsString('/verify-email/'.$user->id.'/'.sha1($user->email), $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_branded_verification_email_views_render_a_clean_copyable_url(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $html = view('emails.verify-email', [
            'user' => $user, 'verificationUrl' => $url, 'expireMinutes' => 60,
        ])->render();
        $text = view('emails.verify-email-text', [
            'user' => $user, 'verificationUrl' => $url, 'expireMinutes' => 60,
        ])->render();

        // HTML part: branded layout, action CTA, expiry note, reassurance.
        $this->assertStringContainsString('EXOSPACE', $html);
        $this->assertStringContainsString('Verify my email address', $html);
        $this->assertStringContainsString('60', $html);
        $this->assertStringContainsString("Didn't create an account?", $html);
        // Transactional email — never an unsubscribe footer.
        $this->assertStringNotContainsString('unsubscribe', strtolower($html));

        // VERIFICATION-ITERATION REGRESSION: the signed URL contains "&".
        // Blade's {{ }} escaping renders that as "&amp;", which corrupts the
        // plain-text part (text-mode mail clients would display a broken
        // link) and the copy-paste fallback in the HTML part. Both MUST show
        // the literal URL.
        $this->assertStringContainsString($url, $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringContainsString($url, $html);
    }

    // ── The signed verification link — happy path ────────────────────────

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        // VERIFICATION-ITERATION: lands on the real dashboard with a success
        // flash the toast component renders (previously a decorative
        // ?verified=1 with no consumer and no acknowledgment).
        $response->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
        $response->assertSessionHas('status', 'email-verified');
    }

    public function test_verification_state_persists_across_subsequent_requests(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl);

        // A refresh / new request keeps the verified state — the user is
        // never asked to verify the same email twice.
        $response = $this->actingAs($user->fresh())->get('/verify-email');
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_only_marks_the_verification_field(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => 'Original Name',
            'plan' => 'free',
            'is_super_admin' => false,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasVerifiedEmail());
        // Verification must not modify unrelated account information.
        $this->assertSame('Original Name', $fresh->name);
        $this->assertSame('free', $fresh->plan);
        $this->assertFalse($fresh->is_super_admin);
    }

    // ── The signed verification link — failure modes ─────────────────────

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        // The framework's EmailVerificationRequest rejects the hash mismatch
        // with 403 — the account must stay unverified.
        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_tampered_signature_is_rejected_and_renders_the_branded_403(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl.'X');

        $response->assertStatus(403);
        // VERIFICATION-ITERATION: failures render the branded error page
        // (not Laravel's bare default) with a way forward.
        $response->assertSee('Access denied');
        $response->assertSee('Log in');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(config('auth.verification.expire', 60) + 1),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_of_the_wrong_user_is_rejected(): void
    {
        $target = User::factory()->unverified()->create();   // the link belongs to this account
        $attacker = User::factory()->unverified()->create(); // a different signed-in user clicks it

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $target->id, 'hash' => sha1($target->email)]
        );

        $response = $this->actingAs($attacker)->get($verificationUrl);

        // Neither account may be verified: the id+hash pair is bound to the
        // signed-in user by EmailVerificationRequest::authorize().
        $response->assertStatus(403);
        $this->assertFalse($attacker->fresh()->hasVerifiedEmail());
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
    }

    public function test_already_verified_user_gets_a_silent_idempotent_redirect(): void
    {
        $user = User::factory()->create(); // already verified

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        // A replayed link redirects without re-verifying, without firing the
        // Verified event a second time and without the success toast.
        $response->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
        $response->assertSessionMissing('status');
        Event::assertNotDispatched(Verified::class);
    }

    // ── Resend verification ──────────────────────────────────────────────

    public function test_unverified_user_can_resend_the_verification_email(): void
    {
        $user = User::factory()->unverified()->create();

        Notification::fake();

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertRedirect();
        $response->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, BrandedVerifyEmail::class);
    }

    public function test_verified_user_cannot_trigger_resend_emails(): void
    {
        $user = User::factory()->create(); // already verified

        Notification::fake();

        $response = $this->actingAs($user)->post('/email/verification-notification');

        // Silently routed onward — no email, no error, no abuse surface.
        $response->assertRedirect(route('dashboard', absolute: false));
        Notification::assertNothingSent();
    }

    public function test_resend_verification_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();
        }

        // 7th request inside the same window → 429.
        $this->actingAs($user)->post('/email/verification-notification')->assertStatus(429);
    }

    public function test_verification_click_after_exhausted_resends_still_works(): void
    {
        // VERIFICATION-ITERATION REGRESSION: Laravel's numeric throttle
        // keys every throttled route into ONE per-user bucket, so 6 resends
        // followed by the legitimate link click 429'd the click itself.
        // The two verification routes now use dedicated named buckets —
        // exhausting the resend budget must never block the click.
        $user = User::factory()->unverified()->create();

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();
        }
        $this->actingAs($user)->post('/email/verification-notification')->assertStatus(429);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_redirects_to_a_stored_intended_destination(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // Simulate the gating bounce: EnsureEmailIsVerified stored the page
        // the user actually wanted via Redirect::guest.
        $this->actingAs($user)->get('/profile');
        $this->assertTrue(session()->has('url.intended'));

        $response = $this->actingAs($user)->get($verificationUrl);

        // The intended destination wins; the success flash renders there.
        $response->assertRedirect(route('profile.edit', absolute: false));
        $response->assertSessionHas('status', 'email-verified');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_intended_dashboard_alias_is_normalized_to_a_single_hop(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // The gating bounce from /dashboard itself stores the ALIAS URL as
        // the intended destination. Routing the success flash through the
        // alias's second redirect would age it out, so the controller
        // normalizes it to admin.dashboard directly.
        $this->actingAs($user)->get('/dashboard');
        $this->assertTrue(session()->has('url.intended'));

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
        $response->assertSessionHas('status', 'email-verified');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ── Verification-required access behavior ────────────────────────────

    public function test_unverified_users_are_bounced_from_verification_gated_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        // Bounced to the verification notice with the original target
        // remembered (Redirect::guest) so the journey resumes after
        // verification.
        $response->assertRedirect(route('verification.notice', absolute: false));
        $response->assertSessionHas('url.intended');

        $this->actingAs($user)->get('/admin/dashboard')
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_verified_users_pass_the_verification_gate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('admin.dashboard', absolute: false)); // through, not bounced back
    }

    public function test_verified_users_are_not_repeatedly_shown_the_verification_screen(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl)->assertRedirect();

        // After verification the gate stops bouncing the user around:
        // hitting the gated routes proceeds (dashboard alias redirects to
        // admin.dashboard) instead of re-prompting.
        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    // ── Guests ───────────────────────────────────────────────────────────

    public function test_guests_are_routed_to_login_from_verification_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // The cross-device journey: the email link is opened without a
        // session — the user is sent to log in first (the auth middleware
        // remembers the link as the intended destination so the verification
        // completes right after).
        $this->get($verificationUrl)->assertRedirect(route('login', absolute: false));

        $this->get('/verify-email')->assertRedirect(route('login', absolute: false));

        $this->post('/email/verification-notification')
            ->assertRedirect(route('login', absolute: false));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
