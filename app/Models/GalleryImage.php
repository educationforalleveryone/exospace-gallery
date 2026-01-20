<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryImage extends Model
{
    protected $fillable = [
        'gallery_id',
        'filename',
        'original_name',
        'path',
        'mime_type',
        'size',
        'width',
        'height',
        'orientation',
        'position_order',
        'wall_position',
        'title',
        'description'
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }
}