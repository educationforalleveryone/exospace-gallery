<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Organic acquisition reporting (SEO OS Iteration 7).
 *
 * Answers, from first-party data only:
 *   - How do signups break down by acquisition channel?
 *   - Do organically-acquired users create galleries (the conversion the
 *     SEO machine exists to drive)?
 *
 * This service never fabricates search-engine data — impressions/clicks/
 * positions live in Search Console (see master manual §3.2). What it DOES
 * measure is the downstream behaviour of visitors the search engines send.
 */
class OrganicAcquisitionService
{
    /**
     * Signup counts by channel over a lookback window.
     *
     * @return array<string, int>
     */
    public function signupsByChannel(int $days = 90): array
    {
        $since = now()->subDays($days);

        $rows = User::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('acquisition_channel')
            ->selectRaw('acquisition_channel, COUNT(*) as total')
            ->groupBy('acquisition_channel')
            ->pluck('total', 'acquisition_channel')
            ->all();

        // Fill all channels so the UI has a stable shape.
        return array_merge([
            'organic'  => 0,
            'social'   => 0,
            'referral' => 0,
            'campaign' => 0,
            'direct'   => 0,
        ], $rows);
    }

    /**
     * Galleries created by organically-acquired users within a window
     * after signup (the SEO → product conversion).
     *
     * @return array{galleries: int, users_with_galleries: int}
     */
    public function organicGalleriesCreated(int $days = 90): array
    {
        $since = now()->subDays($days);

        $organicUserIds = User::query()
            ->where('acquisition_channel', 'organic')
            ->where('created_at', '>=', $since)
            ->pluck('id');

        if ($organicUserIds->isEmpty()) {
            return ['galleries' => 0, 'users_with_galleries' => 0];
        }

        $galleries = Gallery::withTrashed()
            ->whereIn('user_id', $organicUserIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->get();

        return [
            'galleries' => (int) $galleries->sum('total'),
            'users_with_galleries' => $galleries->count(),
        ];
    }

    /**
     * Top organic landing pages by resulting signups (the pages that
     * actually convert search visitors into accounts).
     *
     * @return \Illuminate\Support\Collection<int, array{landing_page: string, signups: int}>
     */
    public function topOrganicLandingPages(int $days = 90, int $limit = 10): Collection
    {
        $since = now()->subDays($days);

        return User::query()
            ->where('acquisition_channel', 'organic')
            ->where('created_at', '>=', $since)
            ->whereNotNull('acquisition_landing_page')
            ->selectRaw('acquisition_landing_page, COUNT(*) as signups')
            ->groupBy('acquisition_landing_page')
            ->orderByDesc('signups')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'landing_page' => $row->acquisition_landing_page,
                'signups' => (int) $row->signups,
            ]);
    }

    /**
     * Full report for the SEO console.
     *
     * @return array<string, mixed>
     */
    public function report(int $days = 90): array
    {
        $signups = $this->signupsByChannel($days);
        $total = array_sum($signups);

        return [
            'window_days' => $days,
            'signups_by_channel' => $signups,
            'total_tracked_signups' => $total,
            'organic_signups' => $signups['organic'] ?? 0,
            'organic_share' => $total > 0 ? round(($signups['organic'] ?? 0) / $total * 100, 1) : 0.0,
            'organic_galleries' => $this->organicGalleriesCreated($days),
            'top_landing_pages' => $this->topOrganicLandingPages($days),
        ];
    }
}
