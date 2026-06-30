<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\NewsletterSignup
 *
 * Email captured in the entrance curtain of a gallery. Attributed to
 * the gallery so the curator can see their audience in analytics.
 * Unique on (gallery_id, email) — one signup per email per gallery.
 */
class NewsletterSignup extends Model
{
    protected $fillable = [
        'gallery_id', 'email', 'name', 'ip_address', 'referrer', 'signed_up_at',
    ];

    protected $casts = [
        'signed_up_at' => 'datetime',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }
}
