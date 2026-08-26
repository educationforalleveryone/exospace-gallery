<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add plan columns to the users table.
 *
 * WHY
 * ---
 * Round 1 introduced paid plans (Free / Pro / Studio) with per-plan
 * gallery and image limits. This migration adds the columns.
 *
 * FIX (task H04 / audit H7)
 * -------------------------
 * The original backfill set Pro and Studio to the SAME limits
 * (999 galleries / 100 images), which contradicted User::planLimits():
 *
 *   - Pro should be 5 galleries / 100 images
 *   - Studio should be 999 galleries / 500 images
 *
 * The User::boot() updating hook fixes this on every save, so the bad
 * backfill was masked in production. But the migration's stated values
 * were wrong and would resurface if anyone ever reran it (e.g. via
 * migrate:fresh + seed).
 *
 * Now the backfill matches User::planLimits() exactly.
 *
 * CI FIX (Iteration 1 premium audit): the raw `ALTER TABLE ... ENUM(...)`
 * DDL is MySQL-only and aborted `migrate:fresh` on SQLite, which is what
 * CI (and any local SQLite test run) uses — the whole feature-suite was
 * erroring with "near ''free'': syntax error" before any test executed.
 * The schema-builder path below is driver-portable: MySQL still gets a
 * real ENUM column; SQLite gets a VARCHAR + CHECK constraint.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro', 'studio'])->default('free');
            $table->unsignedInteger('max_galleries')->default(1);
            $table->unsignedInteger('max_images')->default(10);
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
        });

        // Backfill existing users with correct per-plan limits.
        // These values match User::planLimits() exactly (task H04).
        DB::table('users')->where('plan', 'free')->update([
            'max_galleries'  => 1,
            'max_images'     => 10,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('users')->where('plan', 'pro')->update([
            'max_galleries'  => 5,
            'max_images'     => 100,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('users')->where('plan', 'studio')->update([
            'max_galleries'  => 999,
            'max_images'     => 500,  // ← was 100, fixed in task H04
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'plan_expires_at',
                'plan_started_at',
                'max_images',
                'max_galleries',
                'plan',
            ]);
        });
    }
};
