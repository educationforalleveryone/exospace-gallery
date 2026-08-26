<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-1 FIX: Add subscription columns to the users table.
 *
 * Exospace's pricing model has two purchase types:
 *   1. One-time purchase (existing): user pays $29/$99 once, gets lifetime
 *      access. plan_expires_at = null, no subscription.
 *   2. Recurring subscription (NEW): user pays $X/month or $Y/year, gets
 *      access for the billing period. plan_expires_at = next billing date.
 *      If the renewal payment fails, the subscription is cancelled and
 *      the user is downgraded.
 *
 * This migration adds the columns needed to track 2Checkout recurring
 * subscriptions:
 *   - subscription_id: 2Checkout's recurring order ID (links to their
 *     subscription management API)
 *   - subscription_status: active | cancelled | past_due | trialing
 *   - subscription_cancelled_at: when the user (or system) cancelled
 *   - subscription_ends_at: when the cancelled subscription's access
 *     expires (the end of the already-paid-for period)
 *
 * These columns are NULL for one-time-purchase users — the existing
 * plan_expires_at = null check distinguishes lifetime vs. subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 2Checkout's recurring order ID. Set when a recurring product
            // is purchased. NULL for one-time purchases + free users.
            $table->string('subscription_id')->nullable()->after('plan_expires_at');

            // Subscription lifecycle status:
            //   active    — recurring payments succeeding, access granted
            //   past_due  — latest renewal failed, in grace period
            //   cancelled — user or system cancelled, access until ends_at
            //   trialing  — free trial period (future feature, not yet used)
            $table->string('subscription_status')->nullable()->after('subscription_id');

            // When the cancellation was requested. Used for analytics
            // (churn rate) + for the "reactivate" flow (if cancelled
            // but subscription_ends_at hasn't passed, the user can
            // reactivate without re-entering payment).
            $table->timestamp('subscription_cancelled_at')->nullable()->after('subscription_status');

            // When the subscription's access expires. For active subscriptions,
            // this equals the next billing date. For cancelled subscriptions,
            // this is the end of the already-paid-for period (the user keeps
            // access until this date, then is downgraded). NULL for
            // one-time purchases (which use plan_expires_at = null for lifetime).
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_cancelled_at');
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
            $table->dropColumn([
                'subscription_id',
                'subscription_status',
                'subscription_cancelled_at',
                'subscription_ends_at',
            ]);
        });
    }
};
