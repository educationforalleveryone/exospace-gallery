<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryImage extends Model
{
    protected $fillable = [
        'gallery_id',
        'artist_id',  // NEW (Round 4) — nullable FK to artists table
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
        'description',
        // NEW (Round 4) — per-artwork metadata
        'price',
        'currency',
        'for_sale',
        'medium',
        'year',
        'dimensions',
        'edition_size',
        'edition_number',
        'external_url',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'for_sale'     => 'boolean',
        'year'         => 'integer',
        'edition_size' => 'integer',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /** The artist attributed to this artwork (nullable) */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Format the price for display. Returns null if no price set.
     */
    public function formattedPrice(): ?string
    {
        if (is_null($this->price)) return null;
        $symbol = match($this->currency ?? 'USD') {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            default => $this->currency . ' ',
        };
        return $symbol . number_format((float) $this->price, ($this->currency === 'JPY' ? 0 : 2));
    }

    /**
     * Format the edition info for display. Returns null if no edition.
     */
    public function formattedEdition(): ?string
    {
        if (!$this->edition_size && !$this->edition_number) return null;
        if ($this->edition_number && $this->edition_size) {
            return "Edition {$this->edition_number} of {$this->edition_size}";
        }
        if ($this->edition_size) return "Edition of {$this->edition_size}";
        return "Edition {$this->edition_number}";
    }
}
