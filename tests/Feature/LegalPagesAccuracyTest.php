<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Production sets EXOSPACE_BUSINESS_ADDRESS; mirror that here so the
        // config-driven rendering paths are exercised.
        config()->set('app.business_address', "Exospace Gallery\n27 Innovation Drive, Suite 4B\nIslamabad, Islamabad Capital Territory 44000\nPakistan");
    }

    // ── existence & provider accuracy (2Checkout listing requirements) ──

    public function test_terms_page_names_the_merchant_the_processor_and_links_the_refund_policy(): void
    {
        $response = $this->get('/terms');

        $response->assertOk();
        $response->assertSee('Exospace Gallery Ltd.');
        $response->assertSee('2Checkout (a Verifone company)', false);
        $response->assertSee('14-day money-back guarantee');
        $response->assertSee('27 Innovation Drive, Suite 4B');
        $response->assertSee('support@exospace.gallery');
    }

    public function test_refund_page_states_the_window_the_processor_and_the_request_channel(): void
    {
        $response = $this->get('/refund-policy');

        $response->assertOk();
        $response->assertSee('14 calendar days');
        $response->assertSee('2Checkout (a Verifone company)', false);
        $response->assertSee('Refund Request');
        $response->assertSee('support@exospace.gallery');
    }

    public function test_refund_page_does_not_duplicate_the_14_day_exception(): void
    {
        $response = $this->get('/refund-policy');

        $response->assertOk();
        // The old page stated the 14-day cutoff twice as separate bullets.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'after the 14-day window has passed'),
            'The 14-day exception bullet must appear exactly once.'
        );
        $this->assertStringNotContainsString('submitted more than 14 days after the original purchase date', $response->getContent());
    }

    public function test_privacy_page_discloses_retention_subprocessors_and_consent_cookie(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertSee('Data Retention');
        $response->assertSee('90 days');
        $response->assertSee('18 months');
        $response->assertSee('2Checkout (a Verifone company)', false);
        $response->assertSee('Resend');
        $response->assertSee('Sentry');
        $response->assertSee('Cloudflare');
        $response->assertSee('exospace_cookie_consent', false);
        // The session identifier is described as a stored hash, not raw data.
        $response->assertSee('irreversible hash');
    }

    // ── no silently-refreshing "Last Updated" claims ────────────────────

    public function test_legal_pages_carry_a_static_last_updated_date(): void
    {
        // All three pages must show the revision date of the content, not a
        // date('F d, Y') stamp that silently claims to be updated every day.
        foreach (['/terms', '/privacy'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('Last Updated: October 4, 2026');
        }

        $this->get('/refund-policy')->assertOk()->assertSee('Last updated: October 4, 2026');
    }

    // ── address is rendered from EXOSPACE_BUSINESS_ADDRESS, not hardcoded ──

    public function test_registered_address_is_rendered_from_the_configured_business_address(): void
    {
        $response = $this->get('/refund-policy');

        $response->assertOk();
        // Newlines in the configured address are flattened for inline lists.
        $response->assertSee('27 Innovation Drive, Suite 4B, Islamabad, Islamabad Capital Territory 44000, Pakistan');
    }

    public function test_footer_renders_the_configured_address_and_a_matching_tel_link(): void
    {
        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertSee('Registered Address: Exospace Gallery, 27 Innovation Drive, Suite 4B', false);

        // The tel: href must match the displayed digits (it used to contain
        // two stray digits).
        $content = $response->getContent();
        $this->assertStringContainsString('href="tel:+923112345678"', $content);
        $this->assertStringNotContainsString('tel:+92311234567890', $content);
    }
}
