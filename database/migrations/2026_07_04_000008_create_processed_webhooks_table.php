<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-9: Track processed webhook message_ids to prevent replay attacks.
 *
 * 2Checkout IPNs include a `message_id` that is unique per notification.
 * Without tracking, an attacker who captures a valid IPN can re-POST it
 * with a different `message_type` (e.g. swap ORDER_CREATED for REFUND_ISSUED).
 * The HMAC signature covers message_type, so this only works in MD5-only
 * mode — but it's still a defense-in-depth measure.
 *
 * The WebhookController checks this table before processing any IPN.
 * If the message_id + message_type combination already exists, the IPN
 * is rejected as a replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 100);
            $table->string('message_type', 50);
            $table->string('invoice_id', 100)->nullable();
            $table->timestamp('processed_at')->useCurrent();

            // Unique on message_id + message_type — prevents the same
            // notification from being processed twice with different
            // message_types (the replay-tamper attack).
            $table->unique(['message_id', 'message_type']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
