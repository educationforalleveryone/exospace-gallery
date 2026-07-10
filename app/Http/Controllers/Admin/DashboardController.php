<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use App\Models\TeamInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        // PERF-14: Eager-load currentTeamRelationship to avoid the N+1 query
        // that the legacy currentTeam() method form would issue. The legacy
        // method still works (it returns the loaded relation from the cache),
        // but this way we don't pay the query cost on the first access.
        $user->loadMissing('currentTeamRelationship');
        $team = $user->current_team_id ? $user->currentTeamRelationship : null;

        // ── Gallery scope ───────────────────────────────────────────────────
        $galleriesScope = $team
            ? Gallery::where('team_id', $team->id)
            : Gallery::where('user_id', $user->id)->whereNull('team_id');

        $galleriesCount = (clone $galleriesScope)->count();
        $activeCount    = (clone $galleriesScope)->where('is_active', true)->count();
        $draftCount     = $galleriesCount - $activeCount;
        $totalViews     = (clone $galleriesScope)->sum('view_count');

        $personalGalleriesCount = $team ? null : $galleriesCount;
        $galleryQuotaPercent    = (!$team && $user->max_galleries > 0)
            ? min(100, (int) round(($galleriesCount / $user->max_galleries) * 100))
            : 0;

        // ── Recent galleries (with image count eager-loaded) ────────────────
        $recentGalleries = (clone $galleriesScope)
            ->withCount('images')
            ->with('coverImage')
            ->latest()
            ->take(6)
            ->get();

        $topGallery = (clone $galleriesScope)
            ->where('view_count', '>', 0)
            ->orderByDesc('view_count')
            ->withCount('images')
            ->with('coverImage')
            ->first();

        // ── Analytics: views last 7 days + prior 7 days (for trend) ─────────
        // FIXED (Round 4): uses AnalyticsEvent instead of the deleted GalleryEvent.
        // The table was renamed from gallery_events to analytics_events by
        // migration 2026_06_22_000001.
        //
        // E-2 FIX (Iter-011): Mirrors the AnalyticsController::show pattern —
        // read from analytics_daily for days 1-6 (pre-aggregated, fast) and
        // raw analytics_events ONLY for today (not yet rolled up). Previously
        // every dashboard load ran 3 COUNT queries + 1 DATE() GROUP BY query
        // against the raw events table — slow at scale (1000+ galleries ×
        // 100+ views/day × 7 days = 700k+ rows scanned per dashboard load).
        //
        // Also cached for 5 minutes via Cache::flexible — the dashboard is
        // the most-visited admin page; making it the slowest was backwards.
        $galleryIds = (clone $galleriesScope)->pluck('id');

        $viewsToday = 0;
        $views7     = 0;
        $viewsPrev7 = 0;
        $viewsChart = collect();

        if ($galleryIds->isNotEmpty()) {
            $now    = now();
            $today  = $now->toDateString();
            $day7   = $now->copy()->subDays(7)->toDateString();
            $day14  = $now->copy()->subDays(14)->toDateString();

            $cacheKey = "dashboard:analytics:u{$user->id}:" . ($team ? "t{$team->id}" : 'personal');

            $cached = \Illuminate\Support\Facades\Cache::flexible($cacheKey, [now()->addMinutes(5), now()->addMinutes(10)], function () use ($galleryIds, $now, $today, $day7, $day14) {
                // Today's views from raw events (today is not yet in the rollup).
                $viewsToday = AnalyticsEvent::whereIn('gallery_id', $galleryIds)
                    ->where('event', 'view')
                    ->whereDate('created_at', $today)
                    ->count();

                // Last 7 days from rollup (days 1-6) + today from raw events.
                $views7Rollup = DB::table('analytics_daily')
                    ->whereIn('gallery_id', $galleryIds)
                    ->where('date', '>=', $day7)
                    ->where('date', '<', $today)
                    ->sum('views');
                $views7 = $views7Rollup + $viewsToday;

                // Prior 7 days from rollup.
                $viewsPrev7 = DB::table('analytics_daily')
                    ->whereIn('gallery_id', $galleryIds)
                    ->whereBetween('date', [$day14, $day7])
                    ->sum('views');

                // 7-day chart: 6 days from rollup + today from raw events.
                $rollupDays = DB::table('analytics_daily')
                    ->whereIn('gallery_id', $galleryIds)
                    ->where('date', '>=', $now->copy()->subDays(6)->toDateString())
                    ->where('date', '<', $today)
                    ->selectRaw('date, SUM(views) as views')
                    ->groupBy('date')
                    ->pluck('views', 'date');

                $viewsChart = collect(range(6, 0))->mapWithKeys(function ($d) use ($rollupDays, $now, $viewsToday) {
                    $date  = $now->copy()->subDays($d)->toDateString();
                    $label = $now->copy()->subDays($d)->format('D');
                    $count = $d === 0 ? $viewsToday : (int) ($rollupDays[$date] ?? 0);
                    return [$label => $count];
                });

                return compact('viewsToday', 'views7', 'viewsPrev7', 'viewsChart');
            });

            $viewsToday = $cached['viewsToday'];
            $views7     = $cached['views7'];
            $viewsPrev7 = $cached['viewsPrev7'];
            $viewsChart = collect($cached['viewsChart']);
        }

        $viewsTrend = $viewsPrev7 > 0
            ? (int) round((($views7 - $viewsPrev7) / $viewsPrev7) * 100)
            : ($views7 > 0 ? 100 : null);

        // ── Contextual alerts ────────────────────────────────────────────────
        $alerts = $this->buildAlerts($user, $team, $recentGalleries, $galleryQuotaPercent);

        // ── Pending team invitations (for teams the user owns) ───────────────
        $pendingInvitations = collect();
        if (!$team) {
            $ownedTeamIds = $user->ownedTeams()->pluck('id');
            if ($ownedTeamIds->isNotEmpty()) {
                $pendingInvitations = TeamInvitation::whereIn('team_id', $ownedTeamIds)
                    ->where('expires_at', '>', now())
                    ->with('team')
                    ->latest()
                    ->take(5)
                    ->get();
            }
        }

        // ── Activation flags for onboarding UX ──────────────────────────────
        $isNewUser          = !$team && $galleriesCount === 0 && $user->created_at->gt(now()->subHours(48));
        $hasUnsharedGallery = !$team && $galleriesCount > 0 && $totalViews === 0 && $activeCount > 0;

        // (Task H49) — onboarding checklist data
        $totalImages = !$team ? \DB::table('gallery_images')
            ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
            ->where('galleries.user_id', $user->id)
            ->whereNull('galleries.deleted_at')
            ->whereNull('gallery_images.deleted_at')
            ->count() : 0;
        $hasPublishedGallery = !$team && $activeCount > 0;

        // ── Gallery health flags (for recent list) ───────────────────────────
        $staleLiveIds = (clone $galleriesScope)
            ->where('is_active', true)
            ->where('view_count', 0)
            ->where('created_at', '<=', now()->subDays(3))
            ->pluck('id')
            ->flip();

        return view('admin.dashboard', compact(
            'user',
            'team',
            'galleriesCount',
            'totalViews',
            'isNewUser',
            'hasUnsharedGallery',
            'viewsToday',
            'views7',
            'viewsTrend',
            'activeCount',
            'draftCount',
            'topGallery',
            'recentGalleries',
            'viewsChart',
            'personalGalleriesCount',
            'galleryQuotaPercent',
            'alerts',
            'pendingInvitations',
            'staleLiveIds',
            'totalImages',
            'hasPublishedGallery',
        ));
    }

    private function buildAlerts($user, $team, Collection $galleries, int $quotaPercent): array
    {
        $alerts = [];

        // Plan expiry warning (7-day window)
        if (!$team && $user->plan_expires_at) {
            $daysLeft = now()->diffInDays($user->plan_expires_at, false);
            if ($daysLeft >= 0 && $daysLeft <= 7) {
                $alerts[] = [
                    'type'   => 'warning',
                    'icon'   => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                    'text'   => $daysLeft === 0
                        ? 'Your plan expires today.'
                        : "Your plan expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . '.',
                    'action' => ['label' => 'Renew now', 'href' => '/pricing'],
                ];
            }
        }

        // Quota near-full (free users, ≥80%)
        if (!$team && !$user->isPro() && $quotaPercent >= 80) {
            $alerts[] = [
                'type'   => $quotaPercent >= 100 ? 'error' : 'warning',
                'icon'   => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                'text'   => $quotaPercent >= 100
                    ? 'You\'ve reached your gallery limit.'
                    : "You've used {$quotaPercent}% of your gallery quota.",
                'action' => ['label' => 'Upgrade to Pro', 'href' => '/pricing'],
            ];
        }

        // Draft galleries that have never been published (idle > 7 days)
        $staleDrafts = $galleries->filter(fn($g) =>
            !$g->is_active &&
            $g->created_at->lt(now()->subDays(7))
        )->count();

        if ($staleDrafts > 0) {
            $alerts[] = [
                'type'   => 'info',
                'icon'   => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
                'text'   => $staleDrafts === 1
                    ? 'You have a draft gallery sitting unpublished.'
                    : "You have {$staleDrafts} draft galleries sitting unpublished.",
                'action' => ['label' => 'Go to Galleries', 'href' => route('admin.galleries.index')],
            ];
        }

        // Live galleries with 0 images
        $emptyLive = $galleries->filter(fn($g) =>
            $g->is_active && $g->images_count === 0
        )->count();

        if ($emptyLive > 0) {
            $alerts[] = [
                'type'   => 'error',
                'icon'   => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                'text'   => $emptyLive === 1
                    ? 'A live gallery has no images — visitors see an empty exhibition.'
                    : "{$emptyLive} live galleries have no images.",
                'action' => ['label' => 'Fix now', 'href' => route('admin.galleries.index')],
            ];
        }

        return $alerts;
    }
}