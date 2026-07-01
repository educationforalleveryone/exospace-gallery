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

        // Dwell is fired on page unload — use sendBeacon for reliability
        if (event === 'dwell' && navigator.sendBeacon) {
            navigator.sendBeacon(url, JSON.stringify(body));
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
