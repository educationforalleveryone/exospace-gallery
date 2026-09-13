<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class GalleryImage extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

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
        'price'      => 'decimal:2',
        'for_sale'   => 'boolean',
        'year'       => 'integer',
        'edition_size' => 'integer',
    ];

    // ── Spatie Media Library conversions ───────────────────────────────

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
              ->width(400)
              ->height(400)
              ->fit(Fit::Crop, 400, 400)
              ->format('webp')
              ->quality(85)
              ->nonQueued();

        // Small — 768px wide, for mobile devices
        $this->addMediaConversion('small')
              ->width(768)
              ->format('webp')
              ->quality(85);

        // Medium — 1024px wide, for tablets
        $this->addMediaConversion('medium')
              ->width(1024)
              ->format('webp')
              ->quality(85);

        // Large — 2048px wide, for desktop (matches the original max)
        $this->addMediaConversion('large')
              ->width(2048)
              ->format('webp')
              ->quality(85);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('original')
             ->singleFile();
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function getPublicUrlAttribute(): string
    {
        $media = $this->getMemoizedMedia();
        if ($media) {
            try {
                return $media->getUrl();
            } catch (\Throwable $e) {
                // Fall through to legacy path
            }
        }
        return asset($this->path);
    }

    public function conversionUrl(string $conversion): string
    {
        $media = $this->getMemoizedMedia();
        if ($media) {
            try {
                if ($media->hasGeneratedConversion($conversion)) {
                    return $media->getUrl($conversion);
                }
            } catch (\Throwable $e) {
                // Fall back to public_url
            }
        }
        return $this->public_url;
    }

    public function getSrcsetAttribute(): string
    {
        $media = $this->getMemoizedMedia();
        if (! $media) {
            return $this->public_url . ' 2048w';
        }

        try {
            $srcset = [];
            foreach (['small' => 768, 'medium' => 1024, 'large' => 2048] as $name => $width) {
                if ($media->hasGeneratedConversion($name)) {
                    $srcset[] = $media->getUrl($name) . " {$width}w";
                }
            }

            return empty($srcset) ? $this->public_url . ' 2048w' : implode(', ', $srcset);
        } catch (\Throwable $e) {
            // Any Spatie error — fall back to legacy path
            return asset($this->path) . ' 2048w';
        }
    }

    private ?Media $memoizedMedia = null;
    private bool $memoizedMediaResolved = false;

    private function getMemoizedMedia(): ?Media
    {
        if ($this->memoizedMediaResolved) {
            return $this->memoizedMedia;
        }

        try {
            $this->memoizedMedia = $this->getFirstMedia('original');
        } catch (\Throwable $e) {
            $this->memoizedMedia = null;
        }
        $this->memoizedMediaResolved = true;

        return $this->memoizedMedia;
    }

    public function formattedPrice(): string
    {
        if (!$this->for_sale || !$this->price) return '';

        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'PKR' => 'Rs '];
        $symbol = $symbols[$this->currency] ?? $this->currency . ' ';
        return $symbol . number_format((float) $this->price, 2);
    }

    public function formattedEdition(): string
    {
        if (!$this->edition_size) return '';
        if ($this->edition_number) {
            return "Edition {$this->edition_number} of {$this->edition_size}";
        }
        return "Edition of {$this->edition_size}";
    }
}
