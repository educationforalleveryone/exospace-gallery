<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use AuthorizesGalleryAccess;

    public function show(Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);

        $now   = now();
        $day7  = $now->copy()->subDays(7);
        $day30 = $now->copy()->subDays(30);
        $today = $now->toDateString();

        // Historical totals from rollup (everything before today)
        $rollup = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->where('date', '<', $today)
            ->selectRaw('COALESCE(SUM(views), 0) as total_views')
            ->selectRaw('COALESCE(SUM(unique_visitors), 0) as total_unique')
            ->selectRaw('COALESCE(SUM(focuses), 0) as total_focuses')
            ->selectRaw('COALESCE(SUM(tour_starts), 0) as total_tours')
            ->selectRaw('CASE WHEN SUM(views) > 0 THEN SUM(avg_dwell_seconds * views) / SUM(views) ELSE 0 END as avg_dwell')
            ->first();

        // Today's totals from raw events
        $todayStats = $gallery->events()->whereDate('created_at', $today);
        $todayViews       = (clone $todayStats)->where('event', 'view')->count();
        $todayUnique      = (clone $todayStats)->where('event', 'view')->distinct('session_token')->count('session_token');
        $todayFocuses     = (clone $todayStats)->where('event', 'focus')->count();
        $todayTours       = (clone $todayStats)->where('event', 'tour_start')->count();
        $todayDwell       = (clone $todayStats)->where('event', 'view')->whereNotNull('dwell_seconds')->avg('dwell_seconds') ?? 0;

        // Combine rollup + today
        $totalViews     = ($rollup->total_views ?? 0) + $todayViews;
        $uniqueVisitors = ($rollup->total_unique ?? 0) + $todayUnique;
        $totalFocuses   = ($rollup->total_focuses ?? 0) + $todayFocuses;
        $tourStarts     = ($rollup->total_tours ?? 0) + $todayTours;
        $avgDwell       = $todayViews > 0
            ? (($rollup->avg_dwell ?? 0) * ($rollup->total_views ?? 0) + ($todayDwell * $todayViews)) / $totalViews
            : ($rollup->avg_dwell ?? 0);

        $rollupDays = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->where('date', '<', $today)
            ->pluck('views', 'date');

        // Fill all 30 days
        $chartDates  = [];
        $chartCounts = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $chartDates[]  = now()->subDays($i)->format('M d');
            if ($i === 0) {
                // Today — from raw events
                $chartCounts[] = $todayViews;
            } else {
                $chartCounts[] = $rollupDays[$date] ?? 0;
            }
        }

        $cacheTags = app(\App\Services\CacheTagService::class);
        $topArtworks = $cacheTags->flexibleTagged(
            ['analytics', "analytics:gallery:{$gallery->id}"],
            "analytics:top-artworks:{$gallery->id}",
            [now()->addMinutes(10), now()->addMinutes(15)],
            function () use ($gallery) {
                return $gallery->events()
                    ->where('event', 'focus')
                    ->whereNotNull('image_id')
                    ->select('image_id', DB::raw('COUNT(*) as focus_count'))
                    ->groupBy('image_id')
                    ->orderByDesc('focus_count')
                    ->with('image')
                    ->limit(10)
                    ->get();
            }
        );

        // ── Traffic sources (cached 10 min) ──────────────────────────────
        $referrers = $cacheTags->flexibleTagged(
            ['analytics', "analytics:gallery:{$gallery->id}"],
            "analytics:referrers:{$gallery->id}",
            [now()->addMinutes(10), now()->addMinutes(15)],
            function () use ($gallery) {
                return $gallery->events()
                    ->where('event', 'view')
                    ->where('created_at', '>=', now()->subDays(90))
                    ->select('referrer', DB::raw('COUNT(*) as count'))
                    ->groupBy('referrer')
                    ->orderByDesc('count')
                    ->limit(8)
                    ->get();
            }
        );

        // ── Last 7 days (days −6…−1 from rollup + today) vs prior 7 days ─
        $views7Rollup = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->whereBetween('date', [now()->subDays(6)->toDateString(), $today])
            ->where('date', '<', $today)
            ->sum('views');
        $views7 = $views7Rollup + $todayViews;

        $viewsPrev7 = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->whereBetween('date', [now()->subDays(13)->toDateString(), now()->subDays(7)->toDateString()])
            ->sum('views');
        $viewsTrend = $viewsPrev7 > 0 ? round((($views7 - $viewsPrev7) / $viewsPrev7) * 100) : null;

        return view('admin.galleries.analytics', compact(
            'gallery',
            'totalViews', 'uniqueVisitors', 'avgDwell', 'totalFocuses', 'tourStarts',
            'chartDates', 'chartCounts',
            'topArtworks', 'referrers',
            'views7', 'viewsTrend'
        ));
    }

    public function track(Request $request, Gallery $gallery)
    {
        // Mirror the public viewer's visibility rules: only exhibitions a
        // visitor can actually open accept tracking events.
        $pinVerified = ! $gallery->hasPinProtection() || session("pin_verified_{$gallery->id}");
        if (! $gallery->is_active
            || $gallery->user?->banned_at !== null
            || $gallery->hasNotOpenedYet()
            || $gallery->hasClosed()
            || ! $pinVerified) {
            return response()->json(['ok' => true]);
        }

        $consent = $request->cookie('exospace_cookie_consent');
        if ($consent === 'declined') {
            return response()->json(['ok' => true]);
        }

        $validated = $request->validate([
            'event'          => 'required|in:view,focus,tour_start,tour_complete,dwell,perf',
            'session_token'  => 'required|string|max:64',
            'image_id'       => 'nullable|integer',
            'dwell_seconds'  => 'nullable|integer|min:1|max:86400',
            'perf'           => 'nullable|array', // beacon payload — fields bounded below
            'perf.tier'      => 'nullable|in:high,mobile,low',
            'perf.q'         => 'nullable|string|max:8',
            'perf.fps'       => 'nullable|integer|min:0|max:240',
            'perf.fps_min'   => 'nullable|integer|min:0|max:240',
            'perf.draws'     => 'nullable|integer|min:0|max:10000',
            'perf.tris'      => 'nullable|integer|min:0|max:100000',
            'perf.pr'        => 'nullable|numeric|min:0.1|max:8',
            'perf.adapt'     => 'nullable|numeric|min:0.1|max:1',
            'perf.n'         => 'nullable|integer|min:0|max:10000',
            'perf.heap'      => 'nullable|integer|min:0|max:16384',
            'perf.net'       => 'nullable|string|max:8',
            'perf.ms'        => 'nullable|integer|min:0|max:3600000',
            'perf.partial'   => 'nullable|integer|in:0,1',
        ]);

        // Ignore any keys the client sends beyond the perf beacon schema —
        // the JSON column must only ever hold bounded, validated fields.
        $perf = $validated['perf'] ?? null;
        if (is_array($perf)) {
            $perf = collect($perf)->only([
                'tier', 'q', 'fps', 'fps_min', 'draws', 'tris', 'pr',
                'adapt', 'n', 'heap', 'net', 'ms', 'partial',
            ])->all();
        }

        $sessionTokenHash = hash('sha256', $validated['session_token']);

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
            AnalyticsEvent::where('gallery_id', $gallery->id)
                ->where('session_token', $sessionTokenHash)
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

            AnalyticsEvent::create([
                'gallery_id'    => $gallery->id,
                'image_id'      => $imageId,
                'event'         => $validated['event'],
                'session_token' => $sessionTokenHash,
                'referrer'      => $referrer,
                // Perf beacon payload (null for every other event)
                'perf_data'     => is_array($perf) && $perf !== [] ? $perf : null,
                'created_at'    => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
