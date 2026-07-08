// ─────────────────────────────────────────────────────────────────────────────
// Exospace Service Worker (Task H46 / audit MX5)
//
// Provides offline caching for the Exospace gallery experience:
//   1. App shell (CSS, JS, fonts) — cached on install
//   2. Gallery pages — stale-while-revalidate (instant load from cache,
//      then update in background)
//   3. Static assets (favicons, OG images) — cache-first
//
// This is a progressive enhancement — if the service worker fails to
// register, the site works normally online. The SW only caches GET
// requests; POST requests (analytics tracking, newsletter signup) are
// always sent to the network.
//
// Cache versioning: increment EXOSPACE_SW_VERSION on every deploy to
// trigger cache invalidation.
// ─────────────────────────────────────────────────────────────────────────────

const EXOSPACE_SW_VERSION = 'v1';
const CACHE_NAME = `exospace-${EXOSPACE_SW_VERSION}`;

// App shell — the essential assets for the gallery experience
// C-9 FIX (Iter-005): Removed the literal '/build/assets/app.css' path.
//
// The actual built CSS is content-hashed (e.g. assets/app-_q6yfwqq.css)
// per the Vite manifest. The literal path /build/assets/app.css 404s —
// the SW was pre-caching a 404 response, then serving it from cache on
// the next visit, breaking styles site-wide.
//
// The runtime caching strategy (Strategy 2 below) already caches /build/*
// assets on-demand with stale-while-revalidate. That handles CSS, JS, and
// other build artifacts correctly — no need to pre-cache them.
//
// Only pre-cache HTML routes (which have stable URLs).
const APP_SHELL = [
    '/',
    '/discover',
    '/pricing',
    // CSS/JS are cached on-demand via runtime caching (Strategy 2 below)
];

// ── Install: pre-cache the app shell ───────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting())
            .catch(() => {
                // If any shell asset fails, don't block installation —
                // the SW will still work with runtime caching.
            })
    );
});

// ── Activate: clean up old caches ──────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('exospace-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

// ── Fetch: routing strategy ────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only handle GET requests
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Skip cross-origin requests (fonts from Bunny, etc. — they have their
    // own caching headers)
    if (url.origin !== self.location.origin) return;

    // Skip analytics tracking endpoints (always hit network)
    if (url.pathname.includes('/track')) return;

    // Skip webhook endpoints
    if (url.pathname.includes('/webhooks/')) return;

    // ── Strategy 1: Cache-first for static assets ─────────────────────────
    // Favicons, OG images, manifest
    if (url.pathname.match(/\.(png|ico|svg|webmanifest)$/)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                return cached || fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // ── Strategy 2: Stale-while-revalidate for build assets ────────────────
    // CSS/JS chunks — serve from cache instantly, update in background
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const fetchPromise = fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached); // network failed — return cached
                return cached || fetchPromise;
            })
        );
        return;
    }

    // ── Strategy 3: Network-first for HTML pages ───────────────────────────
    // Gallery pages, admin pages, etc. — try network first (fresh content),
    // fall back to cache if offline
    if (request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(request))
        );
        return;
    }

    // ── Strategy 4: Cache-first for uploaded images ────────────────────────
    // Gallery artwork images, logos, audio — cache on first access
    if (url.pathname.startsWith('/storage/') || url.pathname.startsWith('/assets/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                return cached || fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached);
            })
        );
        return;
    }
});
