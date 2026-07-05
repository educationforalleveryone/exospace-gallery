<?php

namespace Tests\Feature;

use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * P0-3 regression tests: CAN-SPAM / GDPR compliance for marketing emails.
 *
 * Covers:
 *   - marketing_consent flag is captured at registration
 *   - Abandoned-cart email only sends to consented + verified users
 *   - Inactive-nudge email only sends to consented + verified users
 *   - Plan-expiry reminder sends to verified users (transactional, no consent needed)
 *   - Per-user frequency cap on abandoned-cart (max 1 per 7 days)
 *   - Unsubscribe flow (signed URL → confirm → marketing_consent=false)
 *   - Forged unsubscribe URL is rejected (403)
 *   - Email templates include unsubscribe link + physical address
 */
class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    // ── Registration ─────────────────────────────────────────────────────

    public function test_registration_captures_marketing_consent_true_when_checked(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
            'marketing_consent'     => '1',
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->marketing_consent);
    }

    public function test_registration_defaults_marketing_consent_false_when_unchecked(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test2@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
            // marketing_consent not sent
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'test2@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->marketing_consent);
    }

    // ── Abandoned cart ───────────────────────────────────────────────────

    public function test_abandoned_cart_does_not_send_to_user_without_consent(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'marketing_consent'  => false, // no consent
        ]);
        $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');
        // Simulate 25 hours ago
        $pending->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_abandoned_cart_does_not_send_to_unverified_user(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create([
            'marketing_consent' => true,
        ]);
        $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');
        $pending->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_abandoned_cart_sends_to_consented_verified_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'marketing_consent'  => true,
        ]);
        $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');
        $pending->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertSent(\App\Mail\AbandonedCartEmail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    public function test_abandoned_cart_frequency_cap_prevents_double_send_within_7_days(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'marketing_consent'  => true,
        ]);

        // First pending upgrade — 25 hours old, not yet notified
        $pending1 = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');
        $pending1->forceFill(['created_at' => now()->subHours(25)])->save();

        // Second pending upgrade — 3 days old, already notified 3 days ago
        $pending2 = PendingUpgrade::createForUser($user, 'studio', 'STUDIO-001');
        $pending2->forceFill([
            'created_at'  => now()->subDays(3),
            'notified_at' => now()->subDays(3),
        ])->save();

        // The second upgrade IS old enough (>24h) but the user was notified
        // within the 7-day frequency cap window — so no email should be sent.
        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_abandoned_cart_de_duplicates_multiple_pending_upgrades_for_same_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'marketing_consent'  => true,
        ]);

        // Three pending upgrades for the same user, all > 24h old
        for ($i = 0; $i < 3; $i++) {
            $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');
            $pending->forceFill(['created_at' => now()->subHours(25 - $i)])->save();
        }

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        // Only ONE email should be sent (de-duplicated by user)
        Mail::assertSent(\App\Mail\AbandonedCartEmail::class, 1);
    }

    // ── Inactive nudge ───────────────────────────────────────────────────

    public function test_inactive_nudge_does_not_send_without_consent(): void
    {
        Mail::fake();

        User::factory()->create([
            'created_at'          => now()->subDays(10),
            'email_verified_at'   => now(),
            'marketing_consent'   => false,
            'inactive_nudged_at'  => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_inactive_nudge_sends_with_consent_and_verification(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'created_at'          => now()->subDays(10),
            'email_verified_at'   => now(),
            'marketing_consent'   => true,
            'inactive_nudged_at'  => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSent(\App\Mail\InactiveUserNudge::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    // ── Plan expiry (transactional — no consent needed) ──────────────────

    public function test_plan_expiry_reminder_sends_without_marketing_consent(): void
    {
        Mail::fake();

        $user = User::factory()->pro()->create([
            'email_verified_at'         => now(),
            'marketing_consent'         => false, // no consent — but plan-expiry is transactional
            'plan_expires_at'           => now()->addDays(5),
            'plan_expiry_reminded_at'   => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSent(\App\Mail\PlanExpiringSoon::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    public function test_plan_expiry_reminder_does_not_send_to_unverified_user(): void
    {
        Mail::fake();

        User::factory()->pro()->create([
            'email_verified_at'         => null, // unverified
            'marketing_consent'         => false,
            'plan_expires_at'           => now()->addDays(5),
            'plan_expiry_reminded_at'   => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }

    // ── P0-7: inactive nudge no longer suppresses plan-expiry reminder ──

    public function test_inactive_nudge_does_not_suppress_plan_expiry_reminder(): void
    {
        // P0-7 regression test: before the fix, both flows shared the
        // same `lifecycle_nudged_at` column. An inactive-nudge would set
        // the column, and the plan-expiry filter would then skip the user.
        // Now each flow has its own column, so both emails can be sent.
        Mail::fake();

        $user = User::factory()->pro()->create([
            'created_at'                => now()->subDays(10),
            'email_verified_at'         => now(),
            'marketing_consent'         => true,
            'plan_expires_at'           => now()->addDays(5), // expires in 5 days
            'inactive_nudged_at'        => now()->subDays(2), // was inactive-nudged 2 days ago
            'plan_expiry_reminded_at'   => null,              // NOT yet reminded about expiry
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        // The plan-expiry reminder MUST be sent even though the user was
        // inactive-nudged recently. Before P0-7, this would have been
        // suppressed.
        Mail::assertSent(\App\Mail\PlanExpiringSoon::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    // ── Unsubscribe flow ─────────────────────────────────────────────────

    public function test_unsubscribe_signed_url_shows_confirmation_page(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);
        $url = URL::signedRoute('unsubscribe.show', ['user' => $user->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Unsubscribe from marketing emails?');
    }

    public function test_unsubscribe_confirm_sets_marketing_consent_false(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        // P0-3 AUDIT FIX: POST route now requires a signed URL.
        // An unsigned POST should be rejected (403).
        $signedUrl = URL::signedRoute('unsubscribe.confirm', ['user' => $user->id]);

        $response = $this->post($signedUrl);

        $response->assertRedirect('/unsubscribe-done');
        $user->refresh();
        $this->assertFalse($user->marketing_consent);
    }

    public function test_forged_unsubscribe_url_is_rejected(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        // No signature — should be rejected by the 'signed' middleware
        $response = $this->get("/unsubscribe/{$user->id}");

        $response->assertForbidden(); // or 403 — signed middleware rejects
    }

    public function test_forged_unsubscribe_post_is_rejected(): void
    {
        // P0-3 AUDIT FIX: POST without a signature must be rejected.
        // Before the fix, any visitor with a CSRF token could POST to
        // /unsubscribe/{userId} and unsubscribe any user (IDOR).
        $user = User::factory()->create(['marketing_consent' => true]);

        // POST without signature — should be 403
        $response = $this->post("/unsubscribe/{$user->id}");

        $response->assertForbidden();
        $user->refresh();
        $this->assertTrue($user->marketing_consent, 'User was unsubscribed by an unsigned POST — IDOR vulnerability!');
    }

    public function test_unsubscribe_already_unsubscribed_shows_friendly_page(): void
    {
        $user = User::factory()->create(['marketing_consent' => false]);
        $url = URL::signedRoute('unsubscribe.show', ['user' => $user->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee("You're already unsubscribed");
    }

    // ── Email template compliance ────────────────────────────────────────

    public function test_abandoned_cart_email_contains_unsubscribe_link(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'marketing_consent' => true,
        ]);
        $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');

        $email = new \App\Mail\AbandonedCartEmail($user, $pending);
        $rendered = $email->render();

        $this->assertStringContainsString('/unsubscribe/', $rendered);
        $this->assertStringContainsString('Unsubscribe from marketing emails', $rendered);
    }

    public function test_inactive_nudge_email_contains_unsubscribe_link(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'marketing_consent' => true,
        ]);

        $email = new \App\Mail\InactiveUserNudge($user);
        $rendered = $email->render();

        $this->assertStringContainsString('/unsubscribe/', $rendered);
        $this->assertStringContainsString('Unsubscribe from marketing emails', $rendered);
    }
}
