<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-7: Add trial period columns to users table.
 *
 * Supports 14-day free trials for Pro/Studio subscriptions. When a user
 * starts a trial, trial_ends_at is set to now()+14 days. The user gets
 * full plan access until trial_ends_at. If they don't subscribe before
 * the trial ends, they're downgraded to Free.
 *
 * Trials are one-time per user (trial_ends_at is never null again after
 * being set, so we can check "has this user ever used a trial?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('avatar_url');
            $table->index('trial_ends_at');
        });
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
            $table->dropIndex(['trial_ends_at']);
            $table->dropColumn('trial_ends_at');
        });
    }
};
