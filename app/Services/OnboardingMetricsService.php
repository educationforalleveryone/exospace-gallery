<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ITERATION 4 — onboarding funnel + TTFE metrics, shared source of truth.
 *
 * History: this logic lived only inside the weekly exospace:onboarding-analytics
 * console command (console output + a log line), so the product's headline
 * metric — time-to-first-exhibition — was invisible between Monday reports.
 * This service extracts it for continuous display on the Master Control
 * dashboard while keeping the command as a thin consumer, so the two can
 * never drift apart.
 *
 * Scale notes (why these query shapes):
 *   - Funnel stages aggregate in SQL (whereExists subqueries) — one count
 *     query per stage, bounded work at any table size.
 *   - TTFE/TTFG select per-user FIRST-event rows (one row per user who
 *     published / created a gallery in the window — publishers, not all
 *     users) and compute min/avg/max in PHP with portable Carbon math.
 *     This mirrors the Iteration-3 fix (no MySQL-only TIMESTAMPDIFF) and
 *     avoids loading user×gallery cartesian rows like the command's old
 *     join-everything diffStats did.
 *   - Everything is wrapped in Cache::flexible (30/60 min) — the numbers
 *     move on publish events, not on dashboard refreshes, and the Master
 *     Control page is not a hot path worth 10 queries for.
 */
class OnboardingMetricsService
{
    /**
     * @param  int  $days  lookback window for the registered cohort
     * @return array{
     *     days: int,
     *     registered: int, created_gallery: int, uploaded_image: int,
     *     published: int, got_views: int,
     *     ttfg_hours: ?array{min: float, avg: float, max: float},
     *     ttfe_hours: ?array{min: float, avg: float, max: float}
     * }
     */
    public function snapshot(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::flexible(
            "onboarding:metrics:{$days}",
            [now()->addMinutes(30), now()->addMinutes(60)],
            fn () => $this->compute($days),
        );
    }

    /**
     * Uncached computation — command consumers that want fresh numbers
     * (weekly report) call this directly.
     *
     * @return array<string, mixed>
     */
    public function compute(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = now()->subDays($days);

        $registered = User::where('created_at', '>=', $cutoff)->count();

        $createdGallery = User::where('created_at', '>=', $cutoff)
            ->whereHas('galleries')
            ->count();

        $uploadedImage = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('gallery_images')
                    ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->whereNull('galleries.deleted_at')
                    ->whereNull('gallery_images.deleted_at');
            })
            ->count();

        $published = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.is_active', true)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();

        $gotViews = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.view_count', '>', 0)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();

        return [
            'days'             => $days,
            'registered'       => $registered,
            'created_gallery'  => $createdGallery,
            'uploaded_image'   => $uploadedImage,
            'published'        => $published,
            'got_views'        => $gotViews,
            'ttfg_hours'       => $this->firstEventDiffHours($cutoff, 'galleries.created_at', true),
            'ttfe_hours'       => $this->firstEventDiffHours($cutoff, 'galleries.published_at', true),
        ];
    }

    /**
     * Per-user FIRST event timing (hours) for users registered since the
     * cutoff. SQL aggregates the per-user MIN(event column) — the result set
     * is one row per acting user, not one row per event — and the hour math
     * happens in PHP (portable; see Iteration-3 TIMESTAMPDIFF removal).
     *
     * @param  string  $eventColumn  qualified column inside galleries
     * @param  bool  $requireNotNull  skip rows where the event never happened
     * @return null|array{min: float, avg: float, max: float}
     */
    private function firstEventDiffHours(\DateTimeInterface $cutoff, string $eventColumn, bool $requireNotNull): ?array
    {
        $rows = DB::table('users')
            ->join('galleries', 'galleries.user_id', '=', 'users.id')
            ->where('users.created_at', '>=', $cutoff)
            ->whereNull('galleries.deleted_at')
            ->when($requireNotNull, fn ($q) => $q->whereNotNull($eventColumn))
            ->selectRaw("users.id, users.created_at as user_created_at, MIN({$eventColumn}) as event_at")
            ->groupBy('users.id', 'users.created_at')
            ->get();

        $hours = [];
        foreach ($rows as $row) {
            if (! $row->event_at || ! $row->user_created_at) {
                continue;
            }
            $diff = \Carbon\Carbon::parse($row->user_created_at)
                ->diffInHours(\Carbon\Carbon::parse($row->event_at), false);
            if ($diff >= 0) {
                $hours[] = $diff;
            }
        }

        if ($hours === []) {
            return null;
        }

        return [
            'min' => round(min($hours), 1),
            'avg' => round(array_sum($hours) / count($hours), 1),
            'max' => round(max($hours), 1),
        ];
    }
}
