<?php

use App\Http\Controllers\Api\GalleryApiController;
use App\Http\Controllers\Api\ArtistApiController;
use App\Http\Controllers\Api\ApiTokenController;
use Illuminate\Support\Facades\Route;

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

    Route::middleware(['auth:sanctum', 'ability:read'])->group(function () {

        // Authenticated user's own data
        Route::get('me',                    [ApiTokenController::class, 'me']);
        Route::get('me/galleries',          [GalleryApiController::class, 'myGalleries']);

        // List tokens (read operation — doesn't modify tokens)
        Route::get('tokens',                [ApiTokenController::class, 'index']);
    });

    Route::middleware(['auth:sanctum', 'ability:write'])->group(function () {

        // API token management (generate/revoke tokens — write operations)
        Route::post('tokens',               [ApiTokenController::class, 'store']);
        Route::delete('tokens/{tokenId}',   [ApiTokenController::class, 'destroy']);
    });
});

Route::prefix('ops')->name('ops.')->group(function () {
    Route::post('/ingest', [\App\Ops\Http\Controllers\OpsIngestController::class, 'store'])
        ->name('ingest')
        ->middleware('throttle:' . (int) config('ops.ingest.requests_per_minute', 30) . ',1');
});

Route::post('/control-center/runs', [\App\Http\Controllers\ControlCenter\IngestController::class, 'store'])
    ->name('control-center.ingest');
