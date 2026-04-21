<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\GalleryEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Show the analytics dashboard for a gallery.
     */
    public function show(Gallery $gallery)
    {
        if ($gallery->user_id !== Auth::id()) abort(403);

        $now   = now();
        $day7  = $now->copy()->subDays(7);
        $day30 = $now->copy()->subDays(30);

        // ── Overview stats ────────────────────────────────────────────────
        $totalViews   = $gallery->events()->where('event', 'view')->count();
        $uniqueVisitors = $gallery->events()->where('event', 'view')
            ->distinct('session_token')->count('session_token');
        $avgDwell     = $gallery->events()->where('event', 'view')
            ->whereNotNull('dwell_seconds')->avg('dwell_seconds');
        $totalFocuses = $gallery->events()->where('event', 'focus')->count();
        $tourStarts   = $gallery->events()->where('event', 'tour_start')->count();

        // ── Views over last 30 days ───────────────────────────────────────
        $viewsByDay = $gallery->events()
            ->where('event', 'view')
            ->where('created_at', '>=', $day30)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill all 30 days (zeros for missing dates)
        $chartDates  = [];
        $chartCounts = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $chartDates[]  = now()->subDays($i)->format('M d');
            $chartCounts[] = $viewsByDay[$date]->count ?? 0;
        }

        // ── Top artworks by focus count ───────────────────────────────────
        $topArtworks = $gallery->events()
            ->where('event', 'focus')
            ->whereNotNull('image_id')
            ->select('image_id', DB::raw('COUNT(*) as focus_count'))
            ->groupBy('image_id')
            ->orderByDesc('focus_count')
            ->with('image')
            ->limit(10)
            ->get();

        // ── Traffic sources ───────────────────────────────────────────────
        $referrers = $gallery->events()
            ->where('event', 'view')
            ->select('referrer', DB::raw('COUNT(*) as count'))
            ->groupBy('referrer')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // ── Last 7 days vs prior 7 days (for trend indicators) ────────────
        $views7     = $gallery->events()->where('event', 'view')->where('created_at', '>=', $day7)->count();
        $viewsPrev7 = $gallery->events()->where('event', 'view')
            ->whereBetween('created_at', [$now->copy()->subDays(14), $day7])->count();
        $viewsTrend = $viewsPrev7 > 0 ? round((($views7 - $viewsPrev7) / $viewsPrev7) * 100) : null;

        return view('admin.galleries.analytics', compact(
            'gallery',
            'totalViews', 'uniqueVisitors', 'avgDwell', 'totalFocuses', 'tourStarts',
            'chartDates', 'chartCounts',
            'topArtworks', 'referrers',
            'views7', 'viewsTrend'
        ));
    }

    /**
     * Receive an analytics event from the 3D viewer (public, rate-limited).
     */
    public function track(Request $request, Gallery $gallery)
    {
        // Silently ignore if gallery doesn't exist or inactive
        if (!$gallery->is_active) return response()->json(['ok' => true]);

        $validated = $request->validate([
            'event'          => 'required|in:view,focus,tour_start,tour_complete,dwell',
            'session_token'  => 'required|string|max:64',
            'image_id'       => 'nullable|integer',
            'dwell_seconds'  => 'nullable|integer|min:1|max:86400',
        ]);

        // Parse referrer from the request header
        $referrer = $request->header('Referer') ?? null;
        if ($referrer) {
            $host = parse_url($referrer, PHP_URL_HOST) ?: 'direct';
            // Strip www.
            $referrer = preg_replace('/^www\./', '', $host);
        } else {
            $referrer = 'direct';
        }

        if ($validated['event'] === 'dwell') {
            // Update dwell on the most recent view event for this session
            GalleryEvent::where('gallery_id', $gallery->id)
                ->where('session_token', $validated['session_token'])
                ->where('event', 'view')
                ->latest('created_at')
                ->limit(1)
                ->update(['dwell_seconds' => $validated['dwell_seconds']]);
        } else {
            // Validate image_id belongs to this gallery (security)
            $imageId = null;
            if (!empty($validated['image_id'])) {
                $imageId = $gallery->images()->where('id', $validated['image_id'])->value('id');
            }

            GalleryEvent::create([
                'gallery_id'    => $gallery->id,
                'image_id'      => $imageId,
                'event'         => $validated['event'],
                'session_token' => $validated['session_token'],
                'referrer'      => $referrer,
                'created_at'    => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}