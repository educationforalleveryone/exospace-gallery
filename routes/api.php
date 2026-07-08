<?php

use App\Http\Controllers\Api\GalleryApiController;
use App\Http\Controllers\Api\ArtistApiController;
use App\Http\Controllers\Api\ApiTokenController;
use Illuminate\Support\Facades\Route;

/**
 * M-28: Public REST API for Exospace.
 *
 * ITERATION-005 FIX (audit D-5): Sanctum token abilities are now ENFORCED.
 *
 * Previously, tokens were created with 'read' or 'write' abilities, but no
 * route checked them. A read-only token could POST /api/v1/tokens to mint
 * new tokens with write ability → privilege escalation via API.
 *
 * FIX: Read endpoints use `ability:read`, write endpoints use `ability:write`.
 * Sanctum's `ability` middleware checks $token->can($ability) and returns
 * 403 if the token doesn't have the required ability.
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

    // ── Authenticated read endpoints (Sanctum + ability:read) ──────────
    // D-5 FIX: Read-only tokens can access these endpoints.
    Route::middleware(['auth:sanctum', 'ability:read'])->group(function () {

        // Authenticated user's own data
        Route::get('me',                    [ApiTokenController::class, 'me']);
        Route::get('me/galleries',          [GalleryApiController::class, 'myGalleries']);

        // List tokens (read operation — doesn't modify tokens)
        Route::get('tokens',                [ApiTokenController::class, 'index']);
    });

    // ── Authenticated write endpoints (Sanctum + ability:write) ────────
    // D-5 FIX: Only tokens with 'write' ability can create/delete tokens.
    // A read-only token CANNOT mint new tokens or revoke existing ones.
    Route::middleware(['auth:sanctum', 'ability:write'])->group(function () {

        // API token management (generate/revoke tokens — write operations)
        Route::post('tokens',               [ApiTokenController::class, 'store']);
        Route::delete('tokens/{tokenId}',   [ApiTokenController::class, 'destroy']);
    });
});
