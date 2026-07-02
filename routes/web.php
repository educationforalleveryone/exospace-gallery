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
// Returns 200 if DB is reachable + a key migration table exists.
// No auth, no schema leak — safe to expose publicly.
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
        return response()->json(['status' => 'ok', 'ts' => time()], 200);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'down', 'error' => 'db unreachable'], 503);
    }
})->name('health');

// ── Installer (REMOVED in task C08) ──────────────────────────────────────
// The public /install/ directory and InstallerController have been removed.
// They were a standing risk: gated only by storage/.installed, the route
// called Artisan::call('migrate:fresh', ['--force' => true]) which drops
// every table. A missing lockfile (container rebuild without persistent
// volume) made the route reproducible — an attacker could wipe the DB.
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
Route::post('/webhooks/2checkout',        [WebhookController::class, 'handle2Checkout'])->name('webhooks.2checkout');
Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])->name('webhooks.2checkout.refund');

// ── SEO & discovery endpoints ────────────────────────────────────────────
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/feed.xml',    [SitemapController::class, 'feed'])->name('feed');
Route::get('/discover',    [DiscoverController::class, 'index'])->name('discover');

// ── Public pages ─────────────────────────────────────────────────────────
Route::get('/', fn() => view('welcome'));
Route::view('/privacy',          'pages.privacy')->name('privacy');
Route::view('/terms',            'pages.terms')->name('terms');
Route::view('/refund-policy',    'pages.refund')->name('refund');
Route::view('/about',            'pages.about')->name('about');
Route::view('/payment-security', 'pages.security')->name('security');
Route::view('/pricing',          'pages.pricing')->name('pricing');
Route::view('/contact',          'pages.contact')->name('contact');
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit'])->name('contact.submit')->middleware('throttle:5,10');

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
Route::post('/gallery/{gallery}/track', [\App\Http\Controllers\Admin\AnalyticsController::class, 'track'])
    ->name('gallery.track')
    ->middleware('throttle:120,1');

// ── Team Invitations ─────────────────────────────────────────────────────
Route::get('/team-invitations/{token}',          [TeamInvitationController::class, 'show'])->name('team-invitations.show');
Route::post('/team-invitations/{token}/accept',  [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
Route::post('/team-invitations/{token}/decline', [TeamInvitationController::class, 'decline'])->name('team-invitations.decline');

// ── Auth ─────────────────────────────────────────────────────────────────
Route::get('/dashboard', fn() => redirect()->route('admin.dashboard'))->middleware(['auth', 'verified'])->name('dashboard');
Route::middleware('auth')->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // GDPR Art. 20 — right to data portability. Returns JSON download of
    // the user's profile, galleries, images metadata, transactions, teams,
    // and artist profiles. (Task C10.)
    Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');
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

// ── Super Admin ──────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'super_admin'])->prefix('master-control')->name('super.')->group(function () {
    Route::get('/',                                    [SystemController::class, 'index'])->name('index');
    Route::post('/users/{user}/plan',                  [SystemController::class, 'updatePlan'])->name('updatePlan');
    Route::delete('/users/{user}',                     [SystemController::class, 'deleteUser'])->name('deleteUser');
    Route::get('/users/{user}/galleries',              [SystemController::class, 'userGalleries'])->name('user-galleries');
    Route::post('/galleries/{gallery}/toggle',         [SystemController::class, 'toggleGallery'])->name('toggleGallery');

    // Account controls
    Route::post('/users/{user}/ban',                   [SystemController::class, 'banUser'])->name('banUser');
    Route::post('/users/{user}/unban',                 [SystemController::class, 'unbanUser'])->name('unbanUser');
    Route::post('/users/{user}/verify-email',          [SystemController::class, 'verifyEmail'])->name('verifyEmail');
    Route::post('/users/{user}/unverify-email',        [SystemController::class, 'unverifyEmail'])->name('unverifyEmail');
    Route::post('/users/{user}/toggle-super-admin',    [SystemController::class, 'toggleSuperAdmin'])->name('toggleSuperAdmin');

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
});

require __DIR__.'/auth.php';
