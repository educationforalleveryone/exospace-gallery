<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
