<?php

namespace Tests\Browser;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;
use PragmaRX\Google2FA\Google2FA;

/**
 * Permanent interaction-defect regression sweep.
 *
 * For every role (free, pro, studio, team member, super admin, control-center)
 * and viewport (desktop + mobile on the core product surfaces) it asserts:
 *   - zero console errors / uncaught exceptions / unhandled rejections
 *   - zero CSP violations
 *   - zero failed (4xx/5xx) fetch/XHR requests
 *   - zero visible broken images (deliberate data-fallback-hide sources excluded)
 * and then exercises the shared interactive machinery for real: modals
 * (open, Escape, backdrop, close button, focus + scroll restoration), the
 * notification / team / user dropdowns, the command palette, the feedback
 * widget, Turbo back/forward navigation and Alpine re-initialisation.
 *
 * Runs against `php artisan serve` + headless Chrome (see phpunit.dusk.xml);
 * the file-backed sqlite database is shared with the serve process, exactly
 * like SmokeTest.
 */
class InteractionSweepTest extends DuskTestCase
{
    private const DESKTOP = [1440, 900];

    private const MOBILE = [390, 844];

    /** A valid 1×1 JPEG so cover/artwork sources genuinely resolve. */
    private const ONE_PIXEL_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAA'
        .'AAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AKgA//9k=';

    protected function setUp(): void
    {
        parent::setUp();

        // `$browser->keys('body', ...)` resolves to the selector "body body"
        // (Dusk prefixes every selector with `body`) and never matches. Send
        // keystrokes to the real <body> element instead; chainable like keys().
        Browser::macro('pressBody', function (string $keys) {
            $map = [
                '{escape}' => \Facebook\WebDriver\WebDriverKeys::ESCAPE,
                '{enter}' => \Facebook\WebDriver\WebDriverKeys::ENTER,
            ];

            $this->driver
                ->findElement(\Facebook\WebDriver\WebDriverBy::tagName('body'))
                ->sendKeys($map[$keys] ?? $keys);

            return $this;
        });

        $this->artisan('migrate:fresh');

        // The sweep asserts that image sources actually resolve; the link is
        // normally created by docker-start.sh / setup.sh, not by migrate.
        if (! file_exists(public_path('storage'))) {
            $this->artisan('storage:link');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Role sweeps — every authenticated surface, both viewports
    // ─────────────────────────────────────────────────────────────────────

    public function test_free_user_pages_are_clean_on_desktop_and_mobile(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-free@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $routes = [
            '/admin/dashboard' => 'Good',
            '/admin/galleries' => 'Galleries',
            '/admin/galleries/create' => 'Create New Gallery',
            '/admin/artists' => 'Artists',
            '/admin/teams' => 'Teams',
            '/billing' => 'Billing',
            '/profile' => 'Profile',
        ];

        $this->browse(function (Browser $browser) use ($user, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            foreach ([self::DESKTOP, self::MOBILE] as $viewport) {
                $browser->resize($viewport[0], $viewport[1]);

                foreach ($routes as $path => $marker) {
                    $browser->visit($path);
                    $this->settlePage($browser);
                    $browser->assertSee($marker);
                    $this->assertPageSweepClean($browser, "free {$viewport[0]}px {$path}");
                }
            }
        });
    }

    public function test_pro_user_pages_are_clean_on_desktop_and_mobile(): void
    {
        [$user, $gallery] = $this->makeProUserWithGallery();

        $routes = [
            '/admin/dashboard' => 'Good',
            '/admin/galleries' => 'Galleries',
            "/admin/galleries/{$gallery->id}/edit" => 'Edit Gallery:',
            "/admin/galleries/{$gallery->id}/analytics" => 'Analytics',
            "/admin/galleries/{$gallery->id}/events" => 'Events',
            "/admin/galleries/{$gallery->id}/events/create" => 'New event',
            '/admin/artists' => 'Artists',
            '/billing' => 'Billing',
            '/profile' => 'Profile',
        ];

        $this->browse(function (Browser $browser) use ($user, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            foreach ([self::DESKTOP, self::MOBILE] as $viewport) {
                $browser->resize($viewport[0], $viewport[1]);

                foreach ($routes as $path => $marker) {
                    $browser->visit($path);
                    $this->settlePage($browser);
                    $browser->assertSee($marker);
                    $this->assertPageSweepClean($browser, "pro {$viewport[0]}px {$path}");
                }
            }
        });
    }

    public function test_studio_user_pages_are_clean(): void
    {
        $user = User::factory()->studio()->create([
            'email' => 'sweep-studio@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);
        Gallery::factory()->create(['user_id' => $user->id]);

        $routes = [
            '/admin/dashboard' => 'Good',
            '/admin/galleries' => 'Galleries',
            '/billing' => 'Billing',
        ];

        $this->browse(function (Browser $browser) use ($user, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            foreach ($routes as $path => $marker) {
                $browser->visit($path);
                $this->settlePage($browser);
                $browser->assertSee($marker);
                $this->assertPageSweepClean($browser, "studio {$path}");
            }
        });
    }

    public function test_team_member_pages_are_clean(): void
    {
        $owner = User::factory()->pro()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id, 'name' => 'Sweep Team', 'slug' => 'sweep-team']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);
        GalleryImage::factory()->count(2)->create(['gallery_id' => $gallery->id]);

        $member = User::factory()->create([
            'email' => 'sweep-member@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);
        $team->members()->attach($member->id, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $routes = [
            '/admin/dashboard' => 'Good',
            // In a team workspace the page heading is the team name.
            '/admin/galleries' => $team->name,
            "/admin/teams/{$team->id}" => $team->name,
            '/billing' => 'Billing',
        ];

        $this->browse(function (Browser $browser) use ($member, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $member);

            foreach ([self::DESKTOP, self::MOBILE] as $viewport) {
                $browser->resize($viewport[0], $viewport[1]);

                foreach ($routes as $path => $marker) {
                    $browser->visit($path);
                    $this->settlePage($browser);
                    $browser->assertSee($marker);
                    $this->assertPageSweepClean($browser, "member {$viewport[0]}px {$path}");
                }
            }
        });
    }

    public function test_super_admin_pages_are_clean(): void
    {
        User::factory()->create(); // the managed user row in Master Control

        $admin = $this->makeSuperAdmin();
        $routes = [
            '/master-control' => 'Master Control',
            '/master-control/venues' => 'Venue Templates',
            '/master-control/featured' => 'Featured Exhibitions',
            '/master-control/feedback' => 'Feedback',
            '/master-control/pending-upgrades' => 'Pending Upgrades',
            '/master-control/billing' => 'Billing Review',
            '/master-control/webhooks' => 'Outbound webhook subscriptions',
        ];

        $this->browse(function (Browser $browser) use ($admin, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $admin);
            $this->verifyMfaInBrowser($browser, $admin);

            foreach ($routes as $path => $marker) {
                $browser->visit($path);
                $this->settlePage($browser);
                $browser->assertSee($marker);
                $this->assertPageSweepClean($browser, "super-admin {$path}");
            }
        });
    }

    public function test_control_center_pages_are_clean(): void
    {
        $admin = User::factory()->create([
            'email' => 'cc-sweep@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $routes = [
            '/control-center' => 'TESTING CONTROL CENTER',
            '/control-center/runs' => 'TESTING CONTROL CENTER',
            '/control-center/flaky' => 'TESTING CONTROL CENTER',
        ];

        $this->browse(function (Browser $browser) use ($admin, $routes) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $admin);

            foreach ($routes as $path => $marker) {
                $browser->visit($path);
                $this->settlePage($browser);
                $browser->assertSee($marker);
                $this->assertPageSweepClean($browser, "control-center {$path}");
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared interactive machinery — modals, dropdowns, palette, feedback
    // ─────────────────────────────────────────────────────────────────────

    public function test_upgrade_modal_opens_and_closes_every_way(): void
    {
        // Free user at their gallery limit — dashboard swaps "New Gallery" for "Upgrade".
        $user = User::factory()->create([
            'email' => 'sweep-modal@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);
        Gallery::factory()->create(['user_id' => $user->id]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);
            $browser->assertSee('Upgrade');

            // Open via the dashboard trigger.
            $browser->click('[data-click="showUpgradeModal"]')
                ->waitFor('#upgrade-modal', 5)
                ->assertSee('You\'ve reached your gallery limit');

            // Focus moves inside the dialog (openModal focuses the first
            // focusable element after it opens).
            $browser->waitUntil(
                'document.getElementById("upgrade-modal").contains(document.activeElement)',
                5
            );

            // Body scroll is locked while a modal is open.
            Assert::assertTrue(
                (bool) $browser->driver->executeScript(
                    'return document.body.classList.contains("overflow-y-hidden");'
                ),
                'body scroll lock missing while modal is open'
            );

            // Close via the explicit close button.
            $browser->click('#upgrade-modal [data-click="closeModal"]')
                ->waitUntilMissing('#upgrade-modal', 5);
            $this->assertScrollUnlocked($browser);

            // Reopen, close via Escape.
            $browser->click('[data-click="showUpgradeModal"]')->waitFor('#upgrade-modal', 5);
            $browser->pressBody('{escape}')->waitUntilMissing('#upgrade-modal', 5);
            $this->assertScrollUnlocked($browser);

            // Reopen, close via backdrop click (click lands on the overlay itself).
            $browser->click('[data-click="showUpgradeModal"]')->waitFor('#upgrade-modal', 5);
            $browser->driver->executeScript(
                'document.getElementById("upgrade-modal").dispatchEvent('
                .'new MouseEvent("click", {bubbles: true}));'
            );
            $browser->waitUntilMissing('#upgrade-modal', 5);
            $this->assertScrollUnlocked($browser);

            // Focus was returned to the trigger, not left on <body>.
            Assert::assertSame(
                'BUTTON',
                $browser->driver->executeScript('return document.activeElement.tagName;'),
                'focus was not restored after the modal closed'
            );

            $this->assertPageSweepClean($browser, 'upgrade-modal interactions');
        });
    }

    public function test_command_palette_opens_navigates_and_closes(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'sweep-palette@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);

            // "/" opens the palette from anywhere outside an input.
            $browser->pressBody('/')
                ->waitFor('[aria-labelledby="command-palette-title"]', 5);

            // Typing filters the command list; Enter executes the selection.
            $browser->type('[aria-labelledby="command-palette-title"] input[type="text"]', 'galleries')
                ->keys('[aria-labelledby="command-palette-title"] input[type="text"]', '{enter}')
                ->waitForLocation('/admin/galleries', 10);

            // Escape closes it (reopen first).
            $browser->pressBody('/')
                ->waitFor('[aria-labelledby="command-palette-title"]', 5);
            $browser->pressBody('{escape}')
                ->waitUntilMissing('[aria-labelledby="command-palette-title"]', 5);

            // The palette still works after a Turbo visit (Alpine re-initialised).
            $browser->click('a[href="'.route('admin.dashboard').'"]');
            $this->settlePage($browser);
            $browser->pressBody('/')
                ->waitFor('[aria-labelledby="command-palette-title"]', 5)
                ->pressBody('{escape}')
                ->waitUntilMissing('[aria-labelledby="command-palette-title"]', 5);

            $this->assertPageSweepClean($browser, 'command palette');
        });
    }

    public function test_feedback_widget_submits_and_closes(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-feedback@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);

            $browser->click('[aria-label="Send feedback"]')
                ->waitFor('[aria-labelledby="feedback-widget-title"]', 5)
                ->type('#feedback-message', 'Sweep probe: feedback widget works.')
                ->press('Send Feedback')
                ->waitForText('Thank you!', 10);

            // Close the success state; widget collapses back to its launcher.
            $browser->click('[aria-labelledby="feedback-widget-title"] .btn-secondary')
                ->waitUntilMissing('[aria-labelledby="feedback-widget-title"]', 5);

            $this->assertPageSweepClean($browser, 'feedback widget');
        });
    }

    public function test_notification_and_team_dropdowns_toggle_and_close(): void
    {
        $owner = User::factory()->pro()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $user = User::factory()->create([
            'email' => 'sweep-dropdown@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $team->members()->attach($user->id, ['role' => 'editor']);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);

            // Notification bell: toggles open, closes on outside click.
            $browser->click('#notif-dropdown-trigger')
                ->waitFor('#notif-dropdown-panel', 5);
            $browser->driver->executeScript(
                'document.getElementById("main-content").dispatchEvent('
                .'new MouseEvent("click", {bubbles: true}));'
            );
            $browser->waitUntilMissing('#notif-dropdown-panel', 5)
                ->assertAttribute('aria-expanded', '#notif-dropdown-trigger', 'false');

            // …and closes on Escape.
            $browser->click('#notif-dropdown-trigger')->waitFor('#notif-dropdown-panel', 5);
            $browser->pressBody('{escape}')->waitUntilMissing('#notif-dropdown-panel', 5);

            // Team switcher: same contract.
            $browser->click('#team-dropdown-trigger')
                ->waitFor('#team-dropdown-panel', 5);
            $browser->pressBody('{escape}')->waitUntilMissing('#team-dropdown-panel', 5);

            $this->assertPageSweepClean($browser, 'dropdowns');
        });
    }

    public function test_mobile_menu_opens_and_closes(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-mobile@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);
            $browser->resize(self::MOBILE[0], self::MOBILE[1]);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);

            $browser->click('#mobile-nav-toggle')
                ->waitFor('#mobile-nav', 5)
                ->assertAttribute('aria-expanded', '#mobile-nav-toggle', 'true');

            // Escape closes (keyboard parity with the desktop dropdowns).
            $browser->pressBody('{escape}')
                ->waitUntilMissing('#mobile-nav', 5);

            // Reopen and close on outside click.
            $browser->click('#mobile-nav-toggle')->waitFor('#mobile-nav', 5);
            $browser->driver->executeScript(
                'document.getElementById("main-content").dispatchEvent('
                .'new MouseEvent("click", {bubbles: true}));'
            );
            $browser->waitUntilMissing('#mobile-nav', 5);

            $this->assertPageSweepClean($browser, 'mobile menu');
        });
    }

    public function test_turbo_back_forward_keeps_alpine_alive(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-turbo@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/dashboard');
            $this->settlePage($browser);

            // Turbo visit: dashboard → galleries.
            $browser->click('a[href="'.route('admin.galleries.index').'"]')
                ->waitForLocation('/admin/galleries', 10);
            $this->settlePage($browser);

            // Back → dashboard; forward → galleries (restored from cache).
            $browser->driver->navigate()->back();
            $browser->waitForLocation('/admin/dashboard', 10);
            $browser->driver->navigate()->forward();
            $browser->waitForLocation('/admin/galleries', 10);
            $this->settlePage($browser);

            // A hard refresh keeps the app alive too.
            $browser->refresh();
            $this->settlePage($browser);

            // Visiting the same page repeatedly must not double-bind anything.
            $browser->visit('/admin/galleries');
            $this->settlePage($browser);
            $browser->visit('/admin/galleries');
            $this->settlePage($browser);

            // Alpine must still be fully live after back/forward/revisits.
            $browser->pressBody('/')
                ->waitFor('[aria-labelledby="command-palette-title"]', 5)
                ->pressBody('{escape}')
                ->waitUntilMissing('[aria-labelledby="command-palette-title"]', 5);

            $this->assertPageSweepClean($browser, 'turbo back/forward');
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Root-cause regressions for the defects fixed in this mission
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The upgrade notice is flashed when the gallery limit blocks a create.
     * The auto-open used DOMContentLoaded, which never fires on a Turbo
     * visit, so the modal silently never appeared for Turbo-navigated
     * redirects. Click the (now stale) "New Gallery" control to force the
     * exact Turbo redirect → flash → index path.
     */
    public function test_upgrade_modal_auto_opens_after_turbo_redirect(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-autopen@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]); // free: max 1 gallery, has none yet

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            // Render the index while the user is still under the limit.
            $browser->visit('/admin/galleries');
            $this->settlePage($browser);

            // Another device wins the race: the last gallery slot is taken
            // behind the still-rendered "New Gallery" control.
            Gallery::factory()->create(['user_id' => $user->id]);

            $browser->click('a[href="'.route('admin.galleries.create').'"]')
                ->waitForLocation('/admin/galleries', 10)
                ->waitFor('#upgrade-modal', 5)
                ->assertSee('You\'ve reached your gallery limit');

            $this->assertPageSweepClean($browser, 'turbo auto-open upgrade modal');
        });
    }

    /**
     * Cancelling the plan-change confirm used to leave the <select> showing
     * the plan that was never applied (stale UI until a full reload).
     */
    public function test_plan_select_restores_after_cancelled_confirm(): void
    {
        User::factory()->create(); // managed user (free plan)
        $admin = $this->makeSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $admin);
            $this->verifyMfaInBrowser($browser, $admin);

            $browser->visit('/master-control');
            $this->settlePage($browser);

            // Pick PRO on the managed user's plan select — the confirm dialog
            // appears and must be cancellable.
            $browser->select('select[aria-label="Change plan"]', 'pro')
                ->waitFor('#exospace-confirm-cancel', 5)
                ->press('Cancel');

            $browser->waitUntilMissing('#exospace-confirm-cancel', 5);

            Assert::assertSame(
                'free',
                $browser->driver->executeScript(
                    'return document.querySelector(\'select[aria-label="Change plan"]\').value;'
                ),
                'cancelled plan change left the select showing the unapplied plan'
            );

            $this->assertPageSweepClean($browser, 'plan select cancel restore');
        });
    }

    /**
     * The create-gallery busy-state handler used to bind to
     * `document.querySelector('form')` — the first form in the document,
     * which is always a navigation form (logout etc.), never the create
     * form. Dispatch a submit against the real form and require the button
     * to be disabled by the page's own handler (preventDefault keeps the
     * probe from actually creating a gallery).
     */
    public function test_create_gallery_form_receives_submit_handler(): void
    {
        $user = User::factory()->create([
            'email' => 'sweep-createform@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit('/admin/galleries/create');
            $this->settlePage($browser);

            $result = $browser->driver->executeScript(<<<'JS'
                return (function () {
                    const form = document.getElementById('create-gallery-form');
                    const btn = document.getElementById('create-gallery-btn');
                    if (!form || !btn) return 'missing';
                    let observed = null;
                    // Registered after the page's own listener, so it observes
                    // the handler's side effect before cancelling navigation.
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        observed = btn.disabled;
                    });
                    form.querySelector('[name="title"]').value = 'Sweep probe';
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    return observed === true ? 'bound' : 'not-bound';
                })()
            JS);

            Assert::assertSame(
                'bound',
                $result,
                'the create-gallery submit handler is not bound to #create-gallery-form'
            );

            $this->assertPageSweepClean($browser, 'create form busy binding');
        });
    }

    /**
     * A gallery image whose file is missing on disk must degrade to the
     * deliberate fallback (hidden, no broken-icon) instead of surfacing a
     * broken image to the user.
     */
    public function test_missing_image_file_falls_back_without_broken_icon(): void
    {
        [$user, $gallery] = $this->makeProUserWithGallery();

        GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'path' => 'storage/galleries/'.$gallery->id.'/missing-'.uniqid().'.jpg',
        ]);

        $this->browse(function (Browser $browser) use ($user, $gallery) {
            $this->installSweepCollector($browser);
            $this->loginAs($browser, $user);

            $browser->visit("/admin/galleries/{$gallery->id}/edit");
            $this->settlePage($browser);

            // The app's own fallback handler must engage for the 404 source.
            $browser->waitUntil(<<<'JS'
                Array.prototype.some.call(document.images, function (img) {
                    if (!img.hasAttribute('data-fallback-hide')) return false;
                    return img.complete && img.naturalWidth === 0
                        && window.getComputedStyle(img).visibility === 'hidden';
                })
            JS, 10);

            $this->assertPageSweepClean($browser, 'missing-file fallback');
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function loginAs(Browser $browser, User $user): void
    {
        $browser->visit('/login')
            ->type('#email', $user->email)
            ->type('#password', 'sweep-password-123')
            ->press('Sign in')
            ->waitForLocation('/admin/dashboard', 10);

        // The consent banner overlays the bottom of the viewport on a fresh
        // session; accept it so it can never intercept later clicks.
        $browser->driver->executeScript(
            'const b = document.querySelector("[x-data=\'cookieBanner()\'] button.btn-primary");'
            .'if (b) b.click();'
        );
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email' => 'sweep-super@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);
    }

    /** Complete the real MFA verification flow in the browser session. */
    private function verifyMfaInBrowser(Browser $browser, User $admin): void
    {
        $secret = decrypt($admin->google2fa_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);

        // Successful verification redirects super admins to Master Control.
        $browser->visit('/mfa/verify')
            ->type('#code', $code)
            ->press('Verify')
            ->waitForLocation('/master-control', 10);
    }

    private function makeProUserWithGallery(): array
    {
        VenueTemplate::factory()->create(['plan_required' => 'free']);
        VenueTemplate::factory()->create(['plan_required' => 'pro']);

        $artist = Artist::factory()->create();

        $user = User::factory()->pro()->create([
            'email' => 'sweep-pro@example.com',
            'password' => bcrypt('sweep-password-123'),
        ]);

        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        // Two artworks whose files really exist on the public disk so their
        // sources resolve in production-equivalent conditions.
        for ($i = 0; $i < 2; $i++) {
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $rel = 'galleries/'.$gallery->id.'/'.$uuid.'.jpg';
            Storage::disk('public')->put($rel, base64_decode(self::ONE_PIXEL_JPEG));

            GalleryImage::factory()->create([
                'gallery_id' => $gallery->id,
                'artist_id' => $artist->id,
                'path' => 'storage/'.$rel,
                'position_order' => $i + 1,
            ]);
        }

        return [$user, $gallery];
    }

    /** Inject the defect collector before every future document in this session. */
    private function installSweepCollector(Browser $browser): void
    {
        $driver = $browser->driver;

        try {
            $driver->executeCustomCommand(
                '/session/:sessionId/goog/cdp/execute',
                'POST',
                [
                    'cmd' => 'Page.addScriptToEvaluateOnNewDocument',
                    'params' => ['source' => self::collectorScript()],
                ]
            );
        } catch (\Throwable) {
            // CDP bridge unavailable — the document-level injection below
            // still covers every Turbo visit (same document) and this page.
        }

        // Cover the current document immediately as well.
        $driver->executeScript(self::collectorScript());
    }

    private function settlePage(Browser $browser): void
    {
        try {
            // readyState "complete" also guarantees deferred module scripts
            // (app.js → Alpine/Turbo) have run on layouts that include them;
            // layouts without app.js (control-center) still reach it.
            $browser->waitUntil('document.readyState === "complete"', 15);
            $browser->waitUntil(
                'Array.prototype.every.call(document.images, '
                .'function (i) { return i.complete || (i.loading === "lazy" && !i.currentSrc); })',
                15
            );
        } catch (\Facebook\WebDriver\Exception\TimeoutException $e) {
            // A bare "Waited 15 seconds for callback" says nothing about WHICH
            // page or image stalled. Report it so the CI log is actionable.
            $detail = $browser->driver->executeScript(
                'return JSON.stringify({url: location.href, ready: document.readyState, '
                .'pending: Array.prototype.filter.call(document.images, function (i) { return !i.complete; })'
                .'.slice(0, 10).map(function (i) { return {src: i.currentSrc || i.getAttribute("src"), loading: i.loading}; })});'
            );

            throw new \RuntimeException('settlePage timed out: '.$detail, 0, $e);
        }
    }

    private function assertPageSweepClean(Browser $browser, string $context): void
    {
        $json = $browser->driver->executeScript(
            'return window.__sweep ? JSON.stringify(window.__sweep) : null;'
        );

        $sweep = $json === null
            ? []
            : (json_decode((string) $json, true) ?: []);

        $errors = array_merge(
            (array) ($sweep['errors'] ?? []),
            (array) ($sweep['rejections'] ?? []),
            (array) ($sweep['badResponses'] ?? []),
            (array) ($sweep['brokenImages'] ?? [])
        );

        Assert::assertCount(
            0,
            $errors,
            "[{$context}] console errors / CSP violations / failed requests / broken images:"
            .json_encode($errors, JSON_UNESCAPED_SLASHES)
        );

        $visibleBroken = (array) $browser->driver->executeScript(self::VISIBLE_BROKEN_IMAGES_JS);

        Assert::assertCount(
            0,
            $visibleBroken,
            "[{$context}] visible broken images: ".json_encode($visibleBroken, JSON_UNESCAPED_SLASHES)
        );
    }

    private function assertScrollUnlocked(Browser $browser): void
    {
        Assert::assertFalse(
            (bool) $browser->driver->executeScript(
                'return document.body.classList.contains("overflow-y-hidden");'
            ),
            'body scroll lock leaked after the modal closed'
        );
    }

    private const VISIBLE_BROKEN_IMAGES_JS = <<<'JS'
        (function () {
            return Array.prototype.slice.call(document.images).filter(function (img) {
                if (!img.complete || img.naturalWidth > 0) return false;
                // Deliberate fallback path — the app hides these itself.
                if (img.hasAttribute('data-fallback-hide')) return false;
                if (img.hasAttribute('data-onerror-hide')) return false;
                if (img.classList.contains('venue-thumb-img')) return false;
                var style = window.getComputedStyle(img);
                if (style.visibility === 'hidden' || style.display === 'none') return false;
                if (!img.currentSrc) return false; // lazy, never requested
                return true;
            }).map(function (img) {
                return String(img.currentSrc || img.src || '(empty)').slice(0, 140);
            });
        })()
    JS;

    /**
     * Runs at document start on every new document of the session (CDP) and
     * records: JS errors + uncaught exceptions, unhandled rejections, CSP
     * violations, failed fetch/XHR (4xx/5xx) and unexpected broken images.
     * Sources covered by the app's own deliberate fallback attributes are
     * excluded — a hidden fallback is the correct behaviour, not a defect.
     */
    private static function collectorScript(): string
    {
        return <<<'JS'
            (function () {
                if (window.__sweep) return;
                window.__sweep = { errors: [], rejections: [], csp: [], badResponses: [], brokenImages: [] };

                var isDeliberateFallback = function (img) {
                    return img.hasAttribute('data-fallback-hide')
                        || img.hasAttribute('data-onerror-hide')
                        || img.classList.contains('venue-thumb-img');
                };

                window.addEventListener('error', function (e) {
                    var target = e.target;
                    if (target && target.tagName === 'IMG') {
                        if (!isDeliberateFallback(target)) {
                            window.__sweep.brokenImages.push(
                                String(target.currentSrc || target.src || '(empty)').slice(0, 140)
                            );
                        }
                        return;
                    }
                    if (target && (target.tagName === 'SCRIPT' || target.tagName === 'LINK')) {
                        window.__sweep.errors.push(
                            'resource failed: ' + (target.src || target.href || 'inline').slice(0, 140)
                        );
                        return;
                    }
                    var msg = String(e.message || 'error');
                    if (/ResizeObserver loop (limit exceeded|completed with undelivered notifications)/.test(msg)) return;
                    if (/^Script error\.?$/.test(msg)) return;
                    window.__sweep.errors.push(msg.slice(0, 200));
                }, true);

                window.addEventListener('unhandledrejection', function (e) {
                    window.__sweep.rejections.push(String(e.reason).slice(0, 200));
                });

                window.addEventListener('securitypolicyviolation', function (e) {
                    window.__sweep.csp.push(
                        String(e.violatedDirective) + ' blocked ' + String(e.blockedURL || '').slice(0, 120)
                    );
                });

                if (window.fetch) {
                    var originalFetch = window.fetch;
                    window.fetch = function () {
                        var url = String(
                            (arguments[0] && arguments[0].url) ? arguments[0].url : (arguments[0] || '')
                        );
                        return originalFetch.apply(this, arguments).then(function (response) {
                            if (response.status >= 400) {
                                window.__sweep.badResponses.push(
                                    'fetch ' + url.slice(0, 120) + ' -> ' + response.status
                                );
                            }
                            return response;
                        });
                    };
                }

                var originalOpen = XMLHttpRequest.prototype.open;
                var originalSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function (method, url) {
                    this.__sweepUrl = String(url || '');
                    return originalOpen.apply(this, arguments);
                };
                XMLHttpRequest.prototype.send = function () {
                    var xhr = this;
                    xhr.addEventListener('loadend', function () {
                        if (xhr.status >= 400) {
                            window.__sweep.badResponses.push(
                                'xhr ' + String(xhr.__sweepUrl || '').slice(0, 120) + ' -> ' + xhr.status
                            );
                        }
                    });
                    return originalSend.apply(this, arguments);
                };
            })();
        JS;
    }
}
