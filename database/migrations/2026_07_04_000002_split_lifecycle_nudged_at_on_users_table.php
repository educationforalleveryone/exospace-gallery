<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0-7 FIX (audit): Split lifecycle_nudged_at into two separate columns.
 *
 * Before this fix, both the inactive-nudge flow and the plan-expiry-reminder
 * flow used the same `lifecycle_nudged_at` column. This caused a silent bug:
 * a user who was inactive-nudged within 14 days of their plan expiry was
 * filtered out of the plan-expiry-reminder flow, so they NEVER received a
 * renewal warning. The plan silently expired and the user was downgraded
 * to Free with no notification.
 *
 * This migration:
 *   1. Adds `inactive_nudged_at` (timestamp, nullable) — tracks the
 *      "you haven't published in 7 days" nudge.
 *   2. Adds `plan_expiry_reminded_at` (timestamp, nullable) — tracks the
 *      "your plan expires soon" reminder.
 *   3. Migrates existing `lifecycle_nudged_at` data into `inactive_nudged_at`
 *      (the inactive-nudge flow was the first to ship, so existing data is
 *      from that flow).
 *   4. Drops `lifecycle_nudged_at` (no longer used).
 *
 * Rollback (down) restores the original single-column layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the two new columns.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('inactive_nudged_at')->nullable()->after('lifecycle_nudged_at');
            $table->timestamp('plan_expiry_reminded_at')->nullable()->after('inactive_nudged_at');
        });

        // 2. Migrate existing lifecycle_nudged_at data → inactive_nudged_at.
        //    The inactive-nudge flow was the first to ship, so all existing
        //    timestamps in lifecycle_nudged_at are from that flow.
        DB::table('users')
            ->whereNotNull('lifecycle_nudged_at')
            ->update([
                'inactive_nudged_at' => DB::raw('lifecycle_nudged_at'),
            ]);

        // 3. Drop the old column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifecycle_nudged_at');
        });
    }

    public function down(): void
    {
        // 1. Restore the old column.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lifecycle_nudged_at')->nullable()->after('ban_reason');
        });

        // 2. Migrate inactive_nudged_at data back → lifecycle_nudged_at.
        //    (plan_expiry_reminded_at data is lost on rollback — it was
        //    not tracked before this migration.)
        DB::table('users')
            ->whereNotNull('inactive_nudged_at')
            ->update([
                'lifecycle_nudged_at' => DB::raw('inactive_nudged_at'),
            ]);

        // 3. Drop the two new columns.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inactive_nudged_at');
            $table->dropColumn('plan_expiry_reminded_at');
        });
    }
};
