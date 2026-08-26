<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-2 (publish moment): new galleries are DRAFTS by default.
 *
 * Previously `galleries.is_active` defaulted to TRUE — a freshly created
 * gallery (i.e. an empty room with zero artworks) was instantly reachable
 * at its public URL, indexable in sitemaps and discoverable. The product
 * now has an explicit publish step:
 *
 *   GalleryController::store()  sets is_active = false explicitly,
 *   POST /admin/galleries/{id}/publish   flips it live (needs ≥1 artwork),
 *   POST /admin/galleries/{id}/unpublish returns it to draft.
 *
 * This migration flips the COLUMN DEFAULT so the schema reflects the
 * product intent for any insertion path that omits is_active.
 *
 * NOTE (deploy): on MySQL this compiles to a MODIFY COLUMN (table rebuild)
 * — expect a few seconds on large galleries tables; run inside the normal
 * `php artisan migrate` deploy window. On SQLite (CI) Laravel performs its
 * standard create-copy-rename rebuild.
 *
 * Existing rows are intentionally NOT touched: galleries that are live
 * today stay live.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'is_active')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->boolean('is_active')->default(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'is_active')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->change();
            });
        }
    }
};
