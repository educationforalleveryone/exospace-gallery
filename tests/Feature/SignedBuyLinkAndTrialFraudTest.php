<?php

declare(strict_types=1);

/**
 * Iteration-002 regression tests for audit 2CO-2 (signed buy link) and
 * 2CO-8 (trial fraud rate limit).
 *
 * Run: php artisan test --filter=SignedBuyLinkAndTrialFraudTest
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SignedBuyLinkAndTrialFraudTest extends TestCase
{
    use RefreshDatabase;

    public function test_2co2_upgrade_url_includes_sign_parameter_when_price_configured(): void
    {
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.product_id_pro', 'PRO-PRODUCT-ID');
        config()->set('services.2checkout.price_pro', '29.00');

        $user = User::factory()->create(['plan' => 'free']);

        $response = $this->actingAs($user)
            ->get(route('billing.upgrade', ['plan' => 'pro']));

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        // 2CO-2 FIX: the URL must include &sign=...
        $this->assertStringContainsString('&sign=', $location, '2CO-2: Buy URL must include signed &sign= parameter when price is configured.');

        // Verify the signature is an uppercase MD5 hash (32 hex chars)
        preg_match('/&sign=([A-F0-9]{32})/i', $location, $matches);
        $this->assertNotEmpty($matches, '2CO-2: &sign= must be a 32-char uppercase hex string.');
        $this->assertEquals(strtoupper($matches[1]), $matches[1], '2CO-2: &sign= must be uppercase.');
    }

    public function test_2co2_upgrade_url_skips_sign_when_price_not_configured(): void
    {
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.product_id_pro', 'PRO-PRODUCT-ID');
        config()->set('services.2checkout.price_pro', null); // no price configured

        $user = User::factory()->create(['plan' => 'free']);

        $response = $this->actingAs($user)
            ->get(route('billing.upgrade', ['plan' => 'pro']));

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        // 2CO-2: when price is not configured, the signed link is SKIPPED (with a warning log)
        $this->assertStringNotContainsString('&sign=', $location, '2CO-2: &sign= must be absent when price is not configured.');
    }

    public function test_2co2_signed_buy_link_uses_correct_signature_format(): void
    {
        $sid = 'TESTMERCHANT';
        $productId = 'PRO-PRODUCT-ID';
        $price = '29.00';
        $secretWord = 'TESTSECRET';

        config()->set('services.2checkout.account_number', $sid);
        config()->set('services.2checkout.secret_word', $secretWord);
        config()->set('services.2checkout.product_id_pro', $productId);
        config()->set('services.2checkout.price_pro', $price);

        $user = User::factory()->create(['plan' => 'free']);

        $response = $this->actingAs($user)
            ->get(route('billing.upgrade', ['plan' => 'pro']));

        $location = $response->headers->get('Location');

        // Expected signature: strtoupper(md5(sid + product_id + '1' + price + secret_word))
        $expectedSign = strtoupper(md5($sid . $productId . '1' . $price . $secretWord));

        $this->assertStringContainsString('&sign=' . $expectedSign, $location,
            '2CO-2: &sign= must equal strtoupper(md5(sid + product_id + quantity + price + secret_word)).');
    }

    public function test_2co8_trial_start_succeeds_on_first_attempt(): void
    {
        // Clear any rate limit state
        RateLimiter::clear('trial:127.0.0.1');

        $user = User::factory()->create([
            'plan'           => 'free',
            'trial_ends_at'  => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.start-trial', ['plan' => 'pro']));

        $response->assertRedirect(route('admin.dashboard'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        $this->assertNotNull($user->trial_ends_at);
    }

    public function test_2co8_trial_start_blocked_after_max_attempts_per_ip(): void
    {
        RateLimiter::clear('trial:127.0.0.1');

        // Create 2 users and start trials for both (max 2 per IP per 30 days)
        $user1 = User::factory()->create(['plan' => 'free', 'trial_ends_at' => null]);
        $user2 = User::factory()->create(['plan' => 'free', 'trial_ends_at' => null]);
        $user3 = User::factory()->create(['plan' => 'free', 'trial_ends_at' => null]);

        $this->actingAs($user1)->post(route('billing.start-trial', ['plan' => 'pro']));
        $this->actingAs($user2)->post(route('billing.start-trial', ['plan' => 'pro']));

        // 3rd attempt from the same IP should be blocked
        $response = $this->actingAs($user3)
            ->post(route('billing.start-trial', ['plan' => 'pro']));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');

        // Verify user3 did NOT get a trial
        $user3->refresh();
        $this->assertEquals('free', $user3->plan);
        $this->assertNull($user3->trial_ends_at);
    }

    public function test_2co8_trial_start_blocked_for_user_who_already_used_trial(): void
    {
        RateLimiter::clear('trial:127.0.0.1');

        $user = User::factory()->create([
            'plan'           => 'free',
            'trial_ends_at'  => now()->subWeek(), // already used a trial
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.start-trial', ['plan' => 'pro']));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');
    }

    public function test_2co8_trial_start_blocked_for_paid_user(): void
    {
        $user = User::factory()->create([
            'plan'           => 'pro',
            'trial_ends_at'  => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.start-trial', ['plan' => 'studio']));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure rate limiter is clean for each test
        RateLimiter::clear('trial:127.0.0.1');
    }
}
