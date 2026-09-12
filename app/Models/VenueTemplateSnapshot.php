<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueTemplateSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'venue_template_id',
        'label',
        'config',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function venueTemplate(): BelongsTo
    {
        return $this->belongsTo(VenueTemplate::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForVenue($query, int $venueId)
    {
        return $query->where('venue_template_id', $venueId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
