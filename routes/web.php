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
Route::get('/debug-render-test', function () {
    $output = [];

    // Step 0: Clear compiled view cache so we test fresh files
    try {
        $viewCachePath = storage_path('framework/views');
        $files = glob($viewCachePath . '/*.php');
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file)) { unlink($file); $deleted++; }
        }
        $output[] = "Cache cleared: $deleted compiled view files deleted";
    } catch (\Throwable $e) {
        $output[] = 'Cache clear failed: ' . $e->getMessage();
    }

    // Step 1: Show actual file contents around the fixed lines
    $createPath = resource_path('views/admin/galleries/create.blade.php');
    $editPath   = resource_path('views/admin/galleries/edit.blade.php');
    $output[] = '';
    $output[] = '=== CREATE file mtime: ' . date('Y-m-d H:i:s', filemtime($createPath)) . ' ===';
    $createLines = file($createPath);
    foreach (range(130, 137) as $n) {
        if (isset($createLines[$n])) $output[] = "  L" . ($n+1) . ": " . rtrim($createLines[$n]);
    }
    $output[] = '';
    $output[] = '=== EDIT file mtime: ' . date('Y-m-d H:i:s', filemtime($editPath)) . ' ===';
    $editLines = file($editPath);
    foreach (range(490, 496) as $n) {
        if (isset($editLines[$n])) $output[] = "  L" . ($n+1) . ": " . rtrim($editLines[$n]);
    }

    $user = \App\Models\User::first();
    if (!$user) { $output[] = 'No users in DB'; goto done; }

    $venueTemplates = \App\Models\VenueTemplate::where('is_active', true)->orderBy('sort_order')->get();

    // Test CREATE
    $output[] = '';
    $output[] = '=== Testing CREATE view render (fresh) ===';
    try {
        $html = view('admin.galleries.create', ['team' => null, 'venueTemplates' => $venueTemplates])->render();
        $output[] = 'CREATE: OK (' . strlen($html) . ' bytes)';
    } catch (\Throwable $e) {
        $output[] = 'CREATE FAILED: ' . $e->getMessage();
        $output[] = 'At: ' . $e->getFile() . ':' . $e->getLine();
        $output[] = $e->getTraceAsString();
        goto done;
    }

    // Test EDIT
    $gallery = \App\Models\Gallery::with(['images', 'venueTemplate'])->first();
    $output[] = '';
    $output[] = '=== Testing EDIT view render (fresh) ===';
    if (!$gallery) {
        $output[] = 'No galleries in DB — skipping';
    } else {
        try {
            $html = view('admin.galleries.edit', ['gallery' => $gallery, 'venueTemplates' => $venueTemplates])->render();
            $output[] = 'EDIT: OK (' . strlen($html) . ' bytes)';
        } catch (\Throwable $e) {
            $output[] = 'EDIT FAILED: ' . $e->getMessage();
            $output[] = 'At: ' . $e->getFile() . ':' . $e->getLine();
            $output[] = $e->getTraceAsString();
        }
    }

    done:
    return '<pre style="font-size:11px;background:#0d1117;color:#c9d1d9;padding:20px;line-height:1.5">' . implode("\n", $output) . '</pre>';
})->middleware(['auth', 'verified']);

require __DIR__.'/auth.php';