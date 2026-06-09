<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\GalleryEvent;
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
        $team = $user->currentTeam();

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
        $galleryIds = (clone $galleriesScope)->pluck('id');

        $viewsToday = 0;
        $views7     = 0;
        $viewsPrev7 = 0;
        $viewsChart = collect();

        if ($galleryIds->isNotEmpty()) {
            $now    = now();
            $day0   = $now->copy()->startOfDay();
            $day7   = $now->copy()->subDays(7);
            $day14  = $now->copy()->subDays(14);

            $viewsToday = GalleryEvent::whereIn('gallery_id', $galleryIds)
                ->where('event', 'view')
                ->where('created_at', '>=', $day0)
                ->count();

            $views7 = GalleryEvent::whereIn('gallery_id', $galleryIds)
                ->where('event', 'view')
                ->where('created_at', '>=', $day7)
                ->count();

            $viewsPrev7 = GalleryEvent::whereIn('gallery_id', $galleryIds)
                ->where('event', 'view')
                ->whereBetween('created_at', [$day14, $day7])
                ->count();

            $rawChart = GalleryEvent::whereIn('gallery_id', $galleryIds)
                ->where('event', 'view')
                ->where('created_at', '>=', $now->copy()->subDays(6)->startOfDay())
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->pluck('count', 'date');

            $viewsChart = collect(range(6, 0))->mapWithKeys(function ($d) use ($rawChart, $now) {
                $date  = $now->copy()->subDays($d)->toDateString();
                $label = $now->copy()->subDays($d)->format('D');
                return [$label => (int) ($rawChart->get($date, 0))];
            });
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

        // ── Gallery health flags (for recent list) ───────────────────────────
        // Flag galleries that are live but have 0 views after 3+ days
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
