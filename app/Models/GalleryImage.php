<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * App\Models\GalleryImage
 *
 * (Task H21 / audit H45) — implements Spatie Media Library for responsive
 * WebP image variants. Previously the codebase stored a single JPEG
 * (max 2048×2048) and served it at full size to all devices. Mobile users
 * on 360px screens downloaded a 2048px image.
 *
 * With Spatie Media Library:
 *   - The original upload is stored as a media collection
 *   - registerMediaConversions() generates responsive variants:
 *       - thumb: 400×400 (cover image for cards)
 *       - small: 768px wide (mobile)
 *       - medium: 1024px wide (tablet)
 *       - large: 2048px wide (desktop)
 *     All encoded as WebP @ 85% quality
 *   - Views use $image->getSrcset() for responsive <img srcset>
 *
 * MIGRATION NOTES:
 *   - The `path` column is kept for backward compatibility — existing
 *     images that haven't been re-processed still work via $image->path.
 *   - New uploads go through ImageProcessingService which calls
 *     $image->addMedia($file)->toMediaCollection('original') after
 *     the GalleryImage row is created.
 *   - A queued job should regenerate media for existing images:
 *       php artisan tinker
 *       >>> App\Models\GalleryImage::all()->each(fn($i) => $i->addMedia(storage_path('app/public/' . $i->path))->toMediaCollection('original'));
 *   - Views should check $image->hasMedia() before using srcset, and
 *     fall back to $image->path for legacy images.
 */
class GalleryImage extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

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

    // ── Spatie Media Library conversions (Task H21) ────────────────────

    /**
     * Register responsive image conversions.
     *
     * These run asynchronously via the queue when a new file is added
     * to the 'original' media collection. The conversions generate
     * WebP variants at multiple widths for responsive srcset.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Thumbnail — 400px wide, used for gallery cards and admin lists
        // (Hotfix) Removed crop() — Spatie v11 has a type bug with crop()
        // on certain PHP versions. width()+height() with fit Crop is the
        // safe alternative that produces the same visual result.
        $this->addMediaConversion('thumb')
              ->width(400)
              ->height(400)
              ->fit('crop', 400, 400)
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

    /**
     * Register responsive media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('original')
             ->singleFile();
    }

    // ── Existing relationships ──────────────────────────────────────────

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    // ── Accessors ───────────────────────────────────────────────────────

    /**
     * Get the public URL for the original image.
     *
     * Falls back to the legacy `path` column if no Spatie media exists
     * (for images uploaded before the H21 migration).
     */
    public function getPublicUrlAttribute(): string
    {
        $media = $this->getFirstMedia('original');
        if ($media) {
            return $media->getUrl();
        }
        return asset($this->path);
    }

    /**
     * Get the URL for a specific conversion (thumb, small, medium, large).
     *
     * Falls back to the original URL if the conversion doesn't exist
     * (e.g. for legacy images that haven't been re-processed).
     */
    public function conversionUrl(string $conversion): string
    {
        $media = $this->getFirstMedia('original');
        if ($media && $media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }
        return $this->public_url;
    }

    /**
     * Build a responsive srcset string for <img srcset>.
     *
     * Returns something like:
     *   "/storage/123/conversions/small.webp 768w,
     *    /storage/123/conversions/medium.webp 1024w,
     *    /storage/123/conversions/large.webp 2048w"
     *
     * Falls back to the original URL if no conversions exist.
     */
    public function getSrcsetAttribute(): string
    {
        $media = $this->getFirstMedia('original');
        if (! $media) {
            return $this->public_url . ' 2048w';
        }

        $srcset = [];
        foreach (['small' => 768, 'medium' => 1024, 'large' => 2048] as $name => $width) {
            if ($media->hasGeneratedConversion($name)) {
                $srcset[] = $media->getUrl($name) . " {$width}w";
            }
        }

        return empty($srcset) ? $this->public_url . ' 2048w' : implode(', ', $srcset);
    }

    /**
     * Formatted price with currency symbol.
     */
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
