<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 11 — per-subscription delivery ledger for the
 * OutboundWebhookService dispatch path.
 *
 * The Iter-10 surface fans out per-event to every active
 * webhook_subscriptions row + the always-on env-var URL. Each
 * fan-out dispatch is currently logged via Log::info / Log::warning
 * / Log::error only — so an operator investigating "the security
 * team says they didn't receive the recipient_added webhook last
 * Tuesday" has to grep storage/logs/laravel.log across daily
 * rotation files. The ledger turns that grep into a SQL query.
 *
 * Schema shape (one row per dispatch ATTEMPT SEQUENCE, not per
 * individual retry — the retry loop runs inside dispatchSingle(),
 * and at the end of the loop we write ONE row capturing the final
 * state):
 *   - subscription_id  nullable FK → webhook_subscriptions. NULL
 *                      when the dispatch was to the env-var URL
 *                      or a direct override URL (the paths that
 *                      don't have a DB subscription row). nullOnDelete
 *                      so deleting a subscription preserves its
 *                      historical delivery ledger (the operator
 *                      can still triage "what was the last delivery
 *                      to this URL before we removed it" after a
 *                      remove — the audit row preserves the
 *                      subscription config, the delivery ledger
 *                      preserves the dispatch outcomes).
 *   - event_type       denormalized (varchar 100). Preserved
 *                      after subscription deletion so the triage
 *                      query "show me all billing.recipient_added
 *                      dispatches in the last 7 days" works
 *                      without joining to a deleted subscription
 *                      row.
 *   - target_url       denormalized (varchar 500). Same reason.
 *   - http_status      nullable unsigned int. NULL when all
 *                      retries threw exceptions / couldn't
 *                      connect (no response object). 2xx / 4xx /
 *                      5xx when at least one attempt got a response.
 *   - attempt_count    unsigned int (1..MAX_RETRIES). 1 for
 *                      immediate success, MAX_RETRIES for
 *                      exhausted retries.
 *   - success          boolean (default false). Quick filter
 *                      for "did it work" without parsing
 *                      http_status semantics in SQL.
 *   - error_message    nullable text. Last error message if any
 *                      (exception message OR a non-2xx status
 *                      summary). NULL on success.
 *   - delivered_at     timestamp (not nullable). When the
 *                      dispatch completed (success OR exhausted).
 *                      Indexed for retention cleanup DELETE WHERE
 *                      delivered_at < now()->subDays(N).
 *   - created_at, updated_at  standard timestamps.
 *
 * Indexes:
 *   - foreign on subscription_id → webhook_subscriptions.id
 *     nullOnDelete
 *   - index (subscription_id, delivered_at)  — "latest delivery
 *     per subscription" lookup on the management UI
 *   - index (event_type, delivered_at)        — "all dispatches
 *     for event X in time range" triage query
 *   - index (delivered_at)                   — retention cleanup
 *
 * The Schema::hasTable guard means this migration is safe to
 * run twice (a rolling deploy survives either state). The
 * dispatchSingle path's Schema::hasTable('webhook_deliveries')
 * guard means the code is safe to deploy BEFORE the migration
 * runs — a fresh install with the migration not yet applied
 * silently skips ledger writes (same shape as the Iter-10
 * dispatch fan-out Schema::hasTable('webhook_subscriptions')
 * guard).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('webhook_deliveries')) {
            return;
        }

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('event_type', 100);
            $table->string('target_url', 500);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('attempt_count');
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('delivered_at')->useCurrent();

            $table->foreign('subscription_id')
                ->references('id')
                ->on('webhook_subscriptions')
                ->nullOnDelete();

            $table->timestamps();

            // "Latest delivery per subscription" lookup on the
            // management UI's "Last delivery" column.
            $table->index(['subscription_id', 'delivered_at']);
            // "All dispatches for event X in time range" triage
            // query (operator grepping "show me every
            // billing.recipient_added dispatch last week").
            $table->index(['event_type', 'delivered_at']);
            // Retention cleanup DELETE WHERE delivered_at < ...
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        // Guard the drop so a rolling deploy running migrate:rollback
        // survives either state (same convention as every Iter-7+
        // migration in this codebase).
        if (Schema::hasTable('webhook_deliveries')) {
            Schema::drop('webhook_deliveries');
        }
    }
};
