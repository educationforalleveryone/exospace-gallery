@php
    /**
     * Admin Live Preview iframe target.
     *
     * Loaded by /admin/galleries/{gallery}/preview. Renders the same Three.js
     * gallery as the public view, but:
     *   - No entrance curtain (auto-enters on load)
     *   - No PIN gate / time-gate / view-count bump
     *   - Hides newsletter + share UI (curator preview, not a visitor session)
     *   - Loads an extra <script nonce="@nonce"> block that listens for postMessage patches
     *     from the parent window and forwards them to GalleryScene.applyLiveOverride()
     *
     * The parent window (admin/galleries/edit page) sends two message types:
     *   { type: 'exospace-preview-patch', patch: { visual_config: {...}, ... } }
     *     → live, no reload. Used for sliders like ambient_intensity, fog, etc.
     *   { type: 'exospace-preview-reload', overrides: {...} }
     *     → reloads the iframe with ?override=<base64> so structural changes
     *       (wall_height, room_layout) take effect.
     *
     * The blade is intentionally minimal — it reuses the public view's
     * window.GALLERY_DATA shape so GalleryScene doesn't need a separate
     * preview code path.
     */
    $galleryData['isPreview'] = true;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Preview: {{ $gallery->title }}</title>

    @vite(['resources/css/app.css', 'resources/js/gallery/main.js'])

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            margin: 0; overflow: hidden; background-color: #000;
            font-family: system-ui, -apple-system, sans-serif;
        }
        #canvas-container { width: 100vw; height: 100vh; }
        #canvas-container canvas { display: block; }

        /* Preview-mode badge — visible top-right so it's obvious this is a
           curator preview, not the public view. */
        #preview-badge {
            position: fixed; top: 12px; right: 12px;
            background: rgba(139, 92, 246, 0.85);
            color: #fff; font-size: 11px; font-weight: 600;
            padding: 4px 10px; border-radius: 999px;
            letter-spacing: 0.04em; text-transform: uppercase;
            z-index: 30; pointer-events: none;
            backdrop-filter: blur(4px);
        }

        /* Hide the entrance curtain entirely — preview auto-enters */
        #entrance-curtain { display: none !important; }

        /* Hide visitor-only UI that doesn't make sense in preview */
        #newsletter-form, #share-btn, #events-link { display: none !important; }

        /* Compact loading bar so the curator sees the scene is still booting.
           ITERATION-5: z-index 999 (magic number) → z-[60], the ladder's
           full-screen overlay tier — covers site chrome (z-40) and the
           badge (z-30) during boot, sits under toasts/command palette. */
        #preview-loading {
            position: fixed; inset: 0;
            background: #0a0a0a;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            z-index: 60; transition: opacity 0.4s ease;
        }
        #preview-loading.hidden { opacity: 0; pointer-events: none; }
        #preview-loading-bar {
            width: 240px; height: 3px;
            background: rgba(255,255,255,0.08);
            border-radius: 999px; overflow: hidden;
            margin-top: 16px;
        }
        #preview-loading-fill {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, #8b5cf6, #6366f1);
            transition: width 0.2s ease;
        }
        #preview-loading-text {
            color: #9ca3af; font-size: 12px; margin-top: 12px;
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
    <div id="preview-badge">Live Preview</div>

    <div id="preview-loading">
        <div style="color:#e5e7eb; font-size:14px; font-weight:600;">Building 3D scene…</div>
        <div id="preview-loading-bar"><div id="preview-loading-fill"></div></div>
        <div id="preview-loading-text">Loading textures, geometry, and artworks</div>
    </div>

    <div id="canvas-container"></div>

    {{-- Pass gallery data to the 3D viewer.
        Same shape as view.blade.php so GalleryScene doesn't need a special path. --}}
    <script nonce="@nonce">
        window.GALLERY_DATA = @json($galleryData);
        window.EXOSPACE_PREVIEW_MODE = true;
    </script>

    {{-- Preview client: listens for postMessage patches from the parent
        window and forwards them to the running GalleryScene. --}}
    <script nonce="@nonce">
    (function () {
        const ALLOWED_ORIGIN = window.location.origin;

        function getScene() {
            return window.__exospace?.scene ?? null;
        }

        function applyPatch(patch) {
            const scene = getScene();
            if (!scene) return false;
            if (typeof scene.applyLiveOverride !== 'function') return false;
            try {
                scene.applyLiveOverride(patch);
                return true;
            } catch (err) {
                console.error('[PreviewClient] applyLiveOverride failed:', err);
                return false;
            }
        }

        function reloadWithOverrides(overrides) {
            // Build a URL with ?override=<base64-json> and reload.
            // The controller decodes this and merges it on top of the
            // gallery's stored visual_overrides before rendering.
            const url = new URL(window.location.href);
            const json = JSON.stringify(overrides);
            // URL-safe base64 (no +/= so it survives in a query param)
            const b64 = btoa(unescape(encodeURIComponent(json)))
                .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            url.searchParams.set('override', b64);
            window.location.href = url.toString();
        }

        window.addEventListener('message', (e) => {
            if (e.origin !== ALLOWED_ORIGIN) return;
            const msg = e.data;
            if (!msg || typeof msg !== 'object') return;

            if (msg.type === 'exospace-preview-patch') {
                applyPatch(msg.patch);
            } else if (msg.type === 'exospace-preview-reload') {
                reloadWithOverrides(msg.overrides || {});
            } else if (msg.type === 'exospace-preview-ping') {
                // Parent can ping to check if the scene is ready
                e.source.postMessage({
                    type: 'exospace-preview-pong',
                    ready: !!getScene(),
                    hasApplyLiveOverride: typeof (getScene()?.applyLiveOverride) === 'function',
                }, ALLOWED_ORIGIN);
            }
        });

        // Once the scene is ready + first frame rendered, hide the loading
        // overlay and ping the parent so it knows patches can flow.
        let _readyPinged = false;
        function hideLoadingAndPing() {
            const loading = document.getElementById('preview-loading');
            if (loading) loading.classList.add('hidden');
            if (!_readyPinged && window.parent !== window) {
                _readyPinged = true;
                window.parent.postMessage({
                    type: 'exospace-preview-ready',
                }, ALLOWED_ORIGIN);
            }
        }

        // The scene fires window.__exospaceReady (set by main.js once boot completes)
        // — but main.js doesn't currently fire it, so we poll.
        let _pollCount = 0;
        const _poll = setInterval(() => {
            _pollCount++;
            const scene = getScene();
            if (scene && scene.artworks && scene.artworks.length > 0) {
                clearInterval(_poll);
                // Give the renderer one more frame to actually paint
                requestAnimationFrame(() => requestAnimationFrame(hideLoadingAndPing));
            } else if (_pollCount > 200) {
                // 20s timeout — show the scene anyway, the curator can debug
                clearInterval(_poll);
                hideLoadingAndPing();
            }
        }, 100);

        // Track load progress for the loading bar
        const _origUpdateProgress = window.GALLERY_DATA?.updateProgress;
        setInterval(() => {
            const scene = getScene();
            if (scene && typeof scene.loadingProgress === 'number') {
                const fill = document.getElementById('preview-loading-fill');
                if (fill) fill.style.width = scene.loadingProgress + '%';
            }
        }, 200);
    })();
    </script>
</body>
</html>
