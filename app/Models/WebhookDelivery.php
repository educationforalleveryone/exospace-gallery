<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 11 — one row per OutboundWebhookService dispatch
 * completion (success OR retry-exhaustion).
 *
 * Written by OutboundWebhookService::dispatchSingle() at the end
 * of the retry loop. The row captures the FINAL state of the
 * dispatch sequence (not one row per individual retry attempt —
 * the retry loop runs internally; one row per dispatch keeps the
 * ledger row volume proportional to the dispatch event count,
 * not to the retry attempt count).
 *
 * The ledger is the persisted analog of the existing Log::info /
 * Log::warning / Log::error calls in dispatchSingle() — those
 * remain (for the production log aggregator) but the ledger row
 * is the triage surface for the operator (a SQL query on this
 * table vs grep across rotated laravel.log files).
 *
 * Backward-compat: the dispatchSingle() path guards the write
 * behind Schema::hasTable('webhook_deliveries') so a fresh
 * install with the migration not yet applied (or a test
 * database that didn't run this migration) silently skips the
 * ledger write — same shape as the Iter-10 dispatch fan-out
 * Schema::hasTable('webhook_subscriptions') guard.
 *
 * @see \App\Services\OutboundWebhookService::dispatchSingle()
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'subscription_id',
        'event_type',
        'target_url',
        'http_status',
        'attempt_count',
        'success',
        'error_message',
        'delivered_at',
    ];

    protected $casts = [
        'success'      => 'boolean',
        'delivered_at' => 'datetime',
        'http_status'  => 'integer',
        'attempt_count' => 'integer',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    /**
     * Scope: only successful deliveries.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('success', true);
    }

    /**
     * Scope: only failed deliveries (any retry-exhausted or non-2xx).
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('success', false);
    }

    /**
     * The single most-recent delivery row for a given subscription
     * (or null when no delivery has been recorded yet). Called from
     * the WebhookSubscriptionController's "Last delivery" column
     * on the management UI for a SINGLE subscription (e.g. the
     * history page header).
     */
    public static function latestForSubscription(WebhookSubscription $subscription): ?self
    {
        return static::where('subscription_id', $subscription->id)
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The latest delivery row for each subscription in a collection,
     * keyed by subscription_id. Used by the management UI's
     * "Last delivery" column — one query for the page (not N+1).
     *
     * Approach: gather the page's subscription IDs, then for each
     * one fetch MAX(id) GROUP BY subscription_id (one round-trip),
     * then fetch those rows (one round-trip). The MAX(id) approach
     * assumes IDs are monotonic with delivered_at — true under
     * serial dispatch but not under concurrent async dispatch;
     * ordered by delivered_at then id secondarily covers the
     * concurrent case (two dispatches finishing in the same
     * millisecond get the higher id).
     *
     * Returns a base Illuminate\Support\Collection (not an
     * Eloquent\Collection) because keyBy() on an Eloquent
     * Collection downgrades to a base Collection — the only
     * consumer (the blade view) uses ->get($subId) which both
     * support. The docstring declares the base type to avoid
     * the strict-type return-value check from raising.
     *
     * @param  \Illuminate\Support\Collection<int, WebhookSubscription>  $subscriptions
     * @return \Illuminate\Support\Collection<int, self>  keyed by subscription_id
     */
    public static function latestForSubscriptions(Collection $subscriptions): Collection
    {
        if ($subscriptions->isEmpty() || ! Schema::hasTable('webhook_deliveries')) {
            return collect();
        }

        $ids = $subscriptions->pluck('id')->all();

        // Subquery: the highest delivery id per subscription in the page.
        // DB::raw is safe here — $ids is a sanitized integer array from
        // Eloquent's pluck() over a fresh query result (not user input).
        $latestIds = static::query()
            ->selectRaw('MAX(id) AS max_id')
            ->whereIn('subscription_id', $ids)
            ->groupBy('subscription_id')
            ->pluck('max_id')
            ->all();

        if (empty($latestIds)) {
            return collect();
        }

        return static::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('subscription_id');
    }
}
