<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('success', true);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('success', false);
    }

    public static function latestForSubscription(WebhookSubscription $subscription): ?self
    {
        return static::where('subscription_id', $subscription->id)
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->first();
    }

    public static function latestForSubscriptions(Collection $subscriptions): Collection
    {
        if ($subscriptions->isEmpty() || ! Schema::hasTable('webhook_deliveries')) {
            return collect();
        }

        $ids = $subscriptions->pluck('id')->all();

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
