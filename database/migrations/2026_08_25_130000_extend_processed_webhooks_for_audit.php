<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 4 — webhook payload persistence + status lifecycle.
 *
 * Before this migration, processed_webhooks was a pure dedupe marker:
 * message_id + message_type + invoice_id, inserted at ingress and DELETED
 * on every processing failure (Iteration-1's forgetProcessedWebhook fix so
 * 2Checkout retries could reprocess). Two operational consequences:
 *
 *   1. The raw IPN payload was never stored anywhere (only a PII-redacted
 *      log line), so when 2CO missed a webhook or processing failed
 *      permanently, there was NOTHING to replay or audit — support had to
 *      ask 2CO support for the payload, days later.
 *   2. On failure the row was deleted, destroying even the record that the
 *      event arrived at all.
 *
 * This migration turns the table into a real webhook ledger:
 *
 *   payload          — full IPN body (JSON). Contains PII (customer_email,
 *                      customer_name), so retention is bounded to 90 days by
 *                      exospace:cleanup-stale (GDPR proportionality: billing
 *                      dispute resolution window vs. data minimization).
 *   status           — 'processing' | 'processed' | 'failed'.
 *                      'failed' rows do NOT block reprocessing (replaces the
 *                      delete-on-failure semantics); 'processing' rows older
 *                      than 10 minutes are treated as crashed and claimable.
 *   replay_count     — how many times an admin replayed this webhook.
 *   last_replayed_at — when.
 *   updated_at       — status transition timestamps (crash detection).
 *
 * Backfill: existing rows get status='processed'. Under the OLD semantics a
 * surviving row meant the webhook was processed successfully (all failure
 * paths deleted their marker), so 'processed' is the truthful backfill.
 *
 * MySQL note: adding nullable columns + defaults is INPLACE; the status
 * index uses a short varchar. Portable up/down — verified on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            $table->text('payload')->nullable();
            $table->string('status', 20)->default('processed')->index();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Truthful backfill (see docblock): every pre-migration row survived
        // only because its processing succeeded.
        \DB::table('processed_webhooks')->whereNull('status')->update([
            'status'      => 'processed',
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Iteration-1 rollback guard pattern: only drop what we added.
        // SQLite requires the index to go BEFORE the column it references
        // (ALTER TABLE DROP COLUMN fails while an index still uses it).
        try {
            Schema::table('processed_webhooks', function (Blueprint $table) {
                $table->dropIndex('processed_webhooks_status_index');
            });
        } catch (\Throwable) {
            // Index absent under a different name — the column drop below
            // still proceeds.
        }

        Schema::table('processed_webhooks', function (Blueprint $table) {
            foreach (['payload', 'status', 'replay_count', 'last_replayed_at', 'updated_at'] as $column) {
                if (\Schema::hasColumn('processed_webhooks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
