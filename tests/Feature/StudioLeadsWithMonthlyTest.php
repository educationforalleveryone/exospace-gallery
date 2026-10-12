<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Studio carries unlimited galleries and custom domains, so the monthly
 * subscription is the headline offer once it is configured; the one-time
 * lifetime price is the alternative. Pro keeps one-time as its headline.
 */
class StudioLeadsWithMonthlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function enableRecurring(): void
    {
        config([
            'services.2checkout.recurring_product_id_pro' => 'REC-PRO',
            'services.2checkout.recurring_product_id_studio' => 'REC-STUDIO',
            'services.2checkout.recurring_price_pro_monthly' => '4.99',
            'services.2checkout.recurring_price_studio_monthly' => '14.99',
            'services.2checkout.price_pro' => '29',
            'services.2checkout.price_studio' => '99',
        ]);
    }

    #[Test]
    public function pricing_page_headlines_studio_monthly_with_one_time_as_the_alternative(): void
    {
        $this->enableRecurring();

        // The upgrade modals only render purchase links for signed-in users.
        $this->actingAs(User::factory()->create())->get('/pricing')->assertOk()
            ->assertSeeInOrder(['Subscribe — $14.99/month', 'or $99 one-time — lifetime access'], false)
            ->assertSee('Upgrade to Studio — $14.99/month', false)
            ->assertSee('Cancel anytime · or $99 one-time for lifetime access', false);
    }

    #[Test]
    public function pricing_page_keeps_one_time_as_the_studio_headline_when_no_recurring_product_exists(): void
    {
        config([
            'services.2checkout.recurring_product_id_studio' => null,
            'plans.display.studio.price' => 99,
        ]);

        $this->get('/pricing')->assertOk()
            ->assertSee('Upgrade to Studio — $99', false)
            ->assertDontSee('Subscribe — $14.99/month', false);
    }

    #[Test]
    public function the_displayed_one_time_studio_price_follows_the_configured_price(): void
    {
        $this->enableRecurring();
        config(['plans.display.studio.price' => 199]);

        $this->actingAs(User::factory()->create())->get('/pricing')->assertOk()
            ->assertSee('or $199 one-time — lifetime access', false);
    }

    #[Test]
    public function billing_page_lists_studio_monthly_before_studio_one_time(): void
    {
        $this->enableRecurring();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/billing')->assertOk()
            ->assertSeeInOrder(['Studio — $14.99/month', 'Studio — $99 one-time'], false);
    }

    #[Test]
    public function pro_keeps_one_time_as_its_headline(): void
    {
        $this->enableRecurring();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/billing')->assertOk()
            ->assertSeeInOrder(['Pro — $29 one-time', 'Pro — $4.99/month'], false);
    }

    #[Test]
    public function plan_price_labels_default_to_the_configured_checkout_prices(): void
    {
        $this->assertSame('$29', config('plans.display.pro.price_label'));
        $this->assertSame('$99', config('plans.display.studio.price_label'));
    }
}
