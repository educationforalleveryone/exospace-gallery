<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add plan columns
        DB::statement("ALTER TABLE users ADD COLUMN plan ENUM('free','pro','studio') NOT NULL DEFAULT 'free'");
        DB::statement("ALTER TABLE users ADD COLUMN max_galleries INT NOT NULL DEFAULT 1");
        DB::statement("ALTER TABLE users ADD COLUMN max_images INT NOT NULL DEFAULT 10");
        DB::statement("ALTER TABLE users ADD COLUMN plan_started_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE users ADD COLUMN plan_expires_at TIMESTAMP NULL DEFAULT NULL");

        // Backfill existing users with correct per-plan limits.
        // These values match User::planLimits() exactly (task H04).
        DB::table('users')->where('plan', 'free')->update([
            'max_galleries'  => 1,
            'max_images'     => 10,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, NOW())'),
        ]);
        DB::table('users')->where('plan', 'pro')->update([
            'max_galleries'  => 5,
            'max_images'     => 100,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, NOW())'),
        ]);
        DB::table('users')->where('plan', 'studio')->update([
            'max_galleries'  => 999,
            'max_images'     => 500,  // ← was 100, fixed in task H04
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, NOW())'),
        ]);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users DROP COLUMN plan_expires_at");
        DB::statement("ALTER TABLE users DROP COLUMN plan_started_at");
        DB::statement("ALTER TABLE users DROP COLUMN max_images");
        DB::statement("ALTER TABLE users DROP COLUMN max_galleries");
        DB::statement("ALTER TABLE users DROP COLUMN plan");
    }
};
