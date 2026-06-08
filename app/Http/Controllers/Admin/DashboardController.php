<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\GalleryEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        $team = $user->currentTeam();

        // ── Gallery counts scoped to team or personal ──────────────────
        $personalGalleriesCount = DB::table('galleries')
            ->where('user_id', $user->id)
            ->whereNull('team_id')
            ->count();

        $teamGalleriesCount = $team
            ? DB::table('galleries')->where('team_id', $team->id)->count()
            : 0;

        // Active scope for stats
        $galleriesScope = $team
            ? Gallery::where('team_id', $team->id)
            : Gallery::where('user_id', $user->id)->whereNull('team_id');

        $galleriesCount = (clone $galleriesScope)->count();
        $totalViews     = (clone $galleriesScope)->sum('view_count');
        $activeCount    = (clone $galleriesScope)->where('is_active', true)->count();
        $draftCount     = $galleriesCount - $activeCount;

        // ── Top gallery by views ────────────────────────────────────────
        $topGallery = (clone $galleriesScope)
            ->where('view_count', '>', 0)
            ->orderByDesc('view_count')
            ->first();

        // ── Recent galleries (last 5) ───────────────────────────────────
        $recentGalleries = (clone $galleriesScope)
            ->withCount('images')
            ->latest()
            ->take(5)
            ->get();

        // ── Views last 7 days (across scoped galleries) ─────────────────
        $galleryIds = (clone $galleriesScope)->pluck('id');

        $viewsLast7 = GalleryEvent::whereIn('gallery_id', $galleryIds)
            ->where('event', 'view')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        // Fill missing days with 0
        $viewsChart = collect(range(6, 0))->mapWithKeys(function ($daysAgo) use ($viewsLast7) {
            $date  = now()->subDays($daysAgo)->toDateString();
            $label = now()->subDays($daysAgo)->format('D');
            return [$label => $viewsLast7->get($date, 0)];
        });

        // ── Onboarding state ────────────────────────────────────────────
        $onboardingComplete = ($galleriesCount > 0 && $totalViews > 0);

        // ── Quota (personal only — team galleries don't consume personal quota) ──
        $galleryQuotaPercent = $user->max_galleries > 0
            ? min(100, round(($personalGalleriesCount / $user->max_galleries) * 100))
            : 100;

        return view('admin.dashboard', compact(
            'user',
            'team',
            'galleriesCount',
            'totalViews',
            'activeCount',
            'draftCount',
            'topGallery',
            'recentGalleries',
            'viewsChart',
            'onboardingComplete',
            'personalGalleriesCount',
            'galleryQuotaPercent',
        ));
    }
}
