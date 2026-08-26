<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-3 (publish analytics + discover ordering): galleries.published_at.
 *
 * Semantics — FIRST publish timestamp:
 *   - set once by GalleryController::publish() when a gallery goes live
 *     (a later unpublish→publish cycle does NOT overwrite it — re-publishing
 *     an old exhibition must not corrupt the time-to-first-exhibition
 *     metric, which is derived as published_at − users.created_at);
 *   - retained on unpublish (it is a historical fact: "this exhibition
 *     was published at …");
 *   - null only for galleries that have never been live.
 *
 * Enables:
 *   - /discover?sort=published ("recently published" ordering — a gallery
 *     created long ago but published yesterday finally ranks as new, which
 *     created_at ordering got wrong);
 *   - precise publish-funnel analytics (OnboardingAnalytics time-to-publish)
 *     instead of the is_active proxy that cannot say WHEN.
 *
 * Backfill: rows that are already live (is_active = 1) get
 * published_at = created_at — the best available approximation for
 * pre-iteration galleries. The approximation only ever overstates TTFE
 * slightly (created_at ≤ real publish time), never understates it.
 *
 * Portable: guarded column/index creation, plain UPDATE backfill, guarded
 * rollback (works on both SQLite CI and MySQL production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries') && ! Schema::hasColumn('galleries', 'published_at')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->timestamp('published_at')->nullable()->after('is_active');
            });
        }

        // Backfill already-live galleries (see docblock). Plain UPDATE is
        // portable across SQLite and MySQL; soft-deleted rows included on
        // purpose — their publish history is equally real.
        if (Schema::hasColumn('galleries', 'published_at')) {
            DB::table('galleries')
                ->where('is_active', true)
                ->whereNull('published_at')
                ->update(['published_at' => DB::raw('created_at')]);
        }

        // Index for the discover sort (orderByDesc('published_at')).
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'published_at')
            && ! Schema::hasIndex('galleries', 'galleries_published_at_index')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->index('published_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'published_at')) {
            // Drop the index before the column (old SQLite builds reject
            // dropping an indexed column outright).
            if (Schema::hasIndex('galleries', 'galleries_published_at_index')) {
                Schema::table('galleries', function (Blueprint $table) {
                    $table->dropIndex('galleries_published_at_index');
                });
            }

            Schema::table('galleries', function (Blueprint $table) {
                $table->dropColumn('published_at');
            });
        }
    }
};
