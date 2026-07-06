<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-9 FIX: Add dunning tracking columns to the users table.
 *
 * Dunning = the process of recovering failed recurring payments via
 * email reminders. When a RECURRING_INSTALLMENT_FAILED webhook arrives,
 * Exospace sends a 3-email sequence (immediate, day 3, day 7) to nudge
 * the user to update their payment method.
 *
 * This migration adds columns to track which dunning emails have been
 * sent, so the SendDunningEmails command (runs daily) knows:
 *   - Which users are in the dunning window (subscription_status = past_due)
 *   - Which email in the sequence to send next (1, 2, or 3)
 *   - When the last dunning email was sent (to space them correctly)
 *
 * The dunning window ends when:
 *   - The payment succeeds (RECURRING_INSTALLMENT_SUCCESS resets the columns)
 *   - The subscription is cancelled (RECURRING_ORDER_CANCELLED)
 *   - 7 days pass with no recovery (the 3rd email is the last — after that,
 *     2Checkout's own dunning sequence + eventual cancellation takes over)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Which dunning step the user is on (1, 2, or 3). NULL when
            // not in dunning (subscription active or not recurring).
            $table->tinyInteger('dunning_step')->nullable()->after('subscription_ends_at');

            // When the last dunning email was sent. Used to space the
            // sequence: step 1 is immediate, step 2 is 3 days later,
            // step 3 is 7 days after step 1.
            $table->timestamp('dunning_last_sent_at')->nullable()->after('dunning_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dunning_step', 'dunning_last_sent_at']);
        });
    }
};
