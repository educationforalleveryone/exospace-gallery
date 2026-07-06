<?php

use App\Http\Controllers\Api\GalleryApiController;
use App\Http\Controllers\Api\ArtistApiController;
use App\Http\Controllers\Api\ApiTokenController;
use Illuminate\Support\Facades\Route;

/**
 * M-28: Public REST API for Exospace.
 *
 * API endpoints are prefixed with /api/v1 and use JSON responses.
 * Public read endpoints (galleries, artists) are rate-limited at
 * 60 req/min/IP — no auth required.
 *
 * Authenticated endpoints (API token management) use Sanctum token auth.
 * Users generate tokens from their profile page (ProfileController).
 *
 * All responses use the standard JSON:API-inspired format:
 *   { "data": [...], "meta": { "pagination": {...} } }
 */

Route::prefix('v1')->group(function () {

    // ── Public read endpoints (no auth, rate-limited) ──────────────────
    Route::middleware(['throttle:60,1'])->group(function () {

        // Galleries
        Route::get('galleries',             [GalleryApiController::class, 'index']);
        Route::get('galleries/{slug}',      [GalleryApiController::class, 'show']);
        Route::get('galleries/{slug}/images', [GalleryApiController::class, 'images']);

        // Artists
        Route::get('artists',               [ArtistApiController::class, 'index']);
        Route::get('artists/{slug}',        [ArtistApiController::class, 'show']);
        Route::get('artists/{slug}/galleries', [ArtistApiController::class, 'galleries']);
    });

    // ── Authenticated endpoints (Sanctum token auth) ───────────────────
    Route::middleware(['auth:sanctum'])->group(function () {

        // API token management (generate/revoke tokens)
        Route::post('tokens',               [ApiTokenController::class, 'store']);
        Route::get('tokens',                [ApiTokenController::class, 'index']);
        Route::delete('tokens/{tokenId}',   [ApiTokenController::class, 'destroy']);

        // Authenticated user's own data
        Route::get('me',                    [ApiTokenController::class, 'me']);
        Route::get('me/galleries',          [GalleryApiController::class, 'myGalleries']);
    });
});
