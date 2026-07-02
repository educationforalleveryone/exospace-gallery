<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the pending_upgrades table.
     *
     * Task H01 — closes the silent revenue leak where the 2Checkout buy URL
     * had no user binding. A logged-in user could pay with a PayPal email
     * different from their account email → the webhook's customer_email
     * lookup failed → user paid but was never upgraded, and nobody was
     * notified.
     *
     * With this table:
     *   1. User clicks "Upgrade to Pro" → BillingController creates a
     *      pending_upgrades row with a random token + redirects to
     *      2Checkout with `external-reference=<token>` and
     *      `customer_email=<user_email>` (pre-filled).
     *   2. 2Checkout completes checkout → sends IPN with the same
     *      `external-reference` token.
     *   3. WebhookController matches the IPN by external-reference FIRST
     *      (authoritative — proves the payment came from this user's
     *      click), falling back to customer_email (legacy path).
     *   4. The pending_upgrades row is marked 'converted' and linked to
     *      the resulting transaction.
     *
     * Rows older than 7 days with no matching IPN are pruned by a
     * scheduled cleanup command.
     */
    public function up(): void
    {
        Schema::create('pending_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->string('plan', 20);          // pro | studio
            $table->string('product_id', 100);   // 2Checkout product ID
            $table->string('status', 20)->default('pending'); // pending | converted | expired
            $table->foreignId('transaction_id')->nullable();  // set when IPN arrives
            $table->timestamp('expires_at')->nullable();      // 7 days from creation
            $table->timestamps();

            $table->index('user_id');
            $table->index('token');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_upgrades');
    }
};
