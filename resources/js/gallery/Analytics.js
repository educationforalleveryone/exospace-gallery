export const Analytics = {
    _sent: new Set(),
    _csrfDead: false,

    send(event, extra = {}) {
        const url = window.EXOSPACE_TRACK_URL;
        if (!url) return;
        if (this._csrfDead) return;
        const body = { event, session_token: window.EXOSPACE_SESSION, ...extra };

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(body),
            keepalive: true,
        }).then(res => {
            if (res && (res.status === 419 || res.status === 401 || res.status === 403)) {
                this._csrfDead = true;
            }
        }).catch(() => {});
    },

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
