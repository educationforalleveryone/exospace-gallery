<?php

declare(strict_types=1);

/**
 * Iteration-002 regression tests for audit 2CO-1 (cancel/reactivate auth),
 * 2CO-7 (same-plan renewal), and the TwoCheckoutApiClient service.
 *
 * Run: php artisan test --filter=BillingCancelAndRenewalTest
 */

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingCancelAndRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_2co1_twocheckout_api_client_is_registered_as_singleton(): void
    {
        $client1 = app(TwoCheckoutApiClient::class);
        $client2 = app(TwoCheckoutApiClient::class);

        $this->assertSame($client1, $client2, 'TwoCheckoutApiClient should be a singleton.');
    }

    public function test_2co1_cancel_subscription_uses_twocheckout_api_client_not_placeholder(): void
    {
        // Configure the client
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');

        // User with an active subscription
        $user = User::factory()->create([
            'plan'                 => 'pro',
            'subscription_id'      => 'sub-123',
            'subscription_status'  => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        // Fake the HTTP response from 2Checkout's cancel endpoint
        Http::fake([
            'api.2checkout.com/rest/6.0/subscriptions/sub-123/cancel' => Http::response(['success' => true], 200),
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.cancel-subscription'));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        // Verify the user's subscription status was updated
        $user->refresh();
        $this->assertEquals('cancelled', $user->subscription_status);
        $this->assertNotNull($user->subscription_cancelled_at);

        // Verify the request was sent to 2Checkout with the X-Avangate-Authentication header
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.2checkout.com/rest/6.0/subscriptions/sub-123/cancel'
                && $request->hasHeader('X-Avangate-Authentication')
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_2co1_cancel_subscription_fails_gracefully_on_api_error(): void
    {
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');

        $user = User::factory()->create([
            'plan'                 => 'pro',
            'subscription_id'      => 'sub-456',
            'subscription_status'  => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        // Simulate a 401 from 2Checkout (the old placeholder-auth failure mode)
        Http::fake([
            'api.2checkout.com/rest/6.0/subscriptions/sub-456/cancel' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.cancel-subscription'));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');

        // Verify the user's subscription status was NOT updated (the API call failed)
        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
    }

    public function test_2co1_reactivate_subscription_uses_twocheckout_api_client(): void
    {
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');

        $user = User::factory()->create([
            'plan'                     => 'pro',
            'subscription_id'          => 'sub-789',
            'subscription_status'      => 'cancelled',
            'subscription_cancelled_at'=> now()->subDay(),
            'subscription_ends_at'     => now()->addWeek(), // still within paid-for period
        ]);

        Http::fake([
            'api.2checkout.com/rest/6.0/subscriptions/sub-789/reactivate' => Http::response(['success' => true], 200),
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.reactivate-subscription'));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
        $this->assertNull($user->subscription_cancelled_at);
    }

    public function test_2co7_same_plan_one_time_renewal_is_allowed_for_user_without_subscription(): void
    {
        // User on Pro with a one-time purchase (plan_expires_at = null, no subscription)
        $user = User::factory()->create([
            'plan'              => 'pro',
            'plan_expires_at'   => null,
            'subscription_id'   => null,
        ]);

        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.product_id_pro', 'PRO-PRODUCT-ID');

        $response = $this->actingAs($user)
            ->get(route('billing.upgrade', ['plan' => 'pro']));

        // 2CO-7 FIX: should redirect to 2Checkout (not block with "already on this plan")
        $response->assertRedirect();
        $this->assertStringStartsWith('https://www.2checkout.com/checkout/purchase', $response->headers->get('Location'));
    }

    public function test_2co7_same_plan_subscription_to_onetime_conversion_cancels_subscription_first(): void
    {
        config()->set('services.2checkout.account_number', 'TESTMERCHANT');
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.product_id_pro', 'PRO-PRODUCT-ID');
        config()->set('services.2checkout.recurring_product_id_pro', 'PRO-RECURRING-ID');

        // User on Pro with an active monthly subscription
        $user = User::factory()->create([
            'plan'                 => 'pro',
            'subscription_id'      => 'sub-convert-123',
            'subscription_status'  => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        Http::fake([
            'api.2checkout.com/rest/6.0/subscriptions/sub-convert-123/cancel' => Http::response(['success' => true], 200),
        ]);

        // User clicks "Upgrade to Pro" (one-time, not recurring) — this is the
        // "convert to lifetime" flow
        $response = $this->actingAs($user)
            ->get(route('billing.upgrade', ['plan' => 'pro'])); // no ?recurring=1

        $response->assertRedirect();
        $this->assertStringStartsWith('https://www.2checkout.com/checkout/purchase', $response->headers->get('Location'));

        // Verify the subscription was cancelled via the API
        $user->refresh();
        $this->assertEquals('cancelled', $user->subscription_status);
        $this->assertNotNull($user->subscription_cancelled_at);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/subscriptions/sub-convert-123/cancel');
        });
    }

    public function test_2co1_cancel_fails_when_user_has_no_active_subscription(): void
    {
        $user = User::factory()->create([
            'plan'              => 'free',
            'subscription_id'   => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.cancel-subscription'));

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');
    }
}
