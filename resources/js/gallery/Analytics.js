// ─────────────────────────────────────────────────────────────────────────────
// Analytics — view / focus / tour / dwell event tracker
//
// Sends events to /gallery/{gallery}/track via fetch() (or sendBeacon for
// dwell, which fires reliably on page unload).
// ─────────────────────────────────────────────────────────────────────────────

export const Analytics = {
    _sent: new Set(),

    send(event, extra = {}) {
        const url = window.EXOSPACE_TRACK_URL;
        if (!url) return;
        const body = { event, session_token: window.EXOSPACE_SESSION, ...extra };

        // Dwell (page unload) and perf (partial sample flush on pagehide)
        // fire at navigation time — sendBeacon is the reliable transport.
        //
        // ITERATION-1 FIX (silent analytics loss): sendBeacon with a plain
        // string body sends Content-Type: text/plain — Laravel only parses
        // application/json bodies into $request->input(), so the server-side
        // validate() saw an EMPTY payload and returned 422 for every dwell
        // and perf event (engagement time + the PERF-F31 performance beacon
        // were silently discarded). Wrapping the JSON in a Blob with an
        // explicit type makes sendBeacon send the right Content-Type.
        if ((event === 'dwell' || event === 'perf') && navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([JSON.stringify(body)], { type: 'application/json' }));
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(body),
            keepalive: true,
        }).catch(() => {});
    },

    trackView() {
        if (this._sent.has('view')) return;
        this._sent.add('view');
        this.send('view');
        this._startDwell();
    },

    trackFocus(imageId) {
        this.send('focus', { image_id: imageId });
    },

    trackTourStart() {
        if (this._sent.has('tour_start')) return;
        this._sent.add('tour_start');
        this.send('tour_start');
    },

    trackTourComplete() {
        this.send('tour_complete');
    },

    _startDwell() {
        const entered = Date.now();
        const flush = () => {
            const secs = Math.round((Date.now() - entered) / 1000);
            if (secs >= 3) this.send('dwell', { dwell_seconds: secs });
        };
        window.addEventListener('beforeunload', flush);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) flush();
        });
    },
};
