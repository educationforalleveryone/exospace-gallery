<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// ============================================
// INSTALLER ROUTES (Must be FIRST, before any middleware)
// ============================================
Route::get('/finalize-installation', [InstallerController::class, 'finalize'])
    ->name('installer.finalize')
    ->withoutMiddleware(['auth', 'verified']);

// ============================================
// WEBHOOK ROUTES (Must be public - 2Checkout needs access)
// ============================================
Route::post('/webhooks/2checkout', [WebhookController::class, 'handle2Checkout'])
    ->name('webhooks.2checkout');

Route::post('/webhooks/2checkout/refund', [WebhookController::class, 'handleRefund'])
    ->name('webhooks.2checkout.refund');

// ============================================
// PUBLIC ROUTES
// ============================================
Route::get('/', function () {
    return view('welcome');
});

// Legal Pages (Required for 2Checkout)
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/refund-policy', 'pages.refund')->name('refund');
Route::view('/about', 'pages.about')->name('about');
Route::view('/payment-security', 'pages.security')->name('security');

// ============================================
// COMMERCIAL & SUPPORT ROUTES (Add these!)
// ============================================
Route::view('/pricing', 'pages.pricing')->name('pricing');
Route::view('/contact', 'pages.contact')->name('contact');

// Handle Contact Form Submission (Mock success for the UI)
Route::post('/contact', function () {
    return response()->json(['message' => 'Message received']);
});

// Smart Demo Redirect: Finds the first active gallery and redirects to it
Route::get('/gallery/demo', function () {
    $gallery = \App\Models\Gallery::where('is_active', true)->first();
    
    if (!$gallery) {
        return redirect('/')->with('error', 'No demo gallery available yet.');
    }

    return redirect()->route('gallery.view', $gallery->slug);
});

// Public Gallery Route (No authentication required)
Route::get('/gallery/{slug}', [App\Http\Controllers\GalleryViewController::class, 'show'])
    ->name('gallery.view');

// ============================================
// AUTHENTICATED ROUTES
// ============================================
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ============================================
// ADMIN ROUTES
// ============================================
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Gallery Resource Routes
    Route::resource('galleries', \App\Http\Controllers\Admin\GalleryController::class);
    
    // Image Routes
    Route::post('galleries/{gallery}/images', [\App\Http\Controllers\Admin\ImageController::class, 'store'])
        ->name('images.store');
    Route::delete('images/{image}', [\App\Http\Controllers\Admin\ImageController::class, 'destroy'])
        ->name('images.destroy');
    Route::post('images/bulk-delete', [\App\Http\Controllers\Admin\ImageController::class, 'bulkDestroy'])
        ->name('images.bulk_destroy');
});

// ============================================
// AUTHENTICATION ROUTES
// ============================================
require __DIR__.'/auth.php';