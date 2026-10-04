<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LandingPricingContentTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function landing_page_renders_and_links_primary_ctas(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Simple, Honest Pricing');
        // Primary CTAs resolve through named routes.
        $response->assertSee(route('register'), false);
        $response->assertSee(route('pricing'), false);
        $response->assertSee(route('discover'), false);
    }

    #[Test]
    public function landing_page_prices_match_billing_config(): void
    {
        config([
            'plans.display.pro.price'    => 29,
            'plans.display.studio.price' => 99,
            'services.2checkout.recurring_product_id_pro'    => 'prod-pro',
            'services.2checkout.recurring_product_id_studio' => 'prod-studio',
            'services.2checkout.recurring_price_pro_monthly'    => '4.99',
            'services.2checkout.recurring_price_studio_monthly' => '14.99',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('$29', false);
        $response->assertSee('$99', false);
        $response->assertSee('$4.99/mo', false);
        $response->assertSee('$14.99/mo', false);
    }

    #[Test]
    public function landing_page_prices_follow_config_at_runtime(): void
    {
        config([
            'plans.display.pro.price' => 49,
            'services.2checkout.recurring_product_id_pro' => null,
            'services.2checkout.recurring_product_id_studio' => null,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('$49', false);
        // Recurring offer is hidden when no recurring product is configured.
        $response->assertDontSee('or $4.99/mo', false);
        $response->assertDontSee('or $14.99/mo', false);
    }

    #[Test]
    public function pricing_page_prices_match_config(): void
    {
        config(['plans.display.pro.price' => 29, 'plans.display.studio.price' => 99]);

        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertSee('Upgrade to Pro — $29', false);
        $response->assertSee('Upgrade to Studio — $99', false);
    }

    #[Test]
    public function pricing_page_prices_follow_config_at_runtime(): void
    {
        config(['plans.display.pro.price' => 39, 'plans.display.studio.price' => 149]);

        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertSee('Upgrade to Pro — $39', false);
        $response->assertSee('Upgrade to Studio — $149', false);
        // Structured data must carry the same runtime price as the visible card.
        $response->assertSee('"price": "39.00"', false);
        $response->assertSee('"price": "149.00"', false);
    }

    #[Test]
    public function pricing_page_shows_current_plan_state_for_authenticated_users(): void
    {
        $pro = User::factory()->create(['plan' => 'pro']);

        $response = $this->actingAs($pro)->get('/pricing');

        $response->assertOk();
        $response->assertSee('Your Current Plan');
        // A Pro user must not be offered the Pro upgrade CTA again. The hidden
        // modal shell stays in the DOM, so assert on the modal's open anchor,
        // which only renders for users who can actually upgrade.
        $response->assertDontSee('data-click="openModalAnchor" data-arg="upgrade-modal-pro"', false);
    }

    #[Test]
    public function pricing_page_offers_trial_to_fresh_free_users(): void
    {
        $free = User::factory()->create(['plan' => 'free']);

        $response = $this->actingAs($free)->get('/pricing');

        $response->assertOk();
        $response->assertSee(route('billing.start-trial', 'pro'), false);
    }

    #[Test]
    public function display_prices_cannot_drift_from_charged_prices(): void
    {
        // plans.display.* derives from the same env var the checkout charges,
        // so marketing pages and proration math always agree with billing.
        $this->assertSame(
            (float) env('TWOCHECKOUT_PRICE_PRO', 29),
            (float) config('plans.display.pro.price'),
            'Display price for Pro must derive from the charged price env.'
        );
        $this->assertSame(
            (float) env('TWOCHECKOUT_PRICE_STUDIO', 99),
            (float) config('plans.display.studio.price'),
            'Display price for Studio must derive from the charged price env.'
        );
    }

    #[Test]
    public function landing_and_pricing_pages_are_mobile_responsive(): void
    {
        foreach (['/', '/pricing'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $this->assertStringContainsString(
                'width=device-width, initial-scale=1',
                $response->getContent(),
                "{$uri} must declare the responsive viewport."
            );
        }
    }
}
