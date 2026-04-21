<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'gallery_id', 'image_id', 'event',
        'session_token', 'dwell_seconds', 'referrer', 'country',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(GalleryImage::class, 'image_id');
    }
}