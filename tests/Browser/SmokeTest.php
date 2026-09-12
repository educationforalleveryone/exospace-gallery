<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

class SmokeTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_homepage_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                    ->assertSee('Exospace');
        });
    }

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

    public function test_discover_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/discover')
                    ->assertSee('Featured 3D Exhibitions');
        });
    }

    public function test_login_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                    ->assertSee('Email')
                    ->assertSee('Password')
                    ->assertSee('Sign in');
        });
    }

    public function test_register_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                    ->assertSee('Email')
                    ->assertSee('Password')
                    ->assertSee('Sign up');
        });
    }

    public function test_nonexistent_gallery_returns_404(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/gallery/this-slug-does-not-exist-12345')
                    ->assertSee('404');
        });
    }
}
