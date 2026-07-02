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
