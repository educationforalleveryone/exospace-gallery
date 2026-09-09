// ─────────────────────────────────────────────────────────────────────────────
// Analytics — view / focus / tour / dwell event tracker
//
// Sends events to /gallery/{gallery}/track via fetch() — including dwell and
// perf, which used to travel via navigator.sendBeacon (see below).
// ─────────────────────────────────────────────────────────────────────────────

export const Analytics = {
    _sent: new Set(),
    // Set when the backend rejects our CSRF token (419) or auth (401/403).
    // Every subsequent send this page lifetime is then dropped silently —
    // the FIRST rejection still surfaces in the network log, but we stop
    // generating a recurring error storm from a dead/expired session.
    _csrfDead: false,

    send(event, extra = {}) {
        const url = window.EXOSPACE_TRACK_URL;
        if (!url) return;
        if (this._csrfDead) return;
        const body = { event, session_token: window.EXOSPACE_SESSION, ...extra };

        // ── TRANSPORT FIX (garden-iteration-5): dwell + perf now use fetch()
        // with keepalive instead of navigator.sendBeacon. ──────────────────
        //
        // The /track endpoint is CSRF-protected (the standard 'web' middleware
        // group). fetch() sends the X-CSRF-TOKEN header from the page's meta
        // tag; sendBeacon CANNOT attach custom headers — Laravel's
        // VerifyCsrfToken saw an untokenised POST and answered 419 for EVERY
        // dwell and perf event, on EVERY visit (the engagement-time and
        // performance analytics were silently lost in production, and each
        // failure printed a console error).
        //
        // fetch(..., { keepalive: true }) is the header-capable equivalent of
        // sendBeacon: the request survives page unload (beforeunload /
        // pagehide / visibilitychange→hidden) with the same reliability
        // guarantees for our ~200-byte JSON body, while carrying the CSRF
        // header the endpoint requires. No CSRF exemption is created —
        // the protection stays intact end to end.
        //
        // (History — PERF-F31/ITERATION-1: sendBeacon with a plain string
        // body sent Content-Type: text/plain and Laravel returned 422 for
        // the empty payload; wrapping the JSON in a Blob fixed the Content-
        // Type. That fix is superseded by this transport change, which
        // solves BOTH the Content-Type and the missing CSRF header.)
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(body),
            keepalive: true,
            // Self-healing backoff: if the session outlived its CSRF token
            // (page open longer than the session lifetime), the FIRST 419
            // arms the dead-session flag and further events are dropped
            // client-side instead of hammering the endpoint with rejections.
        }).then(res => {
            if (res && (res.status === 419 || res.status === 401 || res.status === 403)) {
                this._csrfDead = true;
            }
        }).catch(() => {});
    },

    // Optional response hook — called by boot code that awaits responses.
    // Not required for operation; kept so future senders can classify
    // failures consistently.
    noteResponseStatus(status) {
        if (status === 419 || status === 401 || status === 403) this._csrfDead = true;
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
