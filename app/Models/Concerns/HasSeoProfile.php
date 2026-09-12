<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\SeoProfile;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasSeoProfile
{
    public function seoProfile(): MorphOne
    {
        return $this->morphOne(SeoProfile::class, 'subject');
    }

    public function seoProfileOrCreate(): SeoProfile
    {
        return $this->seoProfile()->firstOrCreate(
            ['subject_type' => static::class, 'subject_id' => $this->getKey()],
        );
    }

    public function effectiveRobotsDirective(?string $automatic): ?string
    {
        $profile = $this->seoProfile()->first();

        return $profile?->resolveRobots($automatic) ?? $automatic;
    }

    public function effectiveSitemapInclusion(bool $automatic): bool
    {
        $profile = $this->seoProfile()->first();

        if (!$profile) {
            return $automatic;
        }

        return $profile->resolveSitemapInclusion($automatic);
    }

    public function effectiveStructuredData(bool $automatic): bool
    {
        $profile = $this->seoProfile()->first();

        if (!$profile) {
            return $automatic;
        }

        return $profile->resolveStructuredData($automatic);
    }
}
