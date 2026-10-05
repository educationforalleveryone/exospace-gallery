<?php

namespace Tests\Browser;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

class SmokeTest extends DuskTestCase
{
    // The dusk environment shares a FILE-backed sqlite database with the
    // `php artisan serve` process (see ci.yml dusk job). The stock
    // DatabaseMigrations trait is built for single-process databases: it
    // would roll the shared file BACK after every test, wrecking it for the
    // next browser interactions and leaving a half-rolled-back schema.
    // A plain migrate:fresh per test gives the same isolation without the
    // destructive end-of-test rollback.
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    // Forms on this app submit through Turbo (fetch + pushState), so page
    // transitions complete ASYNCHRONOUSLY after a press(). Assertions must
    // wait for the expected content (waitForText polls the live DOM) instead
    // of asserting against the pre-navigation DOM.

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
                ->assertSee('Create your free account')
                ->assertSee('Email')
                ->assertSee('Password')
                ->assertSee('Create account');
        });
    }

    public function test_nonexistent_gallery_returns_404(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/gallery/this-slug-does-not-exist-12345')
                ->assertSee('404');
        });
    }

    public function test_user_can_log_in_and_reach_the_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'smoke-login@example.com',
            'password' => Hash::make('smoke-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('#email', $user->email)
                ->type('#password', 'smoke-password-123')
                ->press('Sign in')
                ->waitForText('Dashboard', 10)
                ->assertPathIs('/admin/dashboard')
                ->assertSee('Dashboard');
        });
    }

    public function test_visitor_can_register_and_reaches_verification_notice(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->type('#name', 'Smoke Tester')
                ->type('#email', 'smoke-register@example.com')
                ->type('#password', 'smoke-password-123')
                ->type('#password_confirmation', 'smoke-password-123')
                ->press('Create account')
                ->waitForText('Check your inbox', 10)
                ->assertPathIs('/verify-email')
                ->assertSee('Check your inbox');
        });
    }

    public function test_gallery_view_mounts_the_3d_viewer(): void
    {
        $gallery = Gallery::factory()->create([
            'title' => 'Smoke Test Exhibition',
            'slug' => 'smoke-test-exhibition',
        ]);

        $this->browse(function (Browser $browser) use ($gallery) {
            $browser->visit('/gallery/'.$gallery->slug)
                ->assertPresent('#canvas-container')
                ->assertSee('Smoke Test Exhibition');
        });
    }

    public function test_pin_protected_gallery_shows_the_pin_gate(): void
    {
        $gallery = Gallery::factory()->pinProtected('1234')->create([
            'slug' => 'smoke-pin-exhibition',
        ]);

        $this->browse(function (Browser $browser) use ($gallery) {
            $browser->visit('/gallery/'.$gallery->slug)
                ->assertPathIs('/gallery/'.$gallery->slug.'/pin')
                ->assertSee('This gallery is private');
        });
    }
}
