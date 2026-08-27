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

// ── OpsCenter ingestion API (Iteration 1) ────────────────────────────────
//
// POST /api/ops/ingest — the platform-wide reporting endpoint. Other
// applications on the Coolify server push their errors/events here with a
// shared token (X-Ops-Token) configured in OPS_INGEST_TOKENS. No agents,
// no Docker socket, no inbound ports on the reporting side.
//
// Fail-closed: when OPS_INGEST_TOKENS is empty the controller aborts 404 —
// the endpoint does not exist (same convention as /metrics).
// Rate-limited per config (ops.ingest.requests_per_minute, default 30/min).
// Every payload is redacted server-side before persistence.
Route::prefix('ops')->name('ops.')->group(function () {
    Route::post('/ingest', [\App\Ops\Http\Controllers\OpsIngestController::class, 'store'])
        ->name('ingest')
        ->middleware('throttle:' . (int) config('ops.ingest.requests_per_minute', 30) . ',1');
});

// ── Testing Control Center ingestion API (QA Iteration 1) ────────────────
//
// POST /api/control-center/runs — CI runners push JUnit XML artifacts here
// after executing a test profile, feeding run history / flaky detection /
// release readiness. Auth: X-QA-Token header matching QA_INGEST_TOKEN.
//
// Fail-closed exactly like /api/ops/ingest: token unset → 404.
Route::post('/control-center/runs', [\App\Http\Controllers\ControlCenter\IngestController::class, 'store'])
    ->name('control-center.ingest');
