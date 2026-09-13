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

Route::get('/health', [\App\Http\Controllers\HealthController::class, 'check'])->name('health');

Route::get ('webhooks/2checkout',         fn() => response('OK', 200));
Route::post('/webhooks/2checkout',        [WebhookController::class, 'handle2Checkout'])->name('webhooks.2checkout')->middleware('throttle:60,1');
Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])->name('webhooks.2checkout.refund')->middleware('throttle:60,1');

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

Route::get('/venues/{slug}/preview', [\App\Http\Controllers\VenuePreviewController::class, 'show'])
    ->name('venues.preview')
    ->middleware('throttle:20,1', 'feature_flag:venue_previews');

Route::get('/venues/{slug}', [\App\Http\Controllers\PublicVenueController::class, 'show'])->name('venues.show');

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

Route::get('/unsubscribe/{user}',      [\App\Http\Controllers\UnsubscribeController::class, 'show'])->name('unsubscribe.show')->middleware('signed');
Route::post('/unsubscribe/{user}',     [\App\Http\Controllers\UnsubscribeController::class, 'confirm'])->name('unsubscribe.confirm')->middleware('signed');
Route::get('/unsubscribe-done',        [\App\Http\Controllers\UnsubscribeController::class, 'done'])->name('unsubscribe.done');

Route::get('/unsubscribe/one-click/{user}',  [\App\Http\Controllers\UnsubscribeController::class, 'oneClickShow'])->name('unsubscribe.one-click')->middleware('signed');
Route::post('/unsubscribe/one-click/{user}', [\App\Http\Controllers\UnsubscribeController::class, 'oneClickPost'])->name('unsubscribe.one-click.post')->middleware('signed');

Route::get('/gallery/demo', function () {
    $gallery = \App\Models\Gallery::publiclyViewable()
        ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
        ->has('images', '>=', 1)
        ->first();
    return $gallery ? redirect()->route('gallery.view', $gallery->slug) : redirect('/')->with('error', 'No demo gallery available yet.');
});

Route::get('/artist/{slug}', [ArtistProfileController::class, 'show'])->name('artist.profile');

// SEO OS (Iteration 2): artist OG image + artwork landing pages.
Route::get('/artist/{slug}/og-image', [OgImageController::class, 'artist'])->name('artist.og-image');
Route::get('/gallery/{slug}/artwork/{image}', [\App\Http\Controllers\ArtworkController::class, 'show'])
    ->name('artwork.show')
    ->middleware('throttle:60,1');

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

Route::get('/gallery/{slug}', [\App\Http\Controllers\GalleryViewController::class, 'show'])
    ->name('gallery.view')
    ->middleware('throttle:60,1');

Route::post('/gallery/{gallery}/track', [\App\Http\Controllers\Admin\AnalyticsController::class, 'track'])
    ->name('gallery.track')
    ->middleware('throttle:30,1');

Route::get('/team-invitations/{token}',          [TeamInvitationController::class, 'show'])->name('team-invitations.show')->middleware('signed');
Route::post('/team-invitations/{token}/accept',  [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
Route::post('/team-invitations/{token}/decline', [TeamInvitationController::class, 'decline'])->name('team-invitations.decline');

Route::get('/dashboard', fn() => redirect()->route('admin.dashboard'))->middleware(['auth', 'verified'])->name('dashboard');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])
        ->middleware('throttle:6,1,profile-update')
        ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->middleware('throttle:6,1,profile-destroy')
        ->name('profile.destroy');
    Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');

    Route::get('/mfa/setup', [\App\Http\Controllers\MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/setup', [\App\Http\Controllers\MfaController::class, 'enable'])->middleware('throttle:6,1,mfa-setup');
    Route::get('/mfa/verify', [\App\Http\Controllers\MfaController::class, 'showVerify'])->name('mfa.verify');
    Route::post('/mfa/verify', [\App\Http\Controllers\MfaController::class, 'verify'])->middleware('throttle:6,1,mfa-verify');
    // P3-7: One-time backup codes display after MFA enable
    Route::get('/mfa/backup-codes', [\App\Http\Controllers\MfaController::class, 'showBackupCodes'])->name('mfa.backup-codes');
    Route::post('/mfa/disable', [\App\Http\Controllers\MfaController::class, 'disable'])
        ->middleware('throttle:6,1,mfa-disable')
        ->name('mfa.disable');

    Route::middleware(['mfa'])->group(function () {
        Route::get('/billing',                [\App\Http\Controllers\BillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/upgrade/{plan}', [\App\Http\Controllers\BillingController::class, 'upgrade'])->name('billing.upgrade')
              ->where('plan', 'pro|studio')
              ->middleware('throttle:10,1');

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

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('galleries',                [\App\Http\Controllers\Admin\GalleryController::class, 'index'])->name('galleries.index');
    Route::get('galleries/create',         [\App\Http\Controllers\Admin\GalleryController::class, 'create'])->name('galleries.create');
    Route::post('galleries',               [\App\Http\Controllers\Admin\GalleryController::class, 'store'])->name('galleries.store');
    Route::get('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'show'])->name('galleries.show');
    Route::get('galleries/{gallery}/edit', [\App\Http\Controllers\Admin\GalleryController::class, 'edit'])->name('galleries.edit');

    Route::get('galleries/{gallery}/preview', [\App\Http\Controllers\Admin\GalleryController::class, 'preview'])->name('galleries.preview');

    Route::put('galleries/{gallery}',      [\App\Http\Controllers\Admin\GalleryController::class, 'update'])->name('galleries.update');
    Route::delete('galleries/{gallery}',   [\App\Http\Controllers\Admin\GalleryController::class, 'destroy'])->name('galleries.destroy');

    // NEW (Round 2): gallery duplication
    Route::post('galleries/{gallery}/duplicate', [\App\Http\Controllers\Admin\GalleryController::class, 'duplicate'])->name('galleries.duplicate');

    Route::post('galleries/{gallery}/publish',   [\App\Http\Controllers\Admin\GalleryController::class, 'publish'])->name('galleries.publish');
    Route::post('galleries/{gallery}/unpublish', [\App\Http\Controllers\Admin\GalleryController::class, 'unpublish'])->name('galleries.unpublish');

    Route::post('galleries/{gallery}/upload-audio',   [\App\Http\Controllers\Admin\GalleryController::class, 'uploadAudio'])->name('galleries.upload-audio');
    Route::post('galleries/{gallery}/upload-logo',    [\App\Http\Controllers\Admin\GalleryController::class, 'uploadLogo'])->name('galleries.upload-logo');
    Route::post('galleries/{gallery}/reorder-images', [\App\Http\Controllers\Admin\GalleryController::class, 'reorderImages'])->name('galleries.reorder-images');
    Route::get('galleries/{gallery}/analytics',       [\App\Http\Controllers\Admin\AnalyticsController::class, 'show'])->name('galleries.analytics');

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

    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])->name('images.store')->middleware('throttle:30,1');
    Route::post('images/bulk-delete',         [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])->name('images.bulk_destroy');
    Route::delete('images/{image}',           [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])->name('images.destroy');

    Route::get('artists',                   [\App\Http\Controllers\Admin\ArtistController::class, 'index'])->name('artists.index');
    Route::get('artists/create',            [\App\Http\Controllers\Admin\ArtistController::class, 'create'])->name('artists.create');
    Route::post('artists',                  [\App\Http\Controllers\Admin\ArtistController::class, 'store'])->name('artists.store');
    Route::get('artists/{artist}',          [\App\Http\Controllers\Admin\ArtistController::class, 'show'])->name('artists.show');
    Route::get('artists/{artist}/edit',     [\App\Http\Controllers\Admin\ArtistController::class, 'edit'])->name('artists.edit');
    Route::put('artists/{artist}',          [\App\Http\Controllers\Admin\ArtistController::class, 'update'])->name('artists.update');
    Route::delete('artists/{artist}',       [\App\Http\Controllers\Admin\ArtistController::class, 'destroy'])->name('artists.destroy');
    Route::get('artists-search',            [\App\Http\Controllers\Admin\ArtistController::class, 'search'])->name('artists.search');

    Route::get   ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'index'])->name('teams.index');
    Route::get   ('teams/create',                      [\App\Http\Controllers\Admin\TeamController::class, 'create'])->name('teams.create');
    Route::post  ('teams',                             [\App\Http\Controllers\Admin\TeamController::class, 'store'])->name('teams.store');
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

    Route::delete('venues/{venue}',                    [VenueTemplateController::class, 'destroy'])->name('venues.destroy');

    Route::get   ('featured',                          [FeaturedExhibitionsController::class, 'index'])->name('featured.index');
    Route::patch ('featured/{gallery}',                [FeaturedExhibitionsController::class, 'toggle'])->name('featured.toggle');

    Route::get   ('seo',                               [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'index'])->name('seo.index');
    Route::post  ('seo/profile/{type}/{id}',           [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'updateProfile'])->name('seo.profile.update');
    Route::post  ('seo/redirects',                     [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'storeRedirect'])->name('seo.redirects.store');
    Route::delete('seo/redirects/{redirect}',          [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'destroyRedirect'])->name('seo.redirects.destroy');
    Route::post  ('seo/pages/{page}/toggle',           [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'togglePage'])->name('seo.pages.toggle');
    Route::post  ('seo/rebuild',                       [\App\Http\Controllers\SuperAdmin\SeoAdminController::class, 'rebuild'])->name('seo.rebuild');

    Route::get   ('pending-upgrades',                  [SystemController::class, 'pendingUpgrades'])->name('pending-upgrades.index');
    Route::post  ('pending-upgrades/{pending}/manual-upgrade', [SystemController::class, 'manualUpgrade'])->name('pending-upgrades.manual-upgrade')
          ->middleware('password.confirm');

    Route::get   ('billing',                           [\App\Http\Controllers\SuperAdmin\BillingController::class, 'index'])->name('billing.index');
    Route::get   ('billing/export',                    [\App\Http\Controllers\SuperAdmin\BillingController::class, 'export'])->name('billing.export');
    Route::post  ('billing/webhooks/{webhook}/replay', [\App\Http\Controllers\SuperAdmin\BillingController::class, 'replayWebhook'])
          ->whereNumber('webhook')
          ->name('billing.replay')
          ->middleware('password.confirm');

    Route::post  ('billing/recipients',                [\App\Http\Controllers\SuperAdmin\BillingController::class, 'storeRecipient'])->name('billing.recipients.store')
          ->middleware('throttle:30,1'); // ITERATION 8: throttle (audit-fix E-1)
    Route::delete('billing/recipients/{recipient}',    [\App\Http\Controllers\SuperAdmin\BillingController::class, 'destroyRecipient'])
          ->whereNumber('recipient')
          ->name('billing.recipients.destroy')
          ->middleware('throttle:30,1'); // ITERATION 8: throttle (audit-fix E-1)

    Route::get   ('webhooks',                           [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
    Route::post  ('webhooks',                           [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'store'])->name('webhooks.store')
          ->middleware('throttle:30,1');
    Route::patch ('webhooks/{subscription}/toggle',    [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'toggle'])->name('webhooks.toggle')
          ->whereNumber('subscription')
          ->middleware('throttle:30,1');
    Route::delete('webhooks/{subscription}',            [\App\Http\Controllers\SuperAdmin\WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy')
          ->whereNumber('subscription')
          ->middleware('throttle:30,1');

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

    Route::get('/retention/{cohort}',                    [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'cohort'])
          ->where('cohort', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
          ->name('retention.cohort')
          ->middleware('throttle:60,1'); // ITERATION 8: throttle (audit-fix E-1)

    Route::get('/retention/{cohort}/export',             [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'exportCsv'])
          ->where('cohort', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
          ->name('retention.cohort.export')
          ->middleware('throttle:30,1');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/master-control/stop-impersonating',  [\App\Http\Controllers\SuperAdmin\SystemController::class, 'stopImpersonating'])->name('super.stop-impersonating');

    // M-12: In-app notifications
    Route::post('/notifications/{notification}/read',     [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/mark-all-read',            [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
});

// M-20: Public status page (no auth required)
Route::get('/status', [\App\Http\Controllers\StatusController::class, 'show'])->name('status');

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

        Route::get('/incidents',                     [\App\Ops\Http\Controllers\OpsIncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/{incident}',          [\App\Ops\Http\Controllers\OpsIncidentController::class, 'show'])
            ->whereNumber('incident')
            ->name('incidents.show');

        Route::get('/digest',                       [\App\Ops\Http\Controllers\OpsDigestController::class, 'index'])->name('digest.index');

        Route::get('/diagnostics',                  [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'index'])->name('diagnostics.index');
        Route::get('/diagnostics/runs/{run}',       [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'show'])
            ->whereNumber('run')
            ->name('diagnostics.show');

        Route::get('/queue',                        [\App\Ops\Http\Controllers\OpsQueueController::class, 'index'])->name('queue.index');

        Route::middleware('ops_operator')->group(function () {
            Route::post('/diagnostics/run',             [\App\Ops\Http\Controllers\OpsDiagnosticController::class, 'run'])
                ->middleware('throttle:30,1')
                ->name('diagnostics.run');
        });

        // ── Operator surfaces (super-admin only) ─────────────────────────
        Route::middleware('super_admin')->group(function () {
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

            Route::get('/actions',                     [\App\Ops\Http\Controllers\OpsActionController::class, 'index'])->name('actions.index');
            Route::get('/actions/{action}/confirm',    [\App\Ops\Http\Controllers\OpsActionController::class, 'confirm'])->name('actions.confirm');
            Route::post('/actions/{action}',           [\App\Ops\Http\Controllers\OpsActionController::class, 'execute'])
                ->middleware('throttle:10,1')
                ->name('actions.execute');

            Route::get('/credentials',                       [\App\Ops\Http\Controllers\OpsCredentialController::class, 'index'])->name('credentials.index');
            Route::post('/credentials/{key}/rotate',         [\App\Ops\Http\Controllers\OpsCredentialController::class, 'rotate'])
                ->middleware('throttle:10,1')
                ->name('credentials.rotate');

            Route::get('/access',                            [\App\Ops\Http\Controllers\OpsAccessController::class, 'index'])->name('access.index');
            Route::post('/access/grant',                     [\App\Ops\Http\Controllers\OpsAccessController::class, 'grant'])
                ->middleware('throttle:10,1')
                ->name('access.grant');
            Route::post('/access/{grant}/revoke',            [\App\Ops\Http\Controllers\OpsAccessController::class, 'revoke'])
                ->whereNumber('grant')
                ->middleware('throttle:10,1')
                ->name('access.revoke');

            Route::post('/digest/send',                     [\App\Ops\Http\Controllers\OpsDigestController::class, 'sendNow'])
                ->middleware('throttle:5,1')
                ->name('digest.send');

            Route::post('/digest/weekly/send',              [\App\Ops\Http\Controllers\OpsDigestController::class, 'sendWeeklyNow'])
                ->middleware('throttle:5,1')
                ->name('digest.weekly.send');

            Route::post('/applications/{app}/sentry',       [\App\Ops\Http\Controllers\OpsDashboardController::class, 'updateSentryMapping'])
                ->whereNumber('app')
                ->middleware('throttle:10,1')
                ->name('applications.sentry');
        });
    });

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
