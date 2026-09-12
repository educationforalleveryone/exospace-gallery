<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function resolveRobots(?string $automatic): ?string
    {
        return $this->robots_directive ?: $automatic;
    }

    public function resolveSitemapInclusion(bool $automatic): bool
    {
        if ($this->sitemap_include === null) {
            return $automatic;
        }

        return (bool) $this->sitemap_include;
    }

    public function resolveStructuredData(bool $automatic): bool
    {
        if ($this->structured_data_enabled === null) {
            return $automatic;
        }

        return (bool) $this->structured_data_enabled;
    }
}
