<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITERATION 10 — one row per (event_type, target_url) subscription.
 *
 * Managed on Master Control → Billing Review → Outbound Webhooks
 * section (mirror of the digest-recipient card on the same page).
 * DB rows take precedence over the env-var-only dispatch path:
 *  - 0 rows in this table for an event  → env var still receives
 *    (preserves the Iter-9 dispatch contract verbatim).
 *  - ≥1 rows for an event                → fan out to each row +
 *    env var still receives (env var is treated as a "default"
 *    subscription that's always-on so a brand-new subscription
 *    doesn't accidentally bypass an existing env subscriber).
 *
 * Per-subscription secrets are optional — when null, the dispatch
 * path falls back to the global OUTBOUND_WEBHOOK_SECRET. This lets
 * a fresh subscription use the global secret (most common case) and
 * only high-security subscribers rotate their own.
 *
 * @see \App\Services\OutboundWebhookService::dispatch()
 */
class WebhookSubscription extends Model
{
    protected $table = 'webhook_subscriptions';

    protected $fillable = [
        'event_type',
        'target_url',
        'secret',
        'is_active',
        'added_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Active subscriptions for a given event type. Called once
     * per dispatch() — the index on (event_type, is_active) makes
     * this a single index seek.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function forEvent(string $eventType): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('event_type', $eventType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Lowercase + trim the event_type on write so the unique index
     * can't be defeated by case-different duplicates (MySQL is ci
     * by default; SQLite is cs — same normalization the
     * BillingDigestRecipient email mutator uses).
     */
    public function setEventTypeAttribute(string $value): void
    {
        $this->attributes['event_type'] = trim(strtolower($value));
    }

    /**
     * Trim the target_url on write. We do NOT lowercase — the
     * path portion of a URL is case-sensitive. Trailing slashes
     * are kept as the operator entered them (different endpoints
     * can resolve trailing slashes differently).
     */
    public function setTargetUrlAttribute(string $value): void
    {
        $this->attributes['target_url'] = trim($value);
    }
}
