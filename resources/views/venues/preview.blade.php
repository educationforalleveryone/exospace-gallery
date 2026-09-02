{{--
    Iteration 1 "The Rehearsal" (roadmap P1.1) — walkable venue preview.

    WHAT THIS PAGE IS
    -----------------
    A public, no-auth, sample-only 3D walkthrough of a venue template,
    rendered by the SAME runtime (resources/js/gallery/main.js) and the
    SAME GALLERY_DATA contract as a customer gallery
    (see VenuePreviewController for the payload's safety properties).

    WHY IT IS SELF-CONTAINED (not an @extends of gallery/view.blade.php)
    -------------------------------------------------------------------
    gallery/view.blade.php is the money page — it is deeply coupled to a
    real Gallery model (owner plan, curtain branding, events, newsletter,
    OG images, analytics session). Reusing it for previews would mean
    threading preview conditionals through every one of those surfaces,
    each one a potential user-data leak. Instead this template mirrors the
    viewer's REQUIRED DOM surface (the ids the 3D runtime queries) and
    carries none of the user-data machinery:

      ✗ no analytics        — the viewer's tracking global is never configured
      ✗ no newsletter form  — the newsletter endpoint key stays null
      ✗ no events           — hasUpcomingEvents is false
      ✗ no OG/artwork pages — no per-artwork OG routes, no deep-links
      ✗ no crawl indexing   — meta robots + X-Robots-Tag (controller)

    SYNC NOTE (Iteration 5 "Authoring" will replace this with the admin
    preview iframe machinery): the viewer CSS below is adapted from
    gallery/view.blade.php. If you change viewer chrome styles there
    (crosshair, info panel, tour HUD, mobile overlay), mirror them here.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-turbo="false">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- NOINDEX (brace to the controller's X-Robots-Tag header): a preview
         is infinite thin-ish content; it must never compete with the
         crawlable /venues/{slug} page or real exhibitions in search. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $venue->name }} — Venue Preview | {{ config('seo.site_name', 'Exospace') }}</title>
    <meta name="description" content="Walk through a live 3D sample exhibition in the {{ $venue->name }} venue. Demonstration artworks — no signup required.">

    @vite(['resources/css/app.css', 'resources/js/gallery/main.js'])

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            margin: 0; overflow: hidden; background-color: #000;
            font-family: system-ui, -apple-system, sans-serif;
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none;
            user-select: none;
        }
        #canvas-container { width: 100vw; height: 100vh; display: block; }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }

        /* ── Preview HUD (top bar) ─────────────────────────────────────────
           Persistent, curtain-independent chrome: back to the venue page on
           the left, the sample label + "use this venue" CTA on the right.
           z-index 150: above the canvas + ui-layer, below the curtain (200)
           — but the curtain carries its own CTA link (see below) so the
           funnel works during loading too. */
        #preview-hud {
            position: fixed; top: 0; left: 0; right: 0; z-index: 150;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 18px; pointer-events: none;
            background: linear-gradient(180deg, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0) 100%);
        }
        .preview-hud-side { display: flex; align-items: center; gap: 10px; pointer-events: auto; }
        .preview-back {
            display: inline-flex; align-items: center; gap: 6px;
            color: rgba(255,255,255,0.85); text-decoration: none;
            font-size: 0.82rem; font-weight: 600; letter-spacing: 0.02em;
            background: rgba(0,0,0,0.55); border: 1px solid rgba(255,255,255,0.15);
            border-radius: 999px; padding: 9px 16px;
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            transition: all 0.2s ease;
        }
        .preview-back:hover { background: rgba(0,0,0,0.75); color: #fff; }
        .preview-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(139,92,246,0.14); border: 1px solid rgba(139,92,246,0.4);
            color: rgba(195,180,255,0.95); border-radius: 999px;
            padding: 8px 14px; font-size: 0.72rem; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
        }
        .preview-cta {
            display: inline-flex; align-items: center; gap: 7px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff; text-decoration: none; border-radius: 999px;
            padding: 9px 18px; font-size: 0.82rem; font-weight: 700;
            box-shadow: 0 6px 24px rgba(99,102,241,0.35);
            transition: all 0.2s ease;
        }
        .preview-cta:hover { transform: translateY(-1px); box-shadow: 0 10px 30px rgba(99,102,241,0.45); }
        .low-end-device #preview-hud { background: rgba(0,0,0,0.6); backdrop-filter: none; }
        @media (max-width: 640px) {
            .preview-chip { display: none; }
            .preview-back { padding: 8px 12px; font-size: 0.75rem; }
            .preview-cta { padding: 8px 14px; font-size: 0.75rem; }
        }

        /* ── Entrance curtain ────────────────────────────────────────────── */
        #entrance-curtain {
            position: fixed; inset: 0; z-index: 200;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #16213e 100%);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            transition: opacity 0.8s ease;
        }
        .entrance-logo {
            font-size: 2rem; font-weight: 800; letter-spacing: 0.3em;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            margin-bottom: 3rem; animation: pulse 2s ease-in-out infinite;
        }
        .entrance-button {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 1.25rem 3rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none; border-radius: 9999px;
            color: white; cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 40px rgba(59, 130, 246, 0.3);
        }
        .entrance-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 50px rgba(59, 130, 246, 0.4);
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }

        /* ── UI overlay ──────────────────────────────────────────────────── */
        #ui-layer { position: absolute; inset: 0; pointer-events: none; z-index: 10; }
        .ui-interactive { pointer-events: auto; }

        #info-panel {
            position: absolute; top: 50%; right: 40px;
            transform: translateY(-50%) translateX(30px);
            background: rgba(10, 10, 20, 0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            padding: 2rem; border-radius: 16px; width: 380px;
            max-height: 70vh; overflow-y: auto; word-wrap: break-word;
            color: white;
            border: 1px solid rgba(139, 92, 246, 0.3);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.05) inset;
            opacity: 0; pointer-events: none; z-index: 100;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #info-panel.show {
            opacity: 1; transform: translateY(-50%) translateX(0); pointer-events: auto;
        }
        #info-panel h3 {
            font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; line-height: 1.3;
            background: linear-gradient(135deg, #8b5cf6, #3b82f6);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        #info-panel p { font-size: 0.95rem; line-height: 1.7; color: rgba(255,255,255,0.85); }
        #info-panel::-webkit-scrollbar { width: 6px; }
        #info-panel::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 3px; }
        #info-panel::-webkit-scrollbar-thumb { background: rgba(139,92,246,0.5); border-radius: 3px; }
        .low-end-device #info-panel {
            backdrop-filter: none; -webkit-backdrop-filter: none;
            background: rgba(0, 0, 0, 0.92);
        }

        /* ── Crosshair ───────────────────────────────────────────────────── */
        #crosshair {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 20px; height: 20px; opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        #crosshair::before, #crosshair::after {
            content: ''; position: absolute; background: rgba(255, 255, 255, 0.8);
        }
        #crosshair::before { width: 2px; height: 100%; left: 50%; transform: translateX(-50%); }
        #crosshair::after  { height: 2px; width: 100%; top: 50%; transform: translateY(-50%); }
        #crosshair.active { opacity: 1; }
        #crosshair.focused::before, #crosshair.focused::after {
            background: rgba(139, 92, 246, 0.95);
            box-shadow: 0 0 10px rgba(139, 92, 246, 0.6);
        }

        /* ── Tour overlay ────────────────────────────────────────────────── */
        #tour-overlay {
            position: absolute; inset: 0; pointer-events: none; display: none; z-index: 50;
        }
        #tour-progress-bar { position: absolute; top: 0; left: 0; right: 0; height: 3px; background: rgba(139,92,246,0.2); }
        #tour-progress-bar::after {
            content: ''; display: block; height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            width: var(--tour-progress, 0%);
            transition: width 0.3s;
        }
        #tour-hud {
            position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%);
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px; border-radius: 999px;
            background: rgba(0,0,0,0.7); backdrop-filter: blur(12px);
            border: 1px solid rgba(139,92,246,0.3);
            color: white; pointer-events: auto;
        }
        .tour-btn {
            min-width: 44px; min-height: 44px;
            width: 44px; height: 44px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: rgba(139,92,246,0.2); border: 1px solid rgba(139,92,246,0.4);
            color: white; cursor: pointer; transition: all 0.2s;
        }
        .tour-btn:hover { background: rgba(139,92,246,0.4); }
        .tour-btn svg { width: 18px; height: 18px; }
        #tour-countdown-ring { position: relative; width: 36px; height: 36px; }
        #tour-countdown-ring svg { transform: rotate(-90deg); }
        #tour-ring-bg { fill: none; stroke: rgba(255,255,255,0.1); stroke-width: 2; }
        #tour-ring-arc {
            fill: none; stroke: #8b5cf6; stroke-width: 2;
            stroke-dasharray: 100; stroke-dashoffset: 100;
            stroke-linecap: round; transition: stroke-dashoffset 0.1s linear;
        }
        #tour-counter { font-size: 0.8rem; color: rgba(255,255,255,0.6); font-variant-numeric: tabular-nums; }
        #tour-title-display { font-size: 0.9rem; color: white; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tour-exit-btn { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }

        #tour-hud button:focus-visible,
        #audio-toggle:focus-visible,
        #in-gallery-tour-btn:focus-visible,
        #info-panel button:focus-visible,
        #info-panel a:focus-visible,
        #canvas-container:focus-visible,
        .preview-back:focus-visible,
        .preview-cta:focus-visible {
            outline: 2px solid #a78bfa;
            outline-offset: 2px;
            border-radius: 6px;
        }

        /* ── Mobile overlay ──────────────────────────────────────────────── */
        @media (pointer: coarse), (hover: none) {
            #desktop-controls { display: none !important; }
        }
        #mobile-overlay { display: none; }
        #mobile-overlay.active {
            display: block; position: absolute; inset: 0; z-index: 20; pointer-events: none;
        }
        #joystick-zone {
            position: absolute; left: 0; bottom: 0; width: 50%; height: 50%;
            pointer-events: auto;
        }
        #joystick-base {
            position: absolute; width: 100px; height: 100px; border-radius: 50%;
            background: rgba(139,92,246,0.15); border: 2px solid rgba(139,92,246,0.4);
            display: none; pointer-events: none;
        }
        #joystick-thumb {
            position: absolute; width: 50px; height: 50px; border-radius: 50%;
            background: rgba(139,92,246,0.6); border: 2px solid rgba(255,255,255,0.4);
            pointer-events: none; transform: translate(-50%, -50%);
        }
        #look-zone {
            position: absolute; right: 0; top: 0; width: 50%; height: 100%;
            pointer-events: auto;
            z-index: 10; /* below #ui-layer so buttons are tappable */
        }
        #sprint-btn, #speed-dial {
            position: absolute; right: 24px; pointer-events: auto;
            min-height: 44px; min-width: 44px;
            padding: 12px 18px; border-radius: 999px;
            background: rgba(0,0,0,0.7); border: 1px solid rgba(139,92,246,0.4);
            color: white; font-weight: 700; font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
        }
        #sprint-btn { bottom: 130px; }
        #sprint-btn.active { background: rgba(139,92,246,0.4); }
        #speed-dial { bottom: 70px; min-width: 60px; text-align: center; }
        #mobile-hint {
            position: absolute; top: 100px; left: 50%; transform: translateX(-50%);
            padding: 10px 20px; border-radius: 8px;
            background: rgba(0,0,0,0.8); color: white; font-size: 0.85rem;
            opacity: 0; transition: opacity 0.4s; pointer-events: none;
        }
        #mobile-hint.show { opacity: 1; }
    </style>
</head>
<body>
    {{-- No-JS visitors: honest explanation instead of a black page. --}}
    <noscript>
        <div style="max-width: 600px; margin: 4rem auto; padding: 2rem; text-align: center; color: #e2e8f0; font-family: system-ui, sans-serif;">
            <h1 style="font-size: 2rem; margin-bottom: 1rem;">{{ $venue->name }} — Venue Preview</h1>
            <p style="color: #94a3b8; margin-bottom: 2rem;">
                This preview requires JavaScript and WebGL to display the immersive 3D walkthrough.
                Please enable JavaScript in your browser settings to continue.
            </p>
            <p style="color: #64748b; font-size: 0.875rem;">
                <a href="{{ route('venues.show', $venue->slug) }}" style="color: #a78bfa;">Back to the venue page →</a>
            </p>
        </div>
    </noscript>

    {{-- WebGL fallback (shown by the inline script below when WebGL is unavailable) --}}
    <div id="webgl-fallback" style="display:none; max-width: 600px; margin: 4rem auto; padding: 2rem; text-align: center; color: #e2e8f0; font-family: system-ui, sans-serif;">
        <h2 style="font-size: 1.5rem; margin-bottom: 1rem;">WebGL Not Available</h2>
        <p style="color: #94a3b8; margin-bottom: 1.5rem;">
            Your browser does not support WebGL, which is required for the 3D venue preview.
            Try a modern browser like Chrome, Firefox, or Safari with hardware acceleration enabled.
        </p>
        <a href="{{ route('venues.show', $venue->slug) }}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600;">Back to the venue page →</a>
    </div>

    {{-- ── Preview HUD ───────────────────────────────────────────────────────
         Persistent chrome while walking. Left: exit back to the venue page.
         Right: the sample label + the conversion CTA. Previews ARE the
         funnel (roadmap DO NOT DO #10) — the CTA is never gated, nagged, or
         countdown-limited; it is simply there. --}}
    <div id="preview-hud">
        <div class="preview-hud-side">
            <a class="preview-back" href="{{ route('venues.show', $venue->slug) }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                {{ $venue->name }}
            </a>
        </div>
        <div class="preview-hud-side">
            <span class="preview-chip">Sample exhibition</span>
            <a class="preview-cta" href="{{ auth()->check() ? route('galleries.index') : route('register') }}">
                Use this venue
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7"/></svg>
            </a>
        </div>
    </div>

    {{-- ── Entrance curtain ─────────────────────────────────────────────────── --}}
    <div id="entrance-curtain">
        <div style="max-width: 800px; text-align: center; padding: 0 2rem;">
            <div class="entrance-logo">EXOSPACE</div>

            <h1 style="font-size: 3rem; font-weight: 800; color: white; margin-bottom: 1rem; line-height: 1.2;">
                {{ $venue->name }}
            </h1>

            <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.3); border-radius: 999px; padding: 6px 16px; margin-bottom: 1.5rem; font-size: 0.8rem; color: rgba(139,92,246,0.9); letter-spacing: 0.08em; font-weight: 600;">
                SAMPLE EXHIBITION — {{ strtoupper($sampleCredit) }}
            </div>

            @if($sampleNote)
                <p style="font-size: 1rem; color: rgba(255,255,255,0.65); margin-bottom: 0.75rem; max-width: 600px; margin-left: auto; margin-right: auto;">
                    {{ $sampleNote }}
                </p>
            @endif

            @if($venue->description)
                <p style="font-size: 0.95rem; color: rgba(255,255,255,0.45); margin-bottom: 3rem; max-width: 600px; margin-left: auto; margin-right: auto;">
                    {{ $venue->description }}
                </p>
            @endif

            <div style="display: flex; gap: 3rem; justify-content: center; margin-bottom: 3rem; font-size: 0.875rem; color: rgba(255,255,255,0.5);">
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">{{ count($galleryData['images']) }}</div>
                    <div>Sample artworks</div>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">3D</div>
                    <div>Walkthrough</div>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">{{ ucfirst($venue->plan_required) }}</div>
                    <div>Plan tier</div>
                </div>
            </div>

            <div id="curtain-progress" style="width: 300px; margin: 0 auto 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span id="curtain-progress-text" style="font-size: 0.875rem; color: rgba(255,255,255,0.75);">Preparing venue...</span>
                    <span id="curtain-progress-percent" style="font-size: 0.875rem; color: rgba(255,255,255,0.9); font-weight: 600;">0%</span>
                </div>
                <div style="width: 100%; height: 3px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden;">
                    <div id="curtain-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); transition: width 0.3s ease;"></div>
                </div>
            </div>

            <button id="enter-btn" class="entrance-button" style="opacity: 0.5; pointer-events: none;">
                <span style="font-size: 1.125rem; font-weight: 600; letter-spacing: 0.05em;">WALK THROUGH</span>
                <svg style="width: 1.5rem; height: 1.5rem; margin-left: 0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                </svg>
            </button>

            <p style="margin-top: 2rem; font-size: 0.875rem; color: rgba(255,255,255,0.4);">
                Use WASD to move • Mouse to look around • Press T for guided tour
            </p>

            <a href="#" id="skip-intro-link"
               style="display: block; margin-top: 1rem; font-size: 0.8rem; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s;">
                Skip intro →
            </a>

            <a href="{{ auth()->check() ? route('galleries.index') : route('register') }}"
               style="display: block; margin-top: 0.5rem; font-size: 0.85rem; color: rgba(195,180,255,0.8); text-decoration: none; font-weight: 600; transition: color 0.2s;">
                Use this venue for your exhibition →
            </a>
        </div>
    </div>

    {{-- ── 3D canvas ───────────────────────────────────────────────────────── --}}
    <div id="canvas-container"
         role="application"
         aria-label="Interactive 3D venue preview: {{ $venue->name }} sample exhibition — Use WASD to move, mouse to look, E to view artwork info, T for guided tour. Press Escape to exit pointer lock."
         tabindex="-1"
         aria-describedby="controls-hint"></div>
    <div id="controls-hint" class="sr-only">
        This is an interactive 3D venue preview with sample artworks. Use keyboard: W A S D or arrow keys to move, mouse to look around, E to view artwork information, T for a guided tour, Escape to close dialogs. On mobile, use the on-screen joystick.
    </div>

    {{-- ── Tour overlay ───────────────────────────────────────────────────── --}}
    <div id="tour-overlay" aria-hidden="true">
        <div id="tour-progress-bar" aria-hidden="true"></div>
        <div id="tour-hud" role="group" aria-label="Guided tour controls">
            <button class="tour-btn" id="tour-prev-btn" title="Previous" aria-label="Previous artwork">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="tour-btn" id="tour-pause-btn" title="Pause / Resume" aria-label="Pause or resume tour" aria-pressed="false">
                <svg id="tour-pause-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                <svg id="tour-play-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </button>
            <div id="tour-countdown-ring" aria-hidden="true">
                <svg viewBox="0 0 36 36" width="36" height="36">
                    <circle id="tour-ring-bg" cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="2"/>
                    <circle id="tour-ring-arc" cx="18" cy="18" r="15.9" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" style="stroke-dasharray: 100; stroke-dashoffset: 100;"/>
                </svg>
            </div>
            <span id="tour-counter" aria-live="polite">1 / 1</span>
            <span id="tour-title-display" aria-live="polite">—</span>
            <button class="tour-btn" id="tour-next-btn" title="Next" aria-label="Next artwork">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button class="tour-btn tour-exit-btn" id="tour-exit-btn" title="Exit tour" aria-label="Exit guided tour">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>

    {{-- ── UI overlay ───────────────────────────────────────────────────────── --}}
    <div id="ui-layer">
        <div class="absolute top-20 left-6">
            <h1 class="text-white text-2xl font-bold drop-shadow-lg mb-1">{{ $venue->name }}</h1>
            <p class="text-white/70 text-xs max-w-md drop-shadow hidden md:block">
                Sample exhibition — demonstration artworks, not for sale
            </p>
        </div>

        <div class="absolute top-20 right-6 flex items-center gap-3">
            <button id="audio-toggle"
                aria-label="Mute audio"
                aria-pressed="false"
                style="display:flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;width:44px;height:44px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;font-size:1.1rem;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                🔊
            </button>
            <button id="in-gallery-tour-btn"
                style="display:flex;align-items:center;gap:6px;min-height:44px;padding:10px 16px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:rgba(255,255,255,0.75);font-size:0.8rem;font-weight:500;letter-spacing:0.05em;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                <svg style="width:14px;height:14px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                TOUR
            </button>
            <div class="bg-black/70 backdrop-blur-md px-4 py-2 rounded-lg border border-white/10" id="speed-indicator">
                <p class="text-white/90 text-sm font-mono">Speed: <span class="text-blue-400 font-bold" id="speed-value">1x</span></p>
            </div>
        </div>

        <div class="absolute bottom-6 left-6">
            <div class="bg-black/70 backdrop-blur-md px-4 py-3 rounded-lg border border-white/10">
                <div class="text-white/90 text-sm space-y-1 hidden md:block" id="desktop-controls">
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">WASD</span> Move</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">SHIFT</span> Sprint</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">1/2/3/4</span> Speed (1x/2x/4x/8x)</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">MOUSE</span> Look Around</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">CLICK</span> Lock/Unlock</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">E</span> View Info</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">T</span> Guided Tour</p>
                </div>
                <div class="text-white/90 text-sm md:hidden">
                    <p>Tap screen to start • Drag to look</p>
                </div>
            </div>
        </div>

        {{-- Artwork info panel — sample artworks carry title/description/
             medium/year/dimensions only: no artist link, no price row, no
             external link (updateArtworkMeta hides those rows when the
             payload omits the data — samples always do). --}}
        <div id="info-panel" aria-live="polite" aria-atomic="true">
            <h3 id="artwork-title">Artwork Title</h3>
            <p id="artwork-description">Description will appear here</p>

            <div id="artwork-meta" class="mt-3 pt-3 border-t border-white/10" style="display:none;">
                <div id="artwork-details" class="text-xs text-gray-400 space-y-0.5"></div>
            </div>

            <div class="mt-3 pt-3 border-t border-white/10">
                <p class="text-xs text-gray-400 desktop-hint">Press E to close</p>
                <p class="text-xs text-gray-400 mobile-hint hidden">Double-tap to close</p>
            </div>
        </div>

        <div id="crosshair" aria-hidden="true"></div>
        <span class="sr-only" aria-hidden="false">
            A center crosshair indicates where the camera is pointing. The crosshair turns purple when an artwork is in focus.
        </span>

        <div id="focus-indicator" style="position: absolute; top: 90px; left: 50%; transform: translateX(-50%); background: rgba(139, 92, 246, 0.15); backdrop-filter: blur(10px); padding: 0.75rem 1.5rem; border-radius: 20px; border: 1px solid rgba(139, 92, 246, 0.4); color: rgba(139, 92, 246, 1); font-size: 0.875rem; font-weight: 600; letter-spacing: 0.05em; opacity: 0; transition: opacity 0.3s ease; pointer-events: none; z-index: 50;">
            <span class="desktop-text">FOCUS MODE • Press E to Exit</span>
            <span class="mobile-text hidden">FOCUS MODE • Double-tap to Exit</span>
        </div>
    </div>

    {{-- ── Mobile overlay (joystick + look pad + buttons) ────────────────── --}}
    <div id="mobile-overlay">
        <div id="joystick-zone">
            <div id="joystick-base"><div id="joystick-thumb"></div></div>
        </div>
        <div id="look-zone"></div>
        <button id="sprint-btn">SPRINT</button>
        <button id="speed-dial">1x</button>
        <div id="mobile-hint">Left: move • Right: look • Double-tap: focus</div>
    </div>

    {{-- ── Gallery data injection (consumed by main.js) ───────────────────── --}}
    <script nonce="@nonce">
        // WebGL detection — show the fallback div and hide the curtain if
        // WebGL is unavailable (same pattern as gallery/view.blade.php).
        (function() {
            var canvas = document.createElement('canvas');
            var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) {
                var fallback = document.getElementById('webgl-fallback');
                var curtain = document.getElementById('entrance-curtain');
                if (fallback) fallback.style.display = 'block';
                if (curtain) curtain.style.display = 'none';
            }
        })();

        // Service worker: cache the shared 3D engine assets. A visitor who
        // previews a venue then opens a real gallery boots from disk cache.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js').catch(function() {});
            });
        }

        window.EXOSPACE_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.EXOSPACE_DEBUG = new URLSearchParams(window.location.search).has('debug');

        // NOTE (analytics silence): the gallery viewer's analytics module
        // no-ops unless a track endpoint + session id are configured. This
        // page deliberately configures NEITHER, so preview walks cannot
        // inflate gallery analytics or view counts. Do not add them here.

        window.GALLERY_DATA = @json($galleryData);

        if (!window.GALLERY_DATA || window.GALLERY_DATA.images.length === 0) {
            console.warn("[Preview] No sample artworks — showing empty state.");
            window.GALLERY_DATA.images = [];
            window.GALLERY_DATA._isEmpty = true;
        }

        // Artwork info panel updater (same shape as gallery/view.blade.php,
        // minus price/artist/external-link/share rows — samples never carry
        // that data, so the rows stay hidden and the code paths stay dead).
        window.updateArtworkMeta = function(data) {
            const metaPanel = document.getElementById('artwork-meta');
            const detailsEl = document.getElementById('artwork-details');

            if (!metaPanel || !detailsEl) return;
            metaPanel.style.display = 'none';
            detailsEl.innerHTML = '';

            const hasDetails = data.medium || data.year || data.dimensions || data.edition;
            if (!hasDetails) return;

            metaPanel.style.display = 'block';
            const parts = [];
            if (data.medium)     parts.push(data.medium);
            if (data.year)       parts.push(data.year);
            if (data.dimensions) parts.push(data.dimensions);
            if (data.edition)    parts.push(data.edition);
            for (const p of parts) {
                const div = document.createElement('div');
                div.textContent = p;
                detailsEl.appendChild(div);
            }
        };

        // CSP-safe event wiring (same pattern as gallery/view.blade.php).
        document.addEventListener('DOMContentLoaded', () => {
            const audioBtn = document.getElementById('audio-toggle');
            if (audioBtn && typeof window.toggleAudioMute === 'function') {
                audioBtn.addEventListener('click', () => window.toggleAudioMute());
            }
            const tourBtn = document.getElementById('in-gallery-tour-btn');
            if (tourBtn && typeof window.startGuidedTour === 'function') {
                tourBtn.addEventListener('click', () => window.startGuidedTour());
            }
            const skipLink = document.getElementById('skip-intro-link');
            if (skipLink) {
                skipLink.addEventListener('mouseenter', () => {
                    skipLink.style.color = 'rgba(255,255,255,0.6)';
                });
                skipLink.addEventListener('mouseleave', () => {
                    skipLink.style.color = 'rgba(255,255,255,0.35)';
                });
            }
        });
    </script>
</body>
</html>
