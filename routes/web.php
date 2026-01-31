<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\Route;

// ============================================
// INSTALLER ROUTES (Must be FIRST, before any middleware)
// ============================================
Route::get('/finalize-installation', [InstallerController::class, 'finalize'])
    ->name('installer.finalize')
    ->withoutMiddleware(['auth', 'verified']);

// ============================================
// PUBLIC ROUTES
// ============================================
Route::get('/', function () {
    return view('welcome');
});

// Legal Pages (Required for 2Checkout)
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');

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