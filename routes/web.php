<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\SuperAdmin\SystemController;
use App\Http\Controllers\SuperAdmin\VenueTemplateController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// ── Bare DB check — no middleware, no models, no views ───────────────────
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
});

// ── Installer ─────────────────────────────────────────────────────────────
Route::get('/finalize-installation', [InstallerController::class, 'finalize'])
    ->name('installer.finalize')->withoutMiddleware(['auth', 'verified']);

// ── Webhooks ──────────────────────────────────────────────────────────────
Route::post('/webhooks/2checkout',        [WebhookController::class, 'handle2Checkout'])->name('webhooks.2checkout');
Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])->name('webhooks.2checkout.refund');

// ── SEO & discovery endpoints ─────────────────────────────────────────────
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

// ── Demo redirect ─────────────────────────────────────────────────────────
Route::get('/gallery/demo', function () {
    $gallery = \App\Models\Gallery::where('is_active', true)->first();
    return $gallery ? redirect()->route('gallery.view', $gallery->slug) : redirect('/')->with('error', 'No demo gallery available yet.');
});

// ── Per-gallery: PIN entry, OG image, QR code ─────────────────────────────
Route::get('/gallery/{slug}/pin',       [\App\Http\Controllers\GalleryPinController::class, 'show'])->name('gallery.pin');
Route::post('/gallery/{slug}/pin',      [\App\Http\Controllers\GalleryPinController::class, 'verify'])->name('gallery.pin.verify');
Route::get('/gallery/{slug}/og-image',  [OgImageController::class, 'show'])->name('gallery.og-image');
Route::get('/gallery/{slug}/qr',        [QrCodeController::class, 'show'])->name('gallery.qr');

// ── Public gallery view ───────────────────────────────────────────────────
Route::get('/gallery/{slug}', [\App\Http\Controllers\GalleryViewController::class, 'show'])->name('gallery.view');

// ── Analytics tracking (public, no auth) ─────────────────────────────────
Route::post('/gallery/{gallery}/track', [\App\Http\Controllers\Admin\AnalyticsController::class, 'track'])
    ->name('gallery.track')
    ->middleware('throttle:120,1');

// ── Team Invitations (public — token-based, no auth required to VIEW) ─────
Route::get('/team-invitations/{token}',          [TeamInvitationController::class, 'show'])->name('team-invitations.show');
Route::post('/team-invitations/{token}/accept',  [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
Route::post('/team-invitations/{token}/decline', [TeamInvitationController::class, 'decline'])->name('team-invitations.decline');

// ── Auth ──────────────────────────────────────────────────────────────────
Route::get('/dashboard', fn() => redirect()->route('admin.dashboard'))->middleware(['auth', 'verified'])->name('dashboard');
Route::middleware('auth')->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Admin ─────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Galleries ──────────────────────────────────────────────────────────
    // NOTE: the `duplicate` route MUST come BEFORE `galleries/{gallery}` so
    // Laravel doesn't match the literal string "duplicate" as a wildcard.
    Route::get('galleries',                [\App\Http\Controllers\Admin\GalleryController::class, 'index'])->name('galleries.index');
    Route::get('galleries/create',         [\App\Http\Controllers\Admin\GalleryController::class, 'create'])->name('galleries.create');
    Route::post('galleries',               [\App\Http\Controllers\Admin\GalleryController::class, 'store'])->name('galleries.store');
    Route::get('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'show'])->name('galleries.show');
    Route::get('galleries/{gallery}/edit', [\App\Http\Controllers\Admin\GalleryController::class, 'edit'])->name('galleries.edit');
    Route::put('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'update'])->name('galleries.update');
    Route::delete('galleries/{gallery}',   [\App\Http\Controllers\Admin\GalleryController::class, 'destroy'])->name('galleries.destroy');

    // NEW: gallery duplication (clone)
    Route::post('galleries/{gallery}/duplicate', [\App\Http\Controllers\Admin\GalleryController::class, 'duplicate'])->name('galleries.duplicate');

    Route::post('galleries/{gallery}/upload-audio',   [\App\Http\Controllers\Admin\GalleryController::class, 'uploadAudio'])->name('galleries.upload-audio');
    Route::post('galleries/{gallery}/upload-logo',    [\App\Http\Controllers\Admin\GalleryController::class, 'uploadLogo'])->name('galleries.upload-logo');
    Route::post('galleries/{gallery}/reorder-images', [\App\Http\Controllers\Admin\GalleryController::class, 'reorderImages'])->name('galleries.reorder-images');
    Route::get('galleries/{gallery}/analytics',       [\App\Http\Controllers\Admin\AnalyticsController::class, 'show'])->name('galleries.analytics');

    // ── Images ─────────────────────────────────────────────────────────────
    // FIX: 'images/bulk-delete' MUST be registered before 'images/{image}' — otherwise
    // Laravel's router matches the literal string "bulk-delete" as the {image} wildcard
    // and the bulk-delete endpoint returns 404 (model not found) instead of routing correctly.
    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])->name('images.store')->middleware('throttle:30,1');
    Route::post('images/bulk-delete',         [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])->name('images.bulk_destroy');
    Route::delete('images/{image}',           [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])->name('images.destroy');

    // ── Teams ──────────────────────────────────────────────────────────────
    Route::get   ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'index'])->name('teams.index');
    Route::get   ('teams/create',                      [\App\Http\Controllers\Admin\TeamController::class, 'create'])->name('teams.create');
    Route::post  ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'store'])->name('teams.store');
    Route::post  ('teams/switch-personal',             function () {
        Auth::user()->forceFill(['current_team_id' => null])->save();
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

// ── Super Admin ───────────────────────────────────────────────────────────
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
});

require __DIR__.'/auth.php';
