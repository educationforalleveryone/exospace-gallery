<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail as BrandedVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
        $response->assertSee('Resend Email');
        $response->assertSee(route('verification.send'), false);
        $response->assertSee('data-busy', false);
    }

    public function test_verification_screen_redirects_verified_users_to_dashboard(): void
    {
        $user = User::factory()->create(); // factory default = verified

        $response = $this->actingAs($user)->get('/verify-email');

        // Already-verified users never see the verification screen again.
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_verification_notification_uses_the_branded_mailable(): void
    {
        $user = User::factory()->unverified()->create();

        // The User model must send the branded notification subclass...
        Notification::fake();
        $user->sendEmailVerificationNotification();
        Notification::assertSentTo($user, BrandedVerifyEmail::class);
        Notification::assertNotSentTo(User::factory()->make(), BrandedVerifyEmail::class);

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

        $response->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
        $response->assertSessionMissing('status');
        Event::assertNotDispatched(Verified::class);
    }

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

        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_guests_are_routed_to_login_from_verification_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->get($verificationUrl)->assertRedirect(route('login', absolute: false));

        $this->get('/verify-email')->assertRedirect(route('login', absolute: false));

        $this->post('/email/verification-notification')
            ->assertRedirect(route('login', absolute: false));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    // ── Registration → branded verification email chain ─────────────────

    public function test_registration_dispatches_the_branded_verification_email(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'chain@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('verification.notice', absolute: false));

        $user = User::whereEmail('chain@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, BrandedVerifyEmail::class);
    }

    // ── Canonical URL generation for the emailed link ────────────────────

    public function test_verification_url_is_built_against_the_canonical_app_url(): void
    {
        config(['app.url' => 'https://exospace.gallery']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example']);

        $user = User::factory()->unverified()->create();

        Notification::fake();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, BrandedVerifyEmail::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->verificationUrl, 'https://exospace.gallery/verify-email/')
                && str_contains($mail->verificationUrl, '/'.$user->id.'/'.sha1($user->email))
                && str_contains($mail->verificationUrl, 'signature=')
                && str_starts_with($mail->to[0]['address'] ?? '', $user->email);
        });
    }

    public function test_verification_url_uses_the_request_host_when_it_is_canonical(): void
    {
        config(['app.url' => 'http://localhost']);

        $user = User::factory()->unverified()->create();

        $notification = new BrandedVerifyEmail;
        $reflection = new \ReflectionMethod($notification, 'verificationUrl');
        $reflection->setAccessible(true);
        $url = $reflection->invoke($notification, $user);

        $this->assertStringStartsWith('http://localhost/verify-email/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    // ── Expiry: one config value drives link TTL and the displayed window ─

    public function test_link_ttl_and_displayed_expiry_share_one_config_value(): void
    {
        config(['auth.verification.expire' => 90]);
        $user = User::factory()->unverified()->create();

        $mail = (new BrandedVerifyEmail)->toMail($user);

        $this->assertInstanceOf(VerifyEmailMail::class, $mail);
        $this->assertSame(90, $mail->content()->with['expireMinutes']);

        parse_str((string) parse_url($mail->verificationUrl, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(
            now()->addMinutes(90)->getTimestamp(),
            (int) $query['expires'],
            5
        );
    }

    // ── Intended destination is consumed, not retained ───────────────────

    public function test_intended_destination_is_cleared_after_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get('/profile');
        $this->assertTrue(session()->has('url.intended'));

        $this->get($verificationUrl)->assertRedirect(route('profile.edit', absolute: false));

        $this->assertFalse(session()->has('url.intended'));
    }

    public function test_resending_does_not_verify_the_account(): void
    {
        $user = User::factory()->unverified()->create();

        Notification::fake();

        foreach (range(1, 3) as $i) {
            $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();
        }

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
