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
use App\Http\Controllers\ArtistDirectoryController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\NewsletterSignupController;
use Illuminate\Support\Facades\Route;

// ── Bare DB check (locked down to super-admins) ────────────────────────
// AUDIT-P0-1.4 FIX: Previously only gated by `app()->environment('production')`
// returning 404 — which still leaked the full schema + migration list in
// staging. Now requires super_admin middleware (auth + verified + super_admin
// + mfa) in ALL environments, including local. Use `/health` for Coolify's
// readiness probe instead.
Route::get('/db-check', function () {
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
})->middleware(['auth', 'verified', 'super_admin', 'mfa'])->name('db-check');

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
// SEO OS (Iteration 4): grouped sitemap architecture.
//   /sitemap.xml                  → index of groups (static, galleries,
//                                   artists, artworks, content)
//   /sitemap-{group}-{page}.xml   → group sub-sitemaps (2,000 URLs each)
//   /sitemap-{page}.xml           → LEGACY gallery sitemaps → 301 to the
//                                   galleries group (preserves old refs)
//   /robots.txt                   → dynamic, host-aware directives
//   /feed.xml                     → RSS of recently updated exhibitions
Route::get('/robots.txt', \App\Http\Controllers\RobotsController::class)->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{group}-{page}.xml', [SitemapController::class, 'group'])
    ->where(['group' => '[a-z]+', 'page' => '[0-9]+'])
    ->name('sitemap.group');
Route::get('/sitemap-{page}.xml', [SitemapController::class, 'legacy'])->where('page', '[0-9]+')->name('sitemap.page');
Route::get('/feed.xml',    [SitemapController::class, 'feed'])->name('feed');
Route::get('/discover',    [DiscoverController::class, 'index'])->name('discover');

// ── SEO OS (Iteration 2): public entity hubs + artwork pages ─────────────
Route::get('/artists',     [ArtistDirectoryController::class, 'index'])->name('artists.index');
Route::get('/venues',      [\App\Http\Controllers\PublicVenueController::class, 'index'])->name('venues.index');

// Iteration 1 "The Rehearsal" (roadmap P1.1) — walkable venue preview.
// Registered BEFORE the single-segment /venues/{slug} for clarity; both
// can coexist because the paths differ in segment count.
//
// Safety envelope (each property pinned by VenuePreviewIterationTest):
//   - PUBLIC, no auth  — previews ARE the funnel; never gate behind signup
//     (roadmap DO NOT DO #10). Rate-limiting is the abuse control instead.
//   - throttle:20,1    — per-IP; generous for humans walking venues, hard
//     enough to stop scripted hammering of the 3D asset surface.
//   - feature_flag:venue_previews — aborts 404 when the flag is off, so
//     the whole surface can be disabled with one env var (rollback path:
//     "route stays harmless").
//   - Sample-data-only + noindex are enforced inside
//     VenuePreviewController + venues/preview.blade.php.
Route::get('/venues/{slug}/preview', [\App\Http\Controllers\VenuePreviewController::class, 'show'])
    ->name('venues.preview')
    ->middleware('throttle:20,1', 'feature_flag:venue_previews');

Route::get('/venues/{slug}', [\App\Http\Controllers\PublicVenueController::class, 'show'])->name('venues.show');

// ── Public pages ─────────────────────────────────────────────────────────
Route::get('/', fn() => view('welcome'))->name('welcome');
Route::view('/privacy',          'pages.privacy')->name('privacy');
Route::view('/terms',            'pages.terms')->name('terms');
Route::view('/refund-policy',    'pages.refund')->name('refund');
Route::view('/about',            'pages.about')->name('about');
Route::view('/payment-security', 'pages.security')->name('security');
Route::view('/pricing',          'pages.pricing')->name('pricing');
Route::view('/contact',          'pages.contact')->name('contact');
Route::get ('/changelog',        [\App\Http\Controllers\ChangelogController::class, 'show'])->name('changelog');
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

// ── RFC 8058 One-Click Unsubscribe (Iter-007 / audit issue 9) ────────────
// Gmail/Yahoo enforce RFC 8058 for bulk senders since Feb 2024. The
// List-Unsubscribe + List-Unsubscribe-Post headers (set by the
// HasMarketingUnsubscribe trait on every marketing mailable) point at
// these routes. The POST route is hit by Gmail's automated unsubscribe
// machinery — it MUST NOT require CSRF (the request comes from Gmail's
// servers, not the user's browser, and has no CSRF token). The signed
// URL is the only auth.
//
// The POST route is added to the $except array of VerifyCsrfToken in
// bootstrap/app.php. The `signed` middleware verifies the URL signature.
//
// The same URL also serves a GET — a user who copies the header URL
// into a browser sees a simple "unsubscribed" page (no confirmation
// step, per RFC 8058 §3 which expects a single round-trip).
Route::get('/unsubscribe/one-click/{user}',  [\App\Http\Controllers\UnsubscribeController::class, 'oneClickShow'])->name('unsubscribe.one-click')->middleware('signed');
Route::post('/unsubscribe/one-click/{user}', [\App\Http\Controllers\UnsubscribeController::class, 'oneClickPost'])->name('unsubscribe.one-click.post')->middleware('signed');

// ── Demo redirect ────────────────────────────────────────────────────────
// SEO OS (Iter-002, audit M9): use publiclyViewable() instead of bare
// is_active — the demo must never leak a PIN-protected or scheduled
// gallery URL.
Route::get('/gallery/demo', function () {
    $gallery = \App\Models\Gallery::publiclyViewable()->has('images', '>=', 1)->first();
    return $gallery ? redirect()->route('gallery.view', $gallery->slug) : redirect('/')->with('error', 'No demo gallery available yet.');
});

// ── Public artist profile (Round 4) ──────────────────────────────────────
Route::get('/artist/{slug}', [ArtistProfileController::class, 'show'])->name('artist.profile');

// SEO OS (Iteration 2): artist OG image + artwork landing pages.
Route::get('/artist/{slug}/og-image', [OgImageController::class, 'artist'])->name('artist.og-image');
Route::get('/gallery/{slug}/artwork/{image}', [\App\Http\Controllers\ArtworkController::class, 'show'])
    ->name('artwork.show')
    ->middleware('throttle:60,1');

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

        // M-2: Self-serve downgrade
        Route::post('/billing/downgrade',               [\App\Http\Controllers\BillingController::class, 'downgrade'])->name('billing.downgrade');

        // M-7: Trial period
        Route::post('/billing/start-trial/{plan}',      [\App\Http\Controllers\BillingController::class, 'startTrial'])->name('billing.start-trial')
              ->where('plan', 'pro|studio');

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

    // ITERATION-2 (publish moment): explicit publish / unpublish actions.
    // Draft-by-default galleries go live through POST …/publish (requires
    // at least one artwork) and return to draft through POST …/unpublish.
    Route::post('galleries/{gallery}/publish',   [\App\Http\Controllers\Admin\GalleryController::class, 'publish'])->name('galleries.publish');
    Route::post('galleries/{gallery}/unpublish', [\App\Http\Controllers\Admin\GalleryController::class, 'unpublish'])->name('galleries.unpublish');

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

    // ── Iteration 5 "Authoring" (roadmap P2.1) ─────────────────────────────
    // The in-product authoring loop: clone → tweak → preview → publish →
    // rollback → retire. Every route is additive and gated behind the
    // venue_authoring flag; rollback = FEATURE_FLAG_VENUE_AUTHORING=false
    // (routes 404, UI affordances hide, snapshot capture pauses) — the
    // core CRUD above keeps working untouched. NOTE: `clone` is a PHP
    // reserved word, hence cloneVenue().
    Route::post  ('venues/{venue}/clone',              [VenueTemplateController::class, 'cloneVenue'])->name('venues.clone')
          ->middleware('feature_flag:venue_authoring');
    Route::patch ('venues/{venue}/publish',            [VenueTemplateController::class, 'publish'])->name('venues.publish')
          ->middleware('feature_flag:venue_authoring');
    Route::patch ('venues/{venue}/unpublish',          [VenueTemplateController::class, 'unpublish'])->name('venues.unpublish')
          ->middleware('feature_flag:venue_authoring');
    Route::patch ('venues/{venue}/unarchive',          [VenueTemplateController::class, 'unarchive'])->name('venues.unarchive')
          ->middleware('feature_flag:venue_authoring');
    Route::post  ('venues/{venue}/snapshots/{snapshot}/restore', [VenueTemplateController::class, 'restoreSnapshot'])->name('venues.snapshots.restore')
          ->middleware('feature_flag:venue_authoring');

    // DELETE is now ARCHIVE (§9.2 #4 — hard delete is gone; galleries using
    // the venue are guarded by the confirm_usage flag and keep rendering).
    // Deliberately NOT flag-gated: archive is strictly safer than the hard
    // delete it replaces, so there is no rollback-to-hard-delete path.
    Route::delete('venues/{venue}',                    [VenueTemplateController::class, 'destroy'])->name('venues.destroy');

    // ── Featured Exhibitions (Round 4) ──────────────────────────────────────
    Route::get   ('featured',                          [FeaturedExhibitionsController::class, 'index'])->name('featured.index');
    Route::patch ('featured/{gallery}',                [FeaturedExhibitionsController::class, 'toggle'])->name('featured.toggle');

    // ── SEO Operations (SEO OS Iteration 6) ─────────────────────────────────
    // Health dashboard, seo_profiles overrides, redirect manager, SEO page
    // publishing. Mirrors the audit log conventions of the other super-admin
    // controllers (every mutation recorded via AdminAuditLog::record).
    Route::get   ('seo',                               [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'index'])->name('seo.index');
    Route::post  ('seo/profile/{type}/{id}',           [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'updateProfile'])->name('seo.profile.update');
    Route::post  ('seo/redirects',                     [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'storeRedirect'])->name('seo.redirects.store');
    Route::delete('seo/redirects/{redirect}',          [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'destroyRedirect'])->name('seo.redirects.destroy');
    Route::post  ('seo/pages/{page}/toggle',           [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'togglePage'])->name('seo.pages.toggle');
    Route::post  ('seo/rebuild',                       [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'rebuild'])->name('seo.rebuild');

    // ── Pending Upgrades (Task H67) ────────────────────────────────────────
    Route::get   ('pending-upgrades',                  [SystemController::class, 'pendingUpgrades'])->name('pending-upgrades.index');
    Route::post  ('pending-upgrades/{pending}/manual-upgrade', [SystemController::class, 'manualUpgrade'])->name('pending-upgrades.manual-upgrade')
          ->middleware('password.confirm');

    // ── Billing Review (ITERATION 4) ──────────────────────────────────────
    // Refunds / chargebacks / webhook ledger with payload viewer + replay.
    // Replay mutates billing state through the webhook pipeline — the same
    // password.confirm bar as manual upgrades.
    Route::get   ('billing',                           [\App\Http\Controllers\SuperAdmin\BillingController::class, 'index'])->name('billing.index');
    // ITERATION 5: streamed CSV export of the same data + filters (read-only,
    // but audit-logged — the CSV carries customer PII out of the system).
    Route::get   ('billing/export',                    [\App\Http\Controllers\SuperAdmin\BillingController::class, 'export'])->name('billing.export');
    Route::post  ('billing/webhooks/{webhook}/replay', [\App\Http\Controllers\SuperAdmin\BillingController::class, 'replayWebhook'])
          ->whereNumber('webhook')
          ->name('billing.replay')
          ->middleware('password.confirm');

    // ITERATION 7: digest recipient management. Add/remove emails for the
    // weekly billing digest — the UI-managed list takes over from the
    // BILLING_EXPORT_EMAIL env var once any recipient is added. Same
    // audit-logging bar as the export (financial-data redirects); no
    // password.confirm — team invitations (a comparable sensitivity:
    // grants access) don't use it either, and the master-control surface
    // already requires super-admin + MFA.
    Route::post  ('billing/recipients',                [\App\Http\Controllers\SuperAdmin\BillingController::class, 'storeRecipient'])->name('billing.recipients.store')
          ->middleware('throttle:30,1'); // ITERATION 8: throttle (audit-fix E-1)
    Route::delete('billing/recipients/{recipient}',    [\App\Http\Controllers\SuperAdmin\BillingController::class, 'destroyRecipient'])
          ->whereNumber('recipient')
          ->name('billing.recipients.destroy')
          ->middleware('throttle:30,1'); // ITERATION 8: throttle (audit-fix E-1)

    // ── Outbound webhook subscriptions (ITERATION 10) ───────────────────
    // DB-backed per-event subscription management. Same trust bar as
    // billing recipient management: super-admin + MFA + audit-logged +
    // throttle 30,1. No password.confirm — the add/toggle/remove
    // actions are reversible (re-adding a removed subscription is one
    // click; pausing without deleting preserves the config).
    Route::get   ('webhooks',                           [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
    Route::post  ('webhooks',                           [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'store'])->name('webhooks.store')
          ->middleware('throttle:30,1');
    Route::patch ('webhooks/{subscription}/toggle',    [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'toggle'])->name('webhooks.toggle')
          ->whereNumber('subscription')
          ->middleware('throttle:30,1');
    Route::delete('webhooks/{subscription}',            [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy')
          ->whereNumber('subscription')
          ->middleware('throttle:30,1');

    // ITERATION 11 — per-subscription delivery history page.
    // Read-only surface backed by the webhook_deliveries ledger
    // table. The operator's triage view for "did the security team
    // receive the recipient_added webhook last Tuesday?" — paginated
    // list of every delivery row for this subscription. No audit
    // log row (view list ≠ export PII — same precedent as the
    // billing review index page). No throttle — read-only views
    // don't need spam protection (and the super-admin + MFA gate
    // is already in front).
    Route::get   ('webhooks/{subscription}/deliveries',  [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'deliveries'])->name('webhooks.deliveries')
          ->whereNumber('subscription');

    // M-13: Admin impersonation — start (requires super-admin + password.confirm + feature flag)
    Route::post('/users/{user}/impersonate',           [SystemController::class, 'impersonate'])->name('impersonate')
          ->middleware('password.confirm', 'feature_flag:admin_impersonation');

    // M-19: Feedback management (super-admin triage)
    Route::get('/feedback',                              [\App\Http\Controllers\FeedbackController::class, 'index'])->name('feedback.index');
    Route::patch('/feedback/{feedback}/status',          [\App\Http\Controllers\FeedbackController::class, 'updateStatus'])->name('feedback.update-status');

    // M-18: NPS dashboard
    Route::get('/nps',                                   [\App\Http\Controllers\SurveyController::class, 'npsDashboard'])->name('nps.index');

    // M-5: Affiliate dashboard
    Route::get('/affiliates',                            [\App\Http\Controllers\AffiliateDashboardController::class, 'index'])->name('affiliates.index');

    // ── Retention drill-down (ITERATION 7) ────────────────────────────────
    // PII-gated: clicking a cohort matrix cell opens the underlying user
    // list. Read-only PII reveal behind the group middleware (super-admin +
    // MFA), audit-logged per request — same trust bar as billing review.
    // No password.confirm: this is a read of the same PII that already
    // appears on the dashboard users table; the audit row preserves
    // attribution (who viewed which cohort, when).
    Route::get('/retention/{cohort}',                    [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'cohort'])
          ->where('cohort', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
          ->name('retention.cohort')
          ->middleware('throttle:60,1'); // ITERATION 8: throttle (audit-fix E-1)

    // ITERATION 8: streamed CSV export of a cohort's members — same audit-
    // logged PII surface as the page itself (no password.confirm; the CSV
    // carries the same PII the page already reveals). Throttled to bound
    // load on the cursor() query against large cohorts.
    Route::get('/retention/{cohort}/export',             [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'exportCsv'])
          ->where('cohort', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
          ->name('retention.cohort.export')
          ->middleware('throttle:30,1');
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

// ── OpsCenter — Operations Control Plane (Iteration 1) ───────────────────
//
// The unified operations dashboard. Aggregates EXISTING systems (Sentry,
// OperationalAlertService, JobHeartbeatService, spatie backups, webhook
// ledgers, the Coolify API, Laravel logs) — see docs/OPS_DISCOVERY_AUDIT.md.
//
// ACCESS (Iteration 5 + 6): the outer gate is 'ops_access' — super-admins
// pass exactly as before (MFA still enforced by the 'mfa' middleware),
// and users with an ACTIVE GRANT (ops_access_grants, managed on
// /ops/access) may enter the READ surfaces below. Two tiers:
//   viewer   — read-only (Iteration 5 behavior)
//   operator — read + run the read-only diagnostics (Iteration 6)
// The write surfaces stay super-admin-only in the nested 'super_admin'
// group; the diagnostics-run POST sits in its own nested 'ops_operator'
// group — the split is at the ROUTE level, so a viewer POSTing directly
// gets 403 regardless of what any template renders. Kill switches:
// OPS_VIEWER_ACCESS_ENABLED=false / OPS_OPERATOR_ACCESS_ENABLED=false
// revoke each tier instantly.
//
// IMPORTANT: this group must stay ABOVE the SEO fallback route — fallback
// only matches when nothing else does, but keeping ops routes contiguous
// with the other super-admin surfaces keeps the file readable.
Route::middleware(['auth', 'verified', 'ops_access', 'mfa'])
    ->prefix('ops')
    ->name('ops.')
    ->group(function () {
        // ── Read surfaces (super-admins + viewers) ───────────────────────
        Route::get('/',                     [\App\Ops\Http\Controllers\OpsDashboardController::class, 'overview'])->name('overview');
        Route::get('/applications',         [\App\Ops\Http\Controllers\OpsDashboardController::class, 'applications'])->name('applications');
        Route::get('/events',               [\App\Ops\Http\Controllers\OpsDashboardController::class, 'events'])->name('events');
        Route::get('/events/{event}',       [\App\Ops\Http\Controllers\OpsDashboardController::class, 'eventDetail'])
            ->whereNumber('event')
            ->name('events.show');

        // Incidents (Iteration 2): list + timeline detail are read-only and
        // viewer-visible; the lifecycle POSTs live in the super-admin group.
        Route::get('/incidents',                     [\App\Ops\Http\Controllers\OpsIncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/{incident}',          [\App\Ops\Http\Controllers\OpsIncidentController::class, 'show'])
            ->whereNumber('incident')
            ->name('incidents.show');

        // Morning digest (Iteration 7): the PREVIEW is read-only and
        // viewer-visible — it renders the exact message Slack receives.
        // The "send now" POST is super-admin-only (outbound message on
        // the operational channel), audited as ops.digest.sent.
        Route::get('/digest',                       [\App\Ops\Http\Controllers\OpsDigestController::class, 'index'])->name('digest.index');

        // Diagnostics (Iteration 3): the catalog and PAST run results are
        // read-only and viewer-visible; RUNNING a check is operator-only.
        Route::get('/diagnostics',                  [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'index'])->name('diagnostics.index');
        Route::get('/diagnostics/runs/{run}',       [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'show'])
            ->whereNumber('run')
            ->name('diagnostics.show');

        // Failed-jobs browser (Iteration 10): the full failed_jobs list —
        // what the queue.failed-jobs diagnostic summarizes, this page shows
        // job by job. Viewer-visible (reading failures is diagnosis, not
        // intervention); the Retry…/Forget… buttons are links into the
        // super-admin action framework with password + typed phrase.
        Route::get('/queue',                        [\App\Ops\Http\Controllers\OpsQueueController::class, 'index'])->name('queue.index');

        // ── Operator tier (super-admins + active operator grants) ────────
        //
        // Diagnostic runs (Iteration 3, opened to operators in Iteration 6)
        // — READ-ONLY checks, but they hit live subsystems and persist
        // audited rows: the exact right to delegate without blast radius.
        // The 'ops_operator' middleware (EnsureOpsOperator) passes
        // super-admins and active operator grants only — viewers 403 at
        // the route level. Throttled because some checks make live API
        // calls. Kill switch: OPS_OPERATOR_ACCESS_ENABLED=false.
        Route::middleware('ops_operator')->group(function () {
            Route::post('/diagnostics/run',             [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'run'])
                ->middleware('throttle:30,1')
                ->name('diagnostics.run');
        });

        // ── Operator surfaces (super-admin only) ─────────────────────────
        Route::middleware('super_admin')->group(function () {
            // Incident lifecycle (Iteration 2) — the module's first write
            // paths: super-admin + MFA + throttled + audited via
            // AdminAuditLog (ops.* actions). They alter only OpsCenter's
            // own records, never infrastructure — that bar
            // (password.confirm) is reserved for infrastructure actions.
            Route::post('/incidents/{incident}/acknowledge', [\App\Ops\Http\Controllers\OpsIncidentController::class, 'acknowledge'])
                ->whereNumber('incident')
                ->middleware('throttle:30,1')
                ->name('incidents.acknowledge');
            Route::post('/incidents/{incident}/resolve',     [\App\Ops\Http\Controllers\OpsIncidentController::class, 'resolve'])
                ->whereNumber('incident')
                ->middleware('throttle:30,1')
                ->name('incidents.resolve');
            Route::post('/incidents/{incident}/reopen',      [\App\Ops\Http\Controllers\OpsIncidentController::class, 'reopen'])
                ->whereNumber('incident')
                ->middleware('throttle:30,1')
                ->name('incidents.reopen');

            // Diagnostic runs moved UP into the 'ops_operator' group
            // (Iteration 6 — operators may run them; viewers may not).

            // Actions (Iteration 3) — the ONLY write paths against
            // infrastructure. Allow-listed (OpsActionRegistry), throttled
            // harder, and for elevated actions: inline password
            // verification + typed confirmation phrase enforced in
            // OpsActionController (the framework password.confirm
            // middleware is deliberately NOT used — its intended()
            // redirect replays POST routes as GET and 405s). Execution,
            // audit (ops.action.executed) and Slack announcement live in
            // OpsActionService. Fail-closed via OPS_ACTIONS_ENABLED=false.
            Route::get('/actions',                     [\App\Ops\Http\Controllers\OpsActionController::class, 'index'])->name('actions.index');
            Route::get('/actions/{action}/confirm',    [\App\Ops\Http\Controllers\OpsActionController::class, 'confirm'])->name('actions.confirm');
            Route::post('/actions/{action}',           [\App\Ops\Http\Controllers\OpsActionController::class, 'execute'])
                ->middleware('throttle:10,1')
                ->name('actions.execute');

            // Credentials (Iteration 5) — the §15 rotation checklist made
            // live: configured-presence booleans (never values) + the
            // rotation ledger. Governance surface → operator-only.
            Route::get('/credentials',                       [\App\Ops\Http\Controllers\OpsCredentialController::class, 'index'])->name('credentials.index');
            Route::post('/credentials/{key}/rotate',         [\App\Ops\Http\Controllers\OpsCredentialController::class, 'rotate'])
                ->middleware('throttle:10,1')
                ->name('credentials.rotate');

            // Access management (Iteration 5) — who may VIEW the control
            // plane. Super-admin only, trivially: a viewer who could grant
            // grants would not be a viewer.
            Route::get('/access',                            [\App\Ops\Http\Controllers\OpsAccessController::class, 'index'])->name('access.index');
            Route::post('/access/grant',                     [\App\Ops\Http\Controllers\OpsAccessController::class, 'grant'])
                ->middleware('throttle:10,1')
                ->name('access.grant');
            Route::post('/access/{grant}/revoke',            [\App\Ops\Http\Controllers\OpsAccessController::class, 'revoke'])
                ->whereNumber('grant')
                ->middleware('throttle:10,1')
                ->name('access.revoke');

            // Morning digest — "send now" (Iteration 7): fires the exact
            // message the preview shows, immediately, WITHOUT the daily
            // dedup (a test send that silently disappeared would look
            // exactly like a broken webhook). Throttled; audited.
            Route::post('/digest/send',                     [\App\Ops\Http\Controllers\OpsDigestController::class, 'sendNow'])
                ->middleware('throttle:5,1')
                ->name('digest.send');

            // Weekly review — "send now" (Iteration 8): same contract as
            // the daily digest's button — super-admin, throttled,
            // audited as ops.weekly_review.sent, never dedup-suppressed
            // (a vanished test send would look like a broken webhook).
            Route::post('/digest/weekly/send',              [\App\Ops\Http\Controllers\OpsDigestController::class, 'sendWeeklyNow'])
                ->middleware('throttle:5,1')
                ->name('digest.weekly.send');

            // Sentry project mapping (Iteration 8) — the operator-owned
            // Coolify-app ↔ Sentry-project mapping behind the per-app
            // trend column. A LABEL write (not a secret), but it drives
            // an outbound API surface → super-admin-only, throttled,
            // audited as ops.sentry.mapping.
            Route::post('/applications/{app}/sentry',       [\App\Ops\Http\Controllers\OpsDashboardController::class, 'updateSentryMapping'])
                ->whereNumber('app')
                ->middleware('throttle:10,1')
                ->name('applications.sentry');
        });
    });

// ── SEO OS (Iteration 5): SEO landing + editorial pages ──────────────────
// The FALLBACK route renders published seo_pages. Real product routes
// always win — this only runs when nothing else matches, and the controller
// checks a cached slug allow-list before rendering (else 404). Landing
// pages live at /{slug}; editorial content at /resources/{slug}.
Route::fallback(\App\Http\Controllers\SeoPageController::class);

// A-8 FIX (Iter-006): Observability endpoint, rate-limited to prevent abuse.
Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
    ->name('metrics')
    ->middleware('throttle:10,1');

// M-19: Feedback widget submission (authenticated users only)
Route::post('/feedback', [\App\Http\Controllers\FeedbackController::class, 'store'])->name('feedback.store')
      ->middleware(['auth', 'throttle:10,1']);

// M-18: NPS survey submission (authenticated users)
Route::post('/survey/nps', [\App\Http\Controllers\SurveyController::class, 'submitNps'])->name('survey.nps')
      ->middleware(['auth', 'throttle:5,1']);

// M-24: OAuth/SSO routes (Google + GitHub)
Route::get('/auth/{provider}/redirect',  [\App\Http\Controllers\OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/auth/{provider}/callback',  [\App\Http\Controllers\OAuthController::class, 'callback'])->name('oauth.callback');
Route::post('/auth/{provider}/unlink',   [\App\Http\Controllers\OAuthController::class, 'unlink'])->name('oauth.unlink')
      ->middleware(['auth']);

require __DIR__.'/auth.php';
// ── Testing Control Center (QA Iteration 2) ───────────────────────────────
// Status wall + run history + failure drill-down for the Exospace test suite.
// Gated by e-mail allowlist (CONTROL_CENTER_ADMINS); fail-closed 404 when the
// list is empty so the whole section vanishes for everyone else.
Route::middleware(['auth', 'cc_access'])->prefix('control-center')->name('control-center.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ControlCenter\DashboardController::class, 'overview'])->name('overview');
    Route::get('/runs', [\App\Http\Controllers\ControlCenter\DashboardController::class, 'runs'])->name('runs');
    Route::get('/flaky', [\App\Http\Controllers\ControlCenter\DashboardController::class, 'flaky'])->name('flaky');
    Route::get('/runs/{run}', [\App\Http\Controllers\ControlCenter\DashboardController::class, 'run'])
        ->name('run.show')
        ->whereNumber('run');
    Route::get('/runs/{run}/artifact', [\App\Http\Controllers\ControlCenter\DashboardController::class, 'artifact'])
        ->name('run.artifact')
        ->whereNumber('run');
    Route::post('/profiles/{profileKey}/start', [\App\Http\Controllers\ControlCenter\StartController::class, 'store'])
        ->name('profile.start');
});
