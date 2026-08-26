<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 10 — DB-backed per-event outbound webhook subscriptions.
 *
 * Until Iteration 9, every event the OutboundWebhookService dispatched
 * went to ONE URL (env var OUTBOUND_WEBHOOK_URL). A security team that
 * only wanted to subscribe to "who is receiving the weekly financial
 * digest" had to also receive every gallery.published / user.upgraded
 * event, and an external billing-reconciliation service that only
 * cared about subscription.* events had to filter on its own side.
 *
 * This table follows the billing_digest_recipients precedent (Iter-7):
 * DB-backed, audit-logged add/remove (the controller writes the audit
 * row), env fallback for fresh installs (when this table is empty the
 * service still falls back to OUTBOUND_WEBHOOK_URL — preserves the
 * Iter-9 dispatch contract verbatim, so a deploy of this code without
 * a migration of subscriptions still works exactly as before).
 *
 * Schema:
 *   - event_type  e.g. 'billing.recipient_added', 'gallery.published'.
 *                  Not a foreign key — the event list is the
 *                  OutboundWebhookService docblock contract, not a
 *                  table; new events are added by dispatching them
 *                  through the service (no schema change here).
 *   - target_url  the subscriber's HTTPS endpoint.
 *   - secret      optional per-subscription HMAC secret. If empty,
 *                  the dispatch path falls back to the global
 *                  OUTBOUND_WEBHOOK_SECRET (preserves the Iter-9
 *                  signature contract). If neither is set, the
 *                  payload is dispatched unsigned (the receiver can
 *                  still see X-Exospace-Event; no X-Exospace-Signature).
 *   - is_active   default true; can be toggled off to pause a
 *                  subscription without deleting it (useful for
 *                  incident triage — disable the noisy subscriber
 *                  without losing its config).
 *   - added_by    the super-admin who added the subscription
 *                  (foreign key → users, nullOnDelete so a deleted
 *                  admin's subscriptions survive attribution — the
 *                  audit log rows that captured the add still carry
 *                  the actor_id, this is just the convenience FK
 *                  for the management UI).
 *   - unique (event_type, target_url) — same subscriber can't be
 *                  registered for the same event twice. Different
 *                  events at the same URL is allowed (a subscriber
 *                  receiving both billing.recipient_added and
 *                  billing.recipient_removed is one row each, same
 *                  URL — the service fans out to both).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('webhook_subscriptions')) {
            return;
        }

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);
            $table->string('target_url', 500);
            $table->string('secret', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('added_by')->nullable();

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();

            // Same subscriber can't be registered for the same event
            // twice. Different events at the same URL is allowed.
            $table->unique(['event_type', 'target_url']);
            // Hot-path lookup: dispatch fans out by event_type +
            // is_active; index the active-only path so the dispatch
            // query is a single index seek per event.
            $table->index(['event_type', 'is_active']);
        });
    }

    public function down(): void
    {
        // Guard the drop so a rolling deploy running migrate:rollback
        // survives either state (same convention as every Iter-7+
        // migration in this codebase).
        if (Schema::hasTable('webhook_subscriptions')) {
            Schema::drop('webhook_subscriptions');
        }
    }
};
