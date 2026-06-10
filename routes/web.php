<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\SuperAdmin\SystemController;
use App\Http\Controllers\TeamInvitationController;
use Illuminate\Support\Facades\Route;

// ── Installer ─────────────────────────────────────────────────────────────
Route::get('/finalize-installation', [InstallerController::class, 'finalize'])
    ->name('installer.finalize')->withoutMiddleware(['auth', 'verified']);

// ── Webhooks ──────────────────────────────────────────────────────────────
Route::post('/webhooks/2checkout',        [WebhookController::class, 'handle2Checkout'])->name('webhooks.2checkout');
Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])->name('webhooks.2checkout.refund');

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

// ── PIN-protected gallery entry ───────────────────────────────────────────
Route::get('/gallery/{slug}/pin',  [\App\Http\Controllers\GalleryPinController::class, 'show'])->name('gallery.pin');
Route::post('/gallery/{slug}/pin', [\App\Http\Controllers\GalleryPinController::class, 'verify'])->name('gallery.pin.verify');

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
    Route::resource('galleries', \App\Http\Controllers\Admin\GalleryController::class);
    Route::post('galleries/{gallery}/upload-audio',   [\App\Http\Controllers\Admin\GalleryController::class, 'uploadAudio'])->name('galleries.upload-audio');
    Route::post('galleries/{gallery}/upload-logo',    [\App\Http\Controllers\Admin\GalleryController::class, 'uploadLogo'])->name('galleries.upload-logo');
    Route::post('galleries/{gallery}/reorder-images', [\App\Http\Controllers\Admin\GalleryController::class, 'reorderImages'])->name('galleries.reorder-images');
    Route::get('galleries/{gallery}/analytics',       [\App\Http\Controllers\Admin\AnalyticsController::class, 'show'])->name('galleries.analytics');

    // ── Images ─────────────────────────────────────────────────────────────
    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])->name('images.store')->middleware('throttle:30,1');
    Route::delete('images/{image}',           [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])->name('images.destroy');
    Route::post('images/bulk-delete',         [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])->name('images.bulk_destroy');

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
});

// TEMPORARY DEBUG — remove after fixing
Route::get('/debug-gallery-create', function () {
    try {
        $user = \App\Models\User::first();
        if (!$user) return 'No users in DB';

        $results = [];

        // Step 1: Can we query venue templates?
        try {
            $venueTemplates = \App\Models\VenueTemplate::where('is_active', true)->orderBy('sort_order')->get();
            $results[] = 'VenueTemplates count: ' . $venueTemplates->count();
        } catch (\Exception $e) {
            $results[] = 'FAIL VenueTemplates: ' . $e->getMessage();
        }

        // Step 2: Does default_settings have required keys?
        try {
            foreach ($venueTemplates as $v) {
                $keys = ['wall_texture','floor_material','frame_style','lighting_preset','room_layout'];
                foreach ($keys as $k) {
                    if (!isset($v->default_settings[$k])) {
                        $results[] = "MISSING key '$k' on venue ID {$v->id} slug={$v->slug}";
                    }
                }
            }
            $results[] = 'default_settings keys OK';
        } catch (\Exception $e) {
            $results[] = 'FAIL default_settings: ' . $e->getMessage();
        }

        // Step 3: isAccessibleBy
        try {
            foreach ($venueTemplates as $v) {
                $v->isAccessibleBy($user);
            }
            $results[] = 'isAccessibleBy OK';
        } catch (\Exception $e) {
            $results[] = 'FAIL isAccessibleBy: ' . $e->getMessage();
        }

        // Step 4: firstWhere plan_required free
        try {
            $first = $venueTemplates->firstWhere('plan_required', 'free');
            $results[] = 'firstWhere free: ' . ($first ? $first->slug : 'NULL — no free template!');
        } catch (\Exception $e) {
            $results[] = 'FAIL firstWhere: ' . $e->getMessage();
        }

        // Step 5: galleries()->count on user
        try {
            $count = $user->galleries()->count();
            $results[] = 'galleries count: ' . $count;
        } catch (\Exception $e) {
            $results[] = 'FAIL galleries count: ' . $e->getMessage();
        }

        // Step 6: resolveEditableTeam simulation
        try {
            $teamId = $user->current_team_id;
            if ($teamId) {
                $team = \App\Models\Team::find($teamId);
                $canEdit = $team ? $team->canEdit($user) : 'no team found';
                $results[] = "current_team_id=$teamId canEdit=$canEdit";
            } else {
                $results[] = 'No current_team_id set';
            }
        } catch (\Exception $e) {
            $results[] = 'FAIL team check: ' . $e->getMessage();
        }

        // Step 7: checkGalleryLimit
        try {
            $canCreate = $user->canCreateGallery();
            $results[] = 'canCreateGallery: ' . ($canCreate ? 'yes' : 'no') . ' max=' . $user->max_galleries;
        } catch (\Exception $e) {
            $results[] = 'FAIL canCreateGallery: ' . $e->getMessage();
        }

        // Step 8: Try rendering a minimal version of what create view does
        try {
            $isPro = $user->isPro();
            $results[] = 'isPro: ' . ($isPro ? 'yes' : 'no') . ' plan=' . $user->plan;
        } catch (\Exception $e) {
            $results[] = 'FAIL isPro: ' . $e->getMessage();
        }

        return '<pre>' . implode("\n", $results) . '</pre>';

    } catch (\Exception $e) {
        return '<pre>TOP LEVEL ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . '</pre>';
    }
})->middleware(['auth', 'verified']);

require __DIR__.'/auth.php';