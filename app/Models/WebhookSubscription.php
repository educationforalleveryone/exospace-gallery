<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public static function forEvent(string $eventType): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('event_type', $eventType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function setEventTypeAttribute(string $value): void
    {
        $this->attributes['event_type'] = trim(strtolower($value));
    }

    public function setTargetUrlAttribute(string $value): void
    {
        $this->attributes['target_url'] = trim($value);
    }
}
