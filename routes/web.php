<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\SuperAdmin\SystemController;
use App\Http\Controllers\SuperAdmin\VenueTemplateController;
use App\Http\Controllers\SuperAdmin\FeaturedExhibitionsController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ArtistProfileController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\NewsletterSignupController;
use Illuminate\Support\Facades\Route;

// ── Bare DB check (locked down to non-production) ───────────────────────
// WARNING: this route exposes the full schema + migration list. Disable
// it in production by setting APP_ENV=production (the env check below
// returns 404). Use `/health` for Coolify's readiness probe instead.
Route::get('/db-check', function () {
    if (app()->environment('production')) {
        abort(404);
    }
    try {
        $tables = \Illuminate\Support\Facades\DB::select("SHOW TABLES");
        $names = array_map(fn($t) => array_values((array)$t)[0], $tables);
        $migrations = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->toArray();
        return response('<pre style="background:#0d1117;color:#c9d1d9;padding:20px;font-size:12px">'
            . "DB connected: YES\n\nTables (" . count($names) . "):\n" . implode("\n", $names)
            . "\n\nRan migrations (" . count($migrations) . "):\n" . implode("\n", $migrations)
            . '</pre>', 200)->header('Content-Type', 'text/html');
    } catch (\Throwable $e) {
        return response('<pre style="background:#1a0000;color:#ff6b6b;padding:20px">'
            . "DB FAILED: " . $e->getMessage() . '</pre>', 200)->header('Content-Type', 'text/html');
    }
});

// ── Healthcheck endpoint for Coolify readiness probe ────────────────────
// P3-3/P3-4/P3-5: Full subsystem health check (DB, Redis, queue, storage).
// Returns 200 if all healthy, 503 if any subsystem is down.
Route::get('/health', [\App\Http\Controllers\HealthController::class, 'check'])->name('health');

// ── Installer (REMOVED in task C08, NEUTRALIZED in P0-5) ───────────────────
// The public /install/ directory and InstallerController have been removed.
// They were a standing risk: gated only by storage/.installed, the route
// called Artisan::call('migrate:fresh', ['--force' => true]) which drops
// every table. A missing lockfile (container rebuild without persistent
// volume) made the route reproducible — an attacker could wipe the DB.
//
// P0-5: The InstallerController.php file has been overwritten with a
// harmless stub (all methods return 404, no migrate:fresh, no .env
// writing). The installer views (resources/views/installer/*) and the
// public/install/ directory have been physically deleted. The founder
// should also `git rm app/Http/Controllers/InstallerController.php` to
// remove the stub file entirely.
//
// First-run setup is now done via artisan commands:
//   php artisan migrate --force
//   php artisan db:seed --class=VenueTemplateSeeder --force
//   php artisan storage:link
//   php artisan tinker  # create the first super-admin manually:
//     >>> $u = App\Models\User::create(['name'=>'Admin','email'=>'…','password'=>bcrypt('…')]);
//     >>> $u->forceFill(['is_super_admin'=>true,'email_verified_at'=>now()])->save();
//
// Any request to /install/ or /finalize-installation now returns 404.

// ── Webhooks ─────────────────────────────────────────────────────────────
// (Hotfix) 2Checkout sends a GET request to validate the IPN URL before
// saving it. Without a GET handler, the validation returns 405 and 2Checkout
// refuses to save the URL. This GET route returns a simple 200 OK.
Route::get ('webhooks/2checkout',         fn() => response('OK', 200));
// SEC-15 FIX: Throttle the webhook endpoint to 60 req/min/IP. 2Checkout
// normally sends one IPN per sale event — well under 1 req/min. A burst
// from a single IP indicates either a misconfigured 2Checkout retry loop
// (which 2Checkout's own retry policy shouldn't trigger beyond ~12 retries)
// or an attack probing the webhook. 60/min is generous enough to absorb
// 2Checkout's worst-case retry burst (12-15 IPNs in 60 seconds) while
// stopping a flood. The throttle uses a per-IP key — 2Checkout's INS
// servers all share a few IP ranges, so the throttle key is effectively
// "all 2Checkout traffic" in practice.
//
// HMAC signature verification (in WebhookController) is still the primary
// defense — this throttle just protects against floods of unsigned junk
// that would otherwise waste CPU on hash verification.
Route::post('/webhooks/2checkout',        [WebhookController::class, 'handle2Checkout'])->name('webhooks.2checkout')->middleware('throttle:60,1');
Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])->name('webhooks.2checkout.refund')->middleware('throttle:60,1');

// ── SEO & discovery endpoints ────────────────────────────────────────────
// S-4: Sitemap index with pagination — /sitemap.xml is the index,
// /sitemap-{page}.xml are the sub-sitemaps (500 URLs each).
Route::get('/sitemap.xml', [SitemapController::class, 'sitemapIndex'])->name('sitemap');
Route::get('/sitemap-{page}.xml', [SitemapController::class, 'sitemapPage'])->where('page', '[0-9]+')->name('sitemap.page');
Route::get('/feed.xml',    [SitemapController::class, 'feed'])->name('feed');
Route::get('/discover',    [DiscoverController::class, 'index'])->name('discover');

// ── Public pages ─────────────────────────────────────────────────────────
Route::get('/', fn() => view('welcome'))->name('welcome');
Route::view('/privacy',          'pages.privacy')->name('privacy');
Route::view('/terms',            'pages.terms')->name('terms');
Route::view('/refund-policy',    'pages.refund')->name('refund');
Route::view('/about',            'pages.about')->name('about');
Route::view('/payment-security', 'pages.security')->name('security');
Route::view('/pricing',          'pages.pricing')->name('pricing');
Route::view('/contact',          'pages.contact')->name('contact');
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit'])->name('contact.submit')->middleware('throttle:5,10');

// ── Unsubscribe (P0-3: CAN-SPAM/GDPR one-click unsubscribe) ──────────────
// BOTH routes are protected by Laravel's `signed` middleware — the
// signature is HMAC-signed with APP_KEY, so only the app can generate
// valid unsubscribe links. An attacker cannot forge a link to unsubscribe
// another user.
//
// P0-3 AUDIT FIX: The POST route previously lacked the `signed` middleware,
// creating an IDOR vulnerability — any visitor with a CSRF token could POST
// to /unsubscribe/{userId} and unsubscribe any user. The POST route now
// also requires a valid signature, and the form on the GET page includes
// the signature as a hidden field so it's submitted with the POST.
Route::get('/unsubscribe/{user}',      [\App\Http\Controllers\UnsubscribeController::class, 'show'])->name('unsubscribe.show')->middleware('signed');
Route::post('/unsubscribe/{user}',     [\App\Http\Controllers\UnsubscribeController::class, 'confirm'])->name('unsubscribe.confirm')->middleware('signed');
Route::get('/unsubscribe-done',        [\App\Http\Controllers\UnsubscribeController::class, 'done'])->name('unsubscribe.done');

// ── Demo redirect ────────────────────────────────────────────────────────
Route::get('/gallery/demo', function () {
    $gallery = \App\Models\Gallery::where('is_active', true)->first();
    return $gallery ? redirect()->route('gallery.view', $gallery->slug) : redirect('/')->with('error', 'No demo gallery available yet.');
});

// ── Public artist profile (Round 4) ──────────────────────────────────────
Route::get('/artist/{slug}', [ArtistProfileController::class, 'show'])->name('artist.profile');

// ── Per-gallery: PIN entry, OG image, QR code, events, newsletter ────────
// PIN verify is throttled at two layers (task C07):
//   1. Route-level `throttle:5,1` — 5 req/min/IP across all PIN endpoints
//   2. Per-gallery lockout in GalleryPinController — after 5 failed attempts
//      for a (gallery, IP) pair, that IP is locked out of that gallery's PIN
//      for 15 minutes. Stops distributed brute-force against a single gallery.
Route::get('/gallery/{slug}/pin',       [\App\Http\Controllers\GalleryPinController::class, 'show'])->name('gallery.pin');
Route::post('/gallery/{slug}/pin',      [\App\Http\Controllers\GalleryPinController::class, 'verify'])->name('gallery.pin.verify')
      ->middleware('throttle:5,1');
Route::get('/gallery/{slug}/og-image',  [OgImageController::class, 'show'])->name('gallery.og-image');
Route::get('/gallery/{slug}/qr',        [QrCodeController::class, 'show'])->name('gallery.qr');

// Public events page + RSVP (Round 4)
Route::get('/gallery/{slug}/events',                       [PublicEventController::class, 'index'])->name('gallery.events.index');
Route::post('/gallery/{slug}/events/{event}/rsvp',         [PublicEventController::class, 'rsvp'])->name('gallery.events.rsvp')
      ->middleware('throttle:10,1');

// Newsletter signup (Round 4)
Route::post('/gallery/{slug}/newsletter', [NewsletterSignupController::class, 'store'])->name('gallery.newsletter')
      ->middleware('throttle:10,1');

// ── Public gallery view ──────────────────────────────────────────────────
// Throttled at 60 req/min/IP to absorb viral spikes without DOSing the
// 3D scene bootstrap. A genuine visitor loads the page once; a scraper
// hitting 60+ times in a minute is abusive.
Route::get('/gallery/{slug}', [\App\Http\Controllers\GalleryViewController::class, 'show'])
    ->name('gallery.view')
    ->middleware('throttle:60,1');

// ── Analytics tracking (public, no auth) ─────────────────────────────────
// (Task H06 / audit H12) — lowered throttle from 120/min to 30/min.
// 120/min was too generous and allowed view-count inflation. A genuine
// visitor loads the page once and fires ~5-10 events (view, focus, dwell,
// tour_start) — 30/min is ample for that, and stops a script from
// generating 120 fake views per minute per IP.
Route::post('/gallery/{gallery}/track', [\App\Http\Controllers\Admin\AnalyticsController::class, 'track'])
    ->name('gallery.track')
    ->middleware('throttle:30,1');

// ── Team Invitations ─────────────────────────────────────────────────────
// SEC-6 FIX: The show route now requires a signed URL (HMAC with APP_KEY).
// Previously the URL contained only the plain token — if it leaked via a
// referrer header, browser history, or email forwarding, anyone could view
// the invitation page (though they still couldn't accept without being
// logged in as the matching email). The signed URL adds a second factor:
// even with the token, an attacker can't forge a valid URL without APP_KEY.
// Accept/decline routes don't need signed URLs — they require auth + the
// logged-in user's email must match the invited email (see controller).
Route::get('/team-invitations/{token}',          [TeamInvitationController::class, 'show'])->name('team-invitations.show')->middleware('signed');
Route::post('/team-invitations/{token}/accept',  [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
Route::post('/team-invitations/{token}/decline', [TeamInvitationController::class, 'decline'])->name('team-invitations.decline');

// ── Auth ─────────────────────────────────────────────────────────────────
Route::get('/dashboard', fn() => redirect()->route('admin.dashboard'))->middleware(['auth', 'verified'])->name('dashboard');
// P1-7 FIX: Added 'verified' middleware — previously only 'auth' was applied,
// allowing unverified-email users to access /profile/export (GDPR PII export)
// and /billing/upgrade/{plan} (mint pending_upgrade + redirect to 2Checkout).
// The /dashboard route above already had 'verified'; this was an inconsistency.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // GDPR Art. 20 — right to data portability. Returns JSON download of
    // the user's profile, galleries, images metadata, transactions, teams,
    // and artist profiles. (Task C10.)
    Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');

    // (Task H56) — MFA setup + verification routes for super-admins.
    // SEC-4: Now also available to regular users (opt-in).
    // Setup: shows QR code for Google Authenticator / Authy / 1Password.
    // Verify: enters the 6-digit TOTP code to complete MFA for the session.
    // P1-5 FIX: Added throttle:6,1 to POST routes — a 6-digit TOTP has
    // only 1M values; without throttle, an attacker with a stolen session
    // cookie can brute-force it in ~8 hours at 1000 attempts/min. 6
    // attempts per minute is ample for a human typing codes.
    Route::get('/mfa/setup', [\App\Http\Controllers\MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/setup', [\App\Http\Controllers\MfaController::class, 'enable'])->middleware('throttle:6,1');
    Route::get('/mfa/verify', [\App\Http\Controllers\MfaController::class, 'showVerify'])->name('mfa.verify');
    Route::post('/mfa/verify', [\App\Http\Controllers\MfaController::class, 'verify'])->middleware('throttle:6,1');
    // P3-7: One-time backup codes display after MFA enable
    Route::get('/mfa/backup-codes', [\App\Http\Controllers\MfaController::class, 'showBackupCodes'])->name('mfa.backup-codes');

    // ── Billing portal + upgrade flow (tasks H01 + H02) ────────────────
    // /billing shows current plan, transaction history, pending upgrades.
    // /billing/upgrade/{plan} generates a pending_upgrade token and
    // redirects to 2Checkout with external-reference=<token> + pre-filled
    // customer_email — closes the silent-revenue-leak bug where email
    // mismatches orphaned payments.
    //
    // SEC-4/5: Billing routes are gated behind the 'mfa' middleware so
    // regular users who have opted into MFA must re-verify before changing
    // their plan. Users who haven't enabled MFA pass through unaffected
    // (the RequireMfa middleware short-circuits for them).
    Route::middleware(['mfa'])->group(function () {
        Route::get('/billing',                [\App\Http\Controllers\BillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/upgrade/{plan}', [\App\Http\Controllers\BillingController::class, 'upgrade'])->name('billing.upgrade')
              ->where('plan', 'pro|studio');

        // M-1: Subscription management routes
        Route::post('/billing/cancel-subscription',     [\App\Http\Controllers\BillingController::class, 'cancelSubscription'])->name('billing.cancel-subscription');
        Route::post('/billing/reactivate-subscription', [\App\Http\Controllers\BillingController::class, 'reactivateSubscription'])->name('billing.reactivate-subscription');

        // M-10: Invoice download
        Route::get('/billing/invoice/{invoice}',        [\App\Http\Controllers\BillingController::class, 'downloadInvoice'])->name('billing.invoice');
    });
});

// ── Admin ────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Galleries ──────────────────────────────────────────────────────────
    Route::get('galleries',                [\App\Http\Controllers\Admin\GalleryController::class, 'index'])->name('galleries.index');
    Route::get('galleries/create',         [\App\Http\Controllers\Admin\GalleryController::class, 'create'])->name('galleries.create');
    Route::post('galleries',               [\App\Http\Controllers\Admin\GalleryController::class, 'store'])->name('galleries.store');
    Route::get('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'show'])->name('galleries.show');
    Route::get('galleries/{gallery}/edit', [\App\Http\Controllers\Admin\GalleryController::class, 'edit'])->name('galleries.edit');

    // NEW (Live Preview) — admin-only preview iframe target.
    // Skips PIN + time-gate + view-count bump; the curator owns the gallery.
    // Accepts an optional ?override=<base64-json> so the iframe can be
    // reloaded with un-saved slider tweaks baked into the URL.
    Route::get('galleries/{gallery}/preview', [\App\Http\Controllers\Admin\GalleryController::class, 'preview'])->name('galleries.preview');

    Route::put('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'update'])->name('galleries.update');
    Route::delete('galleries/{gallery}',   [\App\Http\Controllers\Admin\GalleryController::class, 'destroy'])->name('galleries.destroy');

    // NEW (Round 2): gallery duplication
    Route::post('galleries/{gallery}/duplicate', [\App\Http\Controllers\Admin\GalleryController::class, 'duplicate'])->name('galleries.duplicate');

    Route::post('galleries/{gallery}/upload-audio',   [\App\Http\Controllers\Admin\GalleryController::class, 'uploadAudio'])->name('galleries.upload-audio');
    Route::post('galleries/{gallery}/upload-logo',    [\App\Http\Controllers\Admin\GalleryController::class, 'uploadLogo'])->name('galleries.upload-logo');
    Route::post('galleries/{gallery}/reorder-images', [\App\Http\Controllers\Admin\GalleryController::class, 'reorderImages'])->name('galleries.reorder-images');
    Route::get('galleries/{gallery}/analytics',       [\App\Http\Controllers\Admin\AnalyticsController::class, 'show'])->name('galleries.analytics');

    // Custom-domain DNS verification (Task C06). User adds the TXT record
    // to their DNS, then clicks "Verify domain" which hits this endpoint.
    // Also retried hourly by the exospace:verify-pending-domains command.
    Route::post('galleries/{gallery}/verify-domain',  [\App\Http\Controllers\Admin\GalleryController::class, 'verifyCustomDomain'])->name('galleries.verify-domain');

    // NEW (Round 4): per-artwork metadata editor
    Route::put('galleries/{gallery}/images/{image}/metadata', [\App\Http\Controllers\Admin\ImageMetadataController::class, 'update'])->name('galleries.images.metadata');

    // NEW (Round 4): gallery event calendar
    Route::get('galleries/{gallery}/events',                      [\App\Http\Controllers\Admin\GalleryEventController::class, 'index'])->name('galleries.events.index');
    Route::get('galleries/{gallery}/events/create',               [\App\Http\Controllers\Admin\GalleryEventController::class, 'create'])->name('galleries.events.create');
    Route::post('galleries/{gallery}/events',                     [\App\Http\Controllers\Admin\GalleryEventController::class, 'store'])->name('galleries.events.store');
    Route::get('galleries/{gallery}/events/{event}/edit',         [\App\Http\Controllers\Admin\GalleryEventController::class, 'edit'])->name('galleries.events.edit');
    Route::put('galleries/{gallery}/events/{event}',              [\App\Http\Controllers\Admin\GalleryEventController::class, 'update'])->name('galleries.events.update');
    Route::delete('galleries/{gallery}/events/{event}',           [\App\Http\Controllers\Admin\GalleryEventController::class, 'destroy'])->name('galleries.events.destroy');
    Route::get('galleries/{gallery}/events/{event}/rsvps',        [\App\Http\Controllers\Admin\GalleryEventController::class, 'rsvps'])->name('galleries.events.rsvps');

    // ── Images ─────────────────────────────────────────────────────────────
    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])->name('images.store')->middleware('throttle:30,1');
    Route::post('images/bulk-delete',         [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])->name('images.bulk_destroy');
    Route::delete('images/{image}',           [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])->name('images.destroy');

    // ── Artists (Round 4) ──────────────────────────────────────────────────
    Route::get('artists',                   [\App\Http\Controllers\Admin\ArtistController::class, 'index'])->name('artists.index');
    Route::get('artists/create',            [\App\Http\Controllers\Admin\ArtistController::class, 'create'])->name('artists.create');
    Route::post('artists',                  [\App\Http\Controllers\Admin\ArtistController::class, 'store'])->name('artists.store');
    Route::get('artists/{artist}',          [\App\Http\Controllers\Admin\ArtistController::class, 'show'])->name('artists.show');
    Route::get('artists/{artist}/edit',     [\App\Http\Controllers\Admin\ArtistController::class, 'edit'])->name('artists.edit');
    Route::put('artists/{artist}',          [\App\Http\Controllers\Admin\ArtistController::class, 'update'])->name('artists.update');
    Route::delete('artists/{artist}',       [\App\Http\Controllers\Admin\ArtistController::class, 'destroy'])->name('artists.destroy');
    Route::get('artists-search',            [\App\Http\Controllers\Admin\ArtistController::class, 'search'])->name('artists.search');

    // ── Teams ──────────────────────────────────────────────────────────────
    Route::get   ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'index'])->name('teams.index');
    Route::get   ('teams/create',                      [\App\Http\Controllers\Admin\TeamController::class, 'create'])->name('teams.create');
    Route::post  ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'store'])->name('teams.store');
    // NOTE: kept as a Closure for now — the Auth facade root alias makes
    // this work without an explicit `use` import. If you refactor, move
    // it into TeamController::switchToPersonal() and import Auth there.
    Route::post  ('teams/switch-personal',             function () {
        \Illuminate\Support\Facades\Auth::user()->forceFill(['current_team_id' => null])->save();
        return redirect()->route('admin.galleries.index')
                         ->with('status', 'Switched to personal workspace.');
    })->name('teams.switch-personal');
    Route::get   ('teams/{team}',                      [\App\Http\Controllers\Admin\TeamController::class, 'show'])->name('teams.show');
    Route::patch ('teams/{team}',                      [\App\Http\Controllers\Admin\TeamController::class, 'update'])->name('teams.update');
    Route::delete('teams/{team}',                      [\App\Http\Controllers\Admin\TeamController::class, 'destroy'])->name('teams.destroy');

    Route::post  ('teams/{team}/invite',               [\App\Http\Controllers\Admin\TeamController::class, 'invite'])->name('teams.invite');
    Route::delete('teams/{team}/invitations/{invitation}', [\App\Http\Controllers\Admin\TeamController::class, 'revokeInvitation'])->name('teams.revoke-invitation');
    Route::delete('teams/{team}/members',              [\App\Http\Controllers\Admin\TeamController::class, 'removeMember'])->name('teams.remove-member');
    Route::patch ('teams/{team}/members/role',         [\App\Http\Controllers\Admin\TeamController::class, 'updateMemberRole'])->name('teams.update-role');
    Route::delete('teams/{team}/leave',                [\App\Http\Controllers\Admin\TeamController::class, 'leave'])->name('teams.leave');
    Route::post  ('teams/{team}/switch',               [\App\Http\Controllers\Admin\TeamController::class, 'switchTeam'])->name('teams.switch');
});

// ── Super Admin (Task H56 — MFA required for all super-admin routes) ──────
Route::middleware(['auth', 'verified', 'super_admin', 'mfa'])->prefix('master-control')->name('super.')->group(function () {
    Route::get('/',                                    [SystemController::class, 'index'])->name('index');
    Route::post('/users/{user}/plan',                  [SystemController::class, 'updatePlan'])->name('updatePlan')
          ->middleware('password.confirm');
    Route::delete('/users/{user}',                     [SystemController::class, 'deleteUser'])->name('deleteUser')
          ->middleware('password.confirm');
    Route::get('/users/{user}/galleries',              [SystemController::class, 'userGalleries'])->name('user-galleries');
    Route::post('/galleries/{gallery}/toggle',         [SystemController::class, 'toggleGallery'])->name('toggleGallery');

    // Account controls — destructive actions get password.confirm (audit H18)
    Route::post('/users/{user}/ban',                   [SystemController::class, 'banUser'])->name('banUser')
          ->middleware('password.confirm');
    Route::post('/users/{user}/unban',                 [SystemController::class, 'unbanUser'])->name('unbanUser');
    Route::post('/users/{user}/verify-email',          [SystemController::class, 'verifyEmail'])->name('verifyEmail');
    Route::post('/users/{user}/unverify-email',        [SystemController::class, 'unverifyEmail'])->name('unverifyEmail')
          ->middleware('password.confirm');
    Route::post('/users/{user}/toggle-super-admin',    [SystemController::class, 'toggleSuperAdmin'])->name('toggleSuperAdmin')
          ->middleware('password.confirm');

    // ── Venue Templates Management (full CRUD) ──────────────────────────────
    Route::get   ('venues',                            [VenueTemplateController::class, 'index'])->name('venues.index');
    Route::get   ('venues/create',                     [VenueTemplateController::class, 'create'])->name('venues.create');
    Route::post  ('venues',                            [VenueTemplateController::class, 'store'])->name('venues.store');
    Route::get   ('venues/{venue}/edit',               [VenueTemplateController::class, 'edit'])->name('venues.edit');
    Route::put   ('venues/{venue}',                    [VenueTemplateController::class, 'update'])->name('venues.update');
    Route::patch ('venues/{venue}/toggle',             [VenueTemplateController::class, 'toggle'])->name('venues.toggle');
    Route::patch ('venues/{venue}/toggle-featured',    [VenueTemplateController::class, 'toggleFeatured'])->name('venues.toggle-featured');
    Route::delete('venues/{venue}',                    [VenueTemplateController::class, 'destroy'])->name('venues.destroy');

    // ── Featured Exhibitions (Round 4) ──────────────────────────────────────
    Route::get   ('featured',                          [FeaturedExhibitionsController::class, 'index'])->name('featured.index');
    Route::patch ('featured/{gallery}',                [FeaturedExhibitionsController::class, 'toggle'])->name('featured.toggle');

    // ── Pending Upgrades (Task H67) ────────────────────────────────────────
    Route::get   ('pending-upgrades',                  [SystemController::class, 'pendingUpgrades'])->name('pending-upgrades.index');
    Route::post  ('pending-upgrades/{pending}/manual-upgrade', [SystemController::class, 'manualUpgrade'])->name('pending-upgrades.manual-upgrade')
          ->middleware('password.confirm');

    // M-13: Admin impersonation — start (requires super-admin + password.confirm + feature flag)
    Route::post('/users/{user}/impersonate',           [SystemController::class, 'impersonate'])->name('impersonate')
          ->middleware('password.confirm', 'feature_flag:admin_impersonation');
});

// M-13: Admin impersonation — stop (outside super-admin group because the
// impersonated user is NOT a super-admin. The ImpersonationService checks
// the session key to verify impersonation is active, so this route is safe
// to be accessible by any authenticated user — if they're not impersonating,
// it's a no-op.)
Route::middleware(['auth'])->group(function () {
    Route::post('/master-control/stop-impersonating',  [\App\Http\Controllers\SuperAdmin\SystemController::class, 'stopImpersonating'])->name('super.stop-impersonating');

    // M-12: In-app notifications
    Route::post('/notifications/{notification}/read',     [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/mark-all-read',            [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
});

// M-20: Public status page (no auth required)
Route::get('/status', [\App\Http\Controllers\StatusController::class, 'show'])->name('status');

require __DIR__.'/auth.php';
