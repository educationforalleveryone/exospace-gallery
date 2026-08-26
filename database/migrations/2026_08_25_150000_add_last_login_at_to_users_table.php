<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 6 — users.last_login_at: a truthful activity signal.
 *
 * Problem: the platform had NO login-activity record. The weekly cohort
 * retention report (exospace:cohort-retention) therefore defined "active"
 * as "galleries.updated_at in the period OR users.updated_at >= period
 * start" — the second clause is (a) UNBOUNDED (any future row-update
 * counts as activity in every earlier period) and (b) noisy (users.
 * updated_at is bumped by plan changes, marketing prefs, admin writes,
 * 2FA setup — none of which is product engagement). Retention numbers
 * were inflated and semantically incoherent.
 *
 * This column is stamped by the StampLastLogin listener on Laravel's
 * Login event — one listener covers password login (Auth::attempt),
 * OAuth login and post-registration auto-login (Auth::login). Remember-
 * cookie restoration deliberately does NOT fire the event, so a stale
 * browser session does not count as a login.
 *
 * Retention week-w activity then becomes: last_login_at in [start,end)
 * OR galleries.updated_at in [start,end) — both bounded, both genuine
 * engagement.
 *
 * Nullable: every existing user starts NULL until their next login.
 * The retention service treats NULL as "no login activity yet" (the
 * gallery signal keeps those cohorts measurable immediately).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            return; // rolling-deploy / partial-migration safety
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('banned_at');

            // Retention scans filter users by last_login_at windows
            // (weekly cohorts × activity weeks) — same scale pattern as
            // the Iteration-4 users(created_at)/users(plan) indexes.
            $table->index(['last_login_at']);
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): the table
        // may already be gone when this runs on fresh installs.
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'last_login_at')) {
            return;
        }

        // ITERATION-1 FIX (portable rollback): SQLite cannot drop a column
        // that an index still references — drop the index first, in its
        // own statement, before dropping the column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
