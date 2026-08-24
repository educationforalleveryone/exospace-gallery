<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEO override profile for any entity (gallery, artist, seo_page, ...).
 *
 * See the migration docblock for the design rationale. Reading rules:
 *  - every override column is NULL-able and NULL means "auto-generate";
 *  - use the resolved*() helpers instead of raw columns — they apply the
 *    NULL-fallback so callers never need to know whether a profile exists.
 */
class SeoProfile extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'title_override',
        'description_override',
        'canonical_override',
        'robots_directive',
        'og_image_path',
        'sitemap_include',
        'structured_data_enabled',
        'updated_by',
    ];

    protected $casts = [
        'sitemap_include' => 'boolean',
        'structured_data_enabled' => 'boolean',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Resolution helpers ─────────────────────────────────────────────

    /**
     * Effective robots directive, given the entity's automatic directive.
     * Profile override wins when set; otherwise the automatic value is used.
     */
    public function resolveRobots(?string $automatic): ?string
    {
        return $this->robots_directive ?: $automatic;
    }

    /**
     * Effective sitemap inclusion given the automatic decision.
     */
    public function resolveSitemapInclusion(bool $automatic): bool
    {
        if ($this->sitemap_include === null) {
            return $automatic;
        }

        return (bool) $this->sitemap_include;
    }

    /**
     * Effective structured-data eligibility given the automatic decision.
     */
    public function resolveStructuredData(bool $automatic): bool
    {
        if ($this->structured_data_enabled === null) {
            return $automatic;
        }

        return (bool) $this->structured_data_enabled;
    }
}
