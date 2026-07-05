<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

/**
 * TD-31 FIX: Smoke test — verifies the public-facing pages load without
 * 500 errors and contain expected text.
 *
 * This is intentionally a thin smoke test. Full user-flow browser tests
 * (register → create gallery → upload image → view gallery) should be
 * added incrementally as the codebase stabilizes.
 *
 * Run locally: php artisan dusk
 * Run in CI: see .github/workflows/ci.yml (dusk job)
 */
class SmokeTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * The homepage loads and shows the hero heading.
     */
    public function test_homepage_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                    ->assertSee('Exospace');
        });
    }

    /**
     * The pricing page loads and shows all three plan tiers.
     */
    public function test_pricing_page_shows_all_plans(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/pricing')
                    ->assertSee('Free')
                    ->assertSee('Pro')
                    ->assertSee('Studio')
                    ->assertSee('Compare All Features');
        });
    }

    /**
     * The discover page loads (may be empty if no galleries exist, but
     * shouldn't 500).
     */
    public function test_discover_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/discover')
                    ->assertSee('Featured 3D Exhibitions');
        });
    }

    /**
     * The login page loads and shows the email + password fields.
     */
    public function test_login_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                    ->assertSee('Email')
                    ->assertSee('Password')
                    ->assertSee('Sign in');
        });
    }

    /**
     * The register page loads and shows the required fields.
     */
    public function test_register_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                    ->assertSee('Email')
                    ->assertSee('Password')
                    ->assertSee('Sign up');
        });
    }

    /**
     * A non-existent gallery slug returns 404 (not a 500).
     */
    public function test_nonexistent_gallery_returns_404(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/gallery/this-slug-does-not-exist-12345')
                    ->assertSee('404');
        });
    }
}
