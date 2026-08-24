<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\SeoProfile;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Attach to any model that participates in the SEO system.
 *
 * class Gallery extends Model { use HasSeoProfile; }
 *
 * Provides the polymorphic seo_profile relation plus convenience resolvers
 * used by SeoManager, the sitemap builders and the admin tooling.
 */
trait HasSeoProfile
{
    public function seoProfile(): MorphOne
    {
        return $this->morphOne(SeoProfile::class, 'subject');
    }

    /**
     * Get (or lazily create) the SEO profile row for this entity.
     * Creating on demand keeps admin flows simple: "edit SEO" always has
     * a row to bind the form to, while read paths just use seoProfile.
     */
    public function seoProfileOrCreate(): SeoProfile
    {
        return $this->seoProfile()->firstOrCreate(
            ['subject_type' => static::class, 'subject_id' => $this->getKey()],
        );
    }

    /**
     * Effective robots directive for this entity.
     * $automatic is the quality-rule outcome (e.g. 'noindex' for empty
     * galleries); a profile-level override replaces it.
     */
    public function effectiveRobotsDirective(?string $automatic): ?string
    {
        $profile = $this->seoProfile()->first();

        return $profile?->resolveRobots($automatic) ?? $automatic;
    }

    /**
     * Effective sitemap-inclusion decision for this entity.
     */
    public function effectiveSitemapInclusion(bool $automatic): bool
    {
        $profile = $this->seoProfile()->first();

        if (!$profile) {
            return $automatic;
        }

        return $profile->resolveSitemapInclusion($automatic);
    }

    /**
     * Effective structured-data eligibility for this entity.
     */
    public function effectiveStructuredData(bool $automatic): bool
    {
        $profile = $this->seoProfile()->first();

        if (!$profile) {
            return $automatic;
        }

        return $profile->resolveStructuredData($automatic);
    }
}
