<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\SuperAdmin\SystemController;
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
Route::post('/contact', fn() => response()->json(['message' => 'Message received']));

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
    ->middleware('throttle:120,1'); // 120 events per minute per IP

// ── Auth ──────────────────────────────────────────────────────────────────
Route::get('/dashboard', fn() => view('dashboard'))->middleware(['auth', 'verified'])->name('dashboard');
Route::middleware('auth')->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Admin ─────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('galleries', \App\Http\Controllers\Admin\GalleryController::class);

    Route::post('galleries/{gallery}/upload-audio',  [\App\Http\Controllers\Admin\GalleryController::class, 'uploadAudio'])->name('galleries.upload-audio');
    Route::post('galleries/{gallery}/upload-logo',   [\App\Http\Controllers\Admin\GalleryController::class, 'uploadLogo'])->name('galleries.upload-logo');
    Route::post('galleries/{gallery}/reorder-images',[\App\Http\Controllers\Admin\GalleryController::class, 'reorderImages'])->name('galleries.reorder-images');

    // Analytics dashboard
    Route::get('galleries/{gallery}/analytics', [\App\Http\Controllers\Admin\AnalyticsController::class, 'show'])->name('galleries.analytics');

    // Images
    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])->name('images.store');
    Route::delete('images/{image}',           [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])->name('images.destroy');
    Route::post('images/bulk-delete',         [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])->name('images.bulk_destroy');
});

// ── Super Admin ───────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'super_admin'])->prefix('master-control')->group(function () {
    Route::get('/',                           [SystemController::class, 'index'])->name('super.index');
    Route::post('/users/{user}/plan',         [SystemController::class, 'updatePlan'])->name('super.updatePlan');
    Route::delete('/users/{user}',            [SystemController::class, 'deleteUser'])->name('super.deleteUser');
    Route::get('/users/{user}/galleries',     [SystemController::class, 'userGalleries'])->name('super.user-galleries');
    Route::post('/galleries/{gallery}/toggle',[SystemController::class, 'toggleGallery'])->name('super.toggleGallery');
});

require __DIR__.'/auth.php';