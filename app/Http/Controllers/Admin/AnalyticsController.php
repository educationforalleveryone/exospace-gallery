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

    /**
     * Show the analytics dashboard for a gallery.
     */
    public function show(Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);

        $now   = now();
        $day7  = $now->copy()->subDays(7);
        $day30 = $now->copy()->subDays(30);
        $today = $now->toDateString();

        // ── Overview stats (Task H33) ─────────────────────────────────────
        // Use analytics_daily for historical data (fast — pre-aggregated)
        // and analytics_events for today's data (fresh — not yet rolled up).
        // This avoids running 7+ COUNT/DISTINCT/AVG queries against a
        // potentially multi-million-row raw events table.

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

        // ── Views over last 30 days (Task H33) ────────────────────────────
        // Read from analytics_daily for days 1-29 (already rolled up),
        // then from raw events for today (not yet rolled up).
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

        // ── Top artworks by focus count (Task H66 — cached 10 min) ───────
        // P2-20: Using Cache::flexible() for stampede protection — serves
        // stale data for up to 5 min while a single worker regenerates.
        // P3-16: Now tagged with ['analytics', "analytics:gallery:{$gallery->id}"]
        // so the cache can be bulk-invalidated when the gallery is updated or
        // when RollupAnalytics runs.
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

        // ── Traffic sources (Task H66 — cached 10 min) ───────────────────
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

        // ── Last 7 days vs prior 7 days (from rollup + today) ────────────
        $views7Rollup = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->whereBetween('date', [now()->subDays(7)->toDateString(), $today])
            ->where('date', '<', $today)
            ->sum('views');
        $views7 = $views7Rollup + $todayViews;

        $viewsPrev7 = DB::table('analytics_daily')
            ->where('gallery_id', $gallery->id)
            ->whereBetween('date', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
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

    /**
     * Receive an analytics event from the 3D viewer (public, rate-limited).
     *
     * (Task H06 / audit H12) — hardened:
     *   - session_token is hashed (SHA-256) before storage so a DB leak
     *     doesn't expose a visitor's full viewing history. The hash is
     *     still joinable on `distinct('session_token')` for unique-visitor
     *     counts.
     *   - Cookie-consent gating: if the request carries an
     *     `exospace_cookie_consent=declined` cookie, the event is silently
     *     dropped (audit H7). The frontend cookie banner sets this cookie.
     *   - Lower throttle: 30/min/IP (was 120). The route-level throttle
     *     was already applied in routes/web.php; this method doesn't
     *     duplicate it.
     *
     * NOTE on session_token signing: a fully signed session_token (HMAC)
     * would be the strongest fix for inflation attacks, but it requires
     * the 3D viewer to obtain the token from the server on each gallery
     * load. That's a larger refactor (the viewer currently generates the
     * token client-side via crypto.randomUUID). For now, the rate limit
     * + cookie-consent gating + IP-based throttling is the pragmatic
     * mitigation. A signed-token refactor is noted as follow-up work.
     */
    public function track(Request $request, Gallery $gallery)
    {
        // Silently ignore if gallery doesn't exist or inactive
        if (!$gallery->is_active) return response()->json(['ok' => true]);

        // ── Cookie-consent gate (audit H7) ────────────────────────────────
        // If the visitor declined cookies via the cookie banner, drop the
        // event. The frontend cookie banner sets `exospace_cookie_consent`
        // to either 'accepted' or 'declined'.
        $consent = $request->cookie('exospace_cookie_consent');
        if ($consent === 'declined') {
            return response()->json(['ok' => true]);
        }

        $validated = $request->validate([
            'event'          => 'required|in:view,focus,tour_start,tour_complete,dwell',
            'session_token'  => 'required|string|max:64',
            'image_id'       => 'nullable|integer',
            'dwell_seconds'  => 'nullable|integer|min:1|max:86400',
        ]);

        // ── Hash session_token before storage (audit M9) ──────────────────
        // The raw session_token identifies a unique visitor across page
        // loads. Storing it in plaintext means a DB leak exposes a
        // visitor's entire viewing history. Hashing with SHA-256 still
        // allows `distinct('session_token')` joins for unique-visitor
        // counts, but makes the token non-reversible.
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
                'created_at'    => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
