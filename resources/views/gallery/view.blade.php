@php
    $isEmbed = request()->boolean('embed');
    // (Task H50) — if ?artwork=<id> is in the URL, generate a per-artwork
    // OG image that shows the artwork's image + title + artist.
    $artworkParam = request()->integer('artwork');
    $ogImageUrl = $artworkParam
        ? route('gallery.og-image', $gallery->slug) . '?artwork=' . $artworkParam
        : route('gallery.og-image', $gallery->slug);
    $publicUrl = $gallery->public_url;

    // SEO OS (Iteration 2) — controller-provided SeoData with canonical,
    // robots (embed/empty rules) and profile overrides applied.
    /** @var \App\Support\Seo\SeoData $gallerySeo */
    $publicArtists = $gallery->images
        ->filter(fn ($img) => $img->artist?->name)
        ->map(fn ($img) => $img->artist)
        ->unique('id')
        ->take(8);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-turbo="false">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- (Task H35 / audit M9) — removed maximum-scale=1.0, user-scalable=no.
         WCAG 2.1 AA (1.4.4 Resize Text) requires users be able to zoom.
         The 3D canvas intercepts touch events regardless; the curtain UI
         (title, description, "Enter" button) should be zoomable. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- SEO OS (Iteration 2): full meta layer from SeoData — title,
         description (with factual fallback), CANONICAL (was missing —
         audit C3), robots, OG/Twitter with image dimensions + alt. --}}
    <x-seo :seo="$gallerySeo" />

    {{-- P2-11 → SEO OS (Iteration 3): the ExhibitionEvent/ItemList graphs
         are now built by SchemaBuilder in the controller and rendered from
         SeoData->jsonLd inside <x-seo> — no inline JSON-LD in the template. --}}

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

        /* ── SEO OS (Iteration 2): crawlable semantic layer ─────────────
           The 3D experience is JS/WebGL-only; search engines and users
           without WebGL need a meaningful HTML representation. This
           slide-over panel carries the exhibition's full semantic content:
           description, dates, artists (linked), artwork list (linked to
           artwork pages), venue, events. It is ALWAYS in the DOM (crawlable
           + screen-reader accessible) and opens on demand — the immersive
           experience is untouched. */
        #exhibition-details {
            position: fixed; top: 0; right: 0; bottom: 0;
            width: min(520px, 92vw); z-index: 150;
            background: rgba(10, 10, 20, 0.96);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-left: 1px solid rgba(139, 92, 246, 0.3);
            box-shadow: -8px 0 32px rgba(0,0,0,0.5);
            overflow-y: auto; padding: 2rem 1.75rem 3rem;
            transform: translateX(102%);
            visibility: hidden;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.4s;
            color: #e2e8f0;
            font-family: system-ui, -apple-system, sans-serif;
        }
        #exhibition-details.open {
            transform: translateX(0);
            visibility: visible;
        }
        #exhibition-details h2 { font-size: 1.35rem; font-weight: 800; color: #fff; margin: 0 0 0.35rem; line-height: 1.3; }
        #exhibition-details .ed-kicker { font-size: 0.7rem; letter-spacing: 0.2em; color: #a78bfa; font-weight: 700; text-transform: uppercase; margin-bottom: 0.75rem; }
        #exhibition-details .ed-desc { font-size: 0.95rem; line-height: 1.7; color: rgba(255,255,255,0.82); white-space: pre-line; margin-bottom: 1.5rem; }
        #exhibition-details .ed-meta { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; font-size: 0.8rem; color: rgba(255,255,255,0.55); margin-bottom: 1.5rem; }
        #exhibition-details .ed-section-title { font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.45); font-weight: 700; margin: 1.75rem 0 0.75rem; }
        #exhibition-details ul.ed-artists { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
        #exhibition-details ul.ed-artists li a { display: inline-block; padding: 4px 12px; border-radius: 999px; background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.35); color: #c4b5fd; font-size: 0.8rem; text-decoration: none; }
        #exhibition-details ol.ed-artworks { list-style: none; margin: 0; padding: 0; counter-reset: ed-art; }
        #exhibition-details ol.ed-artworks li { counter-increment: ed-art; border-bottom: 1px solid rgba(255,255,255,0.06); }
        #exhibition-details ol.ed-artworks li a { display: flex; align-items: baseline; gap: 0.75rem; padding: 0.55rem 0; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 0.9rem; line-height: 1.4; }
        #exhibition-details ol.ed-artworks li a::before { content: counter(ed-art); color: rgba(255,255,255,0.3); font-size: 0.75rem; font-variant-numeric: tabular-nums; min-width: 1.5rem; }
        #exhibition-details ol.ed-artworks li a:hover { color: #c4b5fd; }
        #exhibition-details ol.ed-artworks li .ed-art-meta { color: rgba(255,255,255,0.4); font-size: 0.75rem; }
        #exhibition-details .ed-close {
            position: absolute; top: 1rem; right: 1rem;
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);
            color: #fff; border-radius: 8px; width: 36px; height: 36px;
            font-size: 1.1rem; cursor: pointer;
        }
        #exhibition-details .ed-links a { color: #a78bfa; font-size: 0.85rem; text-decoration: none; }
        #exhibition-details .ed-links a:hover { color: #c4b5fd; text-decoration: underline; }
        .low-end-device #exhibition-details { backdrop-filter: none; -webkit-backdrop-filter: none; background: rgba(5, 5, 12, 0.99); }
        #exhibition-details-btn:focus-visible { outline: 2px solid #a78bfa; outline-offset: 2px; }

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
        /* A11Y-9: The crosshair is a visual-only affordance (indicates where
           the camera is pointing). It is hidden from screen readers via
           aria-hidden on the element, and an sr-only span provides a text
           alternative for AT users who navigate by focus rather than sight. */
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
            /* UX-5: 44×44 minimum for WCAG 2.5.5 Level AAA touch target */
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

        /* ── A11Y-10: Keyboard focus indicator ────────────────────────────
           All interactive elements inside the gallery view show a clear
           2px purple outline when they receive keyboard focus (:focus-visible).
           Mouse clicks don't trigger this — only keyboard navigation does.
           This covers: tour buttons, audio toggle, artwork info panel
           close button, and any future keyboard-accessible controls. The
           canvas-container has tabindex=-1 so it isn't in the tab order;
           if it ever receives programmatic focus, it also gets the outline. */
        #tour-hud button:focus-visible,
        #audio-toggle:focus-visible,
        #in-gallery-tour-btn:focus-visible,
        #info-panel button:focus-visible,
        #info-panel a:focus-visible,
        #canvas-container:focus-visible {
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
        /* UX-4 FIX: The look-zone covers the right 50% of the screen on mobile
           with pointer-events:auto, which intercepts taps on the tour button +
           audio toggle (top-right). Lower the look-zone z-index so the buttons
           (z-index: 30 via #ui-layer) sit above it and receive taps first.
           The look-zone still receives drag gestures for camera look — the
           buttons are small enough that they don't meaningfully reduce the
           drag area. */
        #look-zone {
            position: absolute; right: 0; top: 0; width: 50%; height: 100%;
            pointer-events: auto;
            z-index: 10; /* below #ui-layer (z-index: 30) so buttons are tappable */
        }
        /* UX-5 FIX: WCAG 2.5.5 (Level AAA) requires touch targets ≥ 44×44 CSS
           pixels. The tour buttons + audio toggle + sprint/speed buttons were
           36×36 / 60×36 — barely meeting Level AA (24×24) but not AAA. Bumped
           to 44×44 minimum. The tour button gets extra horizontal padding so
           the "TOUR" label + icon hit the 44px height target. */
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

    @if($isEmbed)
    <style>
        body.embed-mode .entrance-logo { display: none !important; }
        body.embed-mode #in-gallery-tour-btn { display: none !important; }
        body.embed-mode .created-with-exospace { display: none !important; }
        body.embed-mode #focus-exit-hint { display: none !important; }
        body.embed-mode #entrance-curtain { transition: opacity 0.3s ease !important; }
    </style>
    <script nonce="@nonce">window.EXOSPACE_EMBED_MODE = true;</script>
    @endif
</head>
<body @if($isEmbed) class="embed-mode" @endif>
    {{-- GSAP is now bundled by Vite as an ES module via the gallery entry
         point (resources/js/gallery/main.js). The previous hand-placed
         <script src="js/gsap.min.js" defer> was redundant — `import gsap
         from 'gsap'` in Tour.js / FocusMode.js does NOT read window.gsap,
         it expects an ES module export. (Task C12 / audit L15.) --}}

    {{-- (Task H35 / audit C4) — noscript fallback for users without JS --}}
    <noscript>
        <div style="max-width: 600px; margin: 4rem auto; padding: 2rem; text-align: center; color: #e2e8f0; font-family: system-ui, sans-serif;">
            <h1 style="font-size: 2rem; margin-bottom: 1rem;">{{ $gallery->title }}</h1>
            <p style="color: #94a3b8; margin-bottom: 2rem;">
                This gallery requires JavaScript and WebGL to display the immersive 3D experience.
                Please enable JavaScript in your browser settings to continue.
            </p>
            <p style="color: #64748b; font-size: 0.875rem;">
                <a href="{{ route('discover') }}" style="color: #a78bfa;">Browse other galleries →</a>
            </p>
        </div>
    </noscript>

    {{-- (Task H35 / audit C4) — WebGL fallback div (hidden by default,
         shown by JS if WebGL is unavailable) --}}
    <div id="webgl-fallback" style="display:none; max-width: 600px; margin: 4rem auto; padding: 2rem; text-align: center; color: #e2e8f0; font-family: system-ui, sans-serif;">
        <h2 style="font-size: 1.5rem; margin-bottom: 1rem;">WebGL Not Available</h2>
        <p style="color: #94a3b8; margin-bottom: 1.5rem;">
            Your browser does not support WebGL, which is required for the 3D gallery experience.
            Try a modern browser like Chrome, Firefox, or Safari with hardware acceleration enabled.
        </p>
        <div style="background: #1e293b; border-radius: 12px; padding: 1.5rem; margin: 1.5rem 0;">
            <p style="font-size: 0.875rem; color: #64748b; margin-bottom: 0.5rem;">Gallery: {{ $gallery->title }}</p>
            @if($gallery->images->count() > 0)
                <p style="font-size: 0.875rem; color: #64748b;">{{ $gallery->images->count() }} artworks in this exhibition</p>
            @endif
        </div>
        <a href="{{ route('discover') }}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 600;">Browse Other Galleries →</a>
    </div>

    {{-- ── Entrance curtain ──────────────────────────────────────────────────── --}}
    <div id="entrance-curtain"
         @if($gallery->user->plan === 'studio' && $gallery->curtain_bg_color)
         style="background: {{ $gallery->curtain_bg_color }};"
         @endif>
    >
        <div style="max-width: 800px; text-align: center; padding: 0 2rem;">
            @if($gallery->user->plan === 'studio' && $gallery->curtain_logo_path)
                <img src="{{ asset('storage/' . $gallery->curtain_logo_path) }}"
                     alt="{{ $gallery->title }}"
                     style="max-height: 80px; max-width: 240px; object-fit: contain; margin-bottom: 2rem;">
            @else
                <div class="entrance-logo">EXOSPACE</div>
            @endif

            <h1 style="font-size: 3rem; font-weight: 800; color: white; margin-bottom: 1rem; line-height: 1.2;">
                {{ $gallery->title }}
            </h1>

            @if($gallery->description)
            <p style="font-size: 1.125rem; color: rgba(255,255,255,0.7); margin-bottom: 3rem; max-width: 600px; margin-left: auto; margin-right: auto;">
                {{ $gallery->description }}
            </p>
            @endif

            @if($gallery->venueTemplate)
            <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.3); border-radius: 999px; padding: 6px 16px; margin-bottom: 2rem; font-size: 0.8rem; color: rgba(139,92,246,0.9); letter-spacing: 0.08em; font-weight: 600;">
                {{ strtoupper($gallery->venueTemplate->name) }}
            </div>
            @endif

            <div style="display: flex; gap: 3rem; justify-content: center; margin-bottom: 3rem; font-size: 0.875rem; color: rgba(255,255,255,0.5);">
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">{{ $gallery->images->count() }}</div>
                    <div>Artworks</div>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">3D</div>
                    <div>Experience</div>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: rgba(255,255,255,0.9); margin-bottom: 0.25rem;">{{ number_format($gallery->view_count) }}</div>
                    <div>Views</div>
                </div>
            </div>

            <div id="curtain-progress" style="width: 300px; margin: 0 auto 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span id="curtain-progress-text" style="font-size: 0.875rem; color: rgba(255,255,255,0.75);">Preparing exhibition...</span>
                    <span id="curtain-progress-percent" style="font-size: 0.875rem; color: rgba(255,255,255,0.9); font-weight: 600;">0%</span>
                </div>
                <div style="width: 100%; height: 3px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden;">
                    <div id="curtain-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); transition: width 0.3s ease;"></div>
                </div>
            </div>

            <button id="enter-btn" class="entrance-button" style="opacity: 0.5; pointer-events: none;">
                <span style="font-size: 1.125rem; font-weight: 600; letter-spacing: 0.05em;">ENTER EXHIBITION</span>
                <svg style="width: 1.5rem; height: 1.5rem; margin-left: 0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                </svg>
            </button>

            <p style="margin-top: 2rem; font-size: 0.875rem; color: rgba(255,255,255,0.4);">
                Use WASD to move • Mouse to look around • Press T for guided tour
            </p>

            {{-- (Task H48 / audit MX6) — "Skip Intro" link. Lets visitors
                 enter the gallery without waiting for 100% load. The
                 button is always visible (not gated by loading progress)
                 so visitors on slow connections aren't held hostage. --}}
            <a href="#" id="skip-intro-link"
               style="display: block; margin-top: 1rem; font-size: 0.8rem; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s;">
                Skip intro →
            </a>

            {{-- SEO OS (Iteration 2): curtain entry to the crawlable
                 details panel — artwork list, artists, dates. --}}
            <a href="#exhibition-details" id="curtain-details-link"
               data-ed-open="1"
               style="display: block; margin-top: 0.5rem; font-size: 0.8rem; color: rgba(195,180,255,0.7); text-decoration: none; transition: color 0.2s;">
                Exhibition details &amp; artwork list
            </a>

            {{-- (PERF-C15 / 3D audit F15) — uses the $hasUpcomingEvents variable
                 computed once in the controller instead of re-running the
                 exists() query a second time per page view. --}}
            @if($hasUpcomingEvents)
            <a href="{{ route('gallery.events.index', $gallery->slug) }}"
               style="display: inline-flex; align-items: center; gap: 8px; margin-top: 1.5rem; padding: 8px 16px; background: rgba(139,92,246,0.15); border: 1px solid rgba(139,92,246,0.4); border-radius: 999px; color: rgba(195,180,255,0.95); font-size: 0.8rem; font-weight: 500; text-decoration: none; transition: all 0.2s ease;">
                <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                View upcoming events
            </a>
            @endif

            @if(!request()->boolean('embed'))
            <form id="newsletter-signup-form"
                  style="max-width: 380px; margin: 2.5rem auto 0; padding: 1rem 1.25rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; backdrop-filter: blur(8px);">
                <p style="font-size: 0.8rem; color: rgba(255,255,255,0.75); margin-bottom: 0.75rem; letter-spacing: 0.04em;">JOIN THE LIST</p>
                <div style="display: flex; gap: 8px;">
                    {{-- A11Y-4: Added visually-hidden label for screen readers --}}
                    <label for="newsletter-email-input" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Your email address</label>
                    <input id="newsletter-email-input" type="email" name="email" required placeholder="your@email.com" aria-label="Your email address"
                           style="flex: 1; padding: 8px 12px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-size: 0.85rem; outline: none;">
                    <button type="submit"
                            style="padding: 8px 16px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border: none; border-radius: 8px; color: white; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s;">
                        Subscribe
                    </button>
                </div>
                <p class="newsletter-msg" style="font-size: 0.75rem; margin-top: 0.5rem; min-height: 1rem;"></p>
                {{-- P3-19: Cloudflare Turnstile captcha (invisible when enabled).
                     Renders nothing in dev (TurnstileService disabled). --}}
                @if(app('App\Services\TurnstileService')->isEnabled())
                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" style="margin-top: 0.5rem;"></div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                @endif
            </form>
            @endif
        </div>
    </div>

    {{-- ── 3D canvas ──────────────────────────────────────────────────────────── --}}
    {{-- A11Y-1: ARIA role + label for screen readers. The canvas is an
         interactive 3D application — screen readers announce it as such
         and keyboard users can skip past it. --}}
    <div id="canvas-container"
         role="application"
         aria-label="Interactive 3D gallery: {{ $gallery->title }} — Use WASD to move, mouse to look, E to view artwork info, T for guided tour. Press Escape to exit pointer lock."
         tabindex="-1"
         aria-describedby="controls-hint"></div>
    <div id="controls-hint" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">
        This is an interactive 3D art gallery. Use keyboard: W A S D or arrow keys to move, mouse to look around, E to view artwork information, T for a guided tour, Escape to close dialogs. On mobile, use the on-screen joystick.
    </div>

    {{-- ── SEO OS (Iteration 2): crawlable semantic layer ───────────────────
         Full HTML representation of the exhibition that complements the 3D
         experience: title, description, artists (linked), artwork list
         (linked to artwork pages), dates, venue, events. Always in the DOM;
         opened via the "Details" button. See docs/SEO_AUDIT.md H1. --}}
    @unless($isEmbed)
    <section id="exhibition-details" aria-label="Exhibition details and artwork list">
        <button type="button" class="ed-close" aria-label="Close exhibition details">&times;</button>

        <p class="ed-kicker">3D Virtual Exhibition</p>
        <h2>{{ $gallery->title }}</h2>

        <div class="ed-meta">
            @if($gallery->venueTemplate)
                <span>{{ $gallery->venueTemplate->name }}</span>
            @endif
            <span>{{ $gallery->images->count() }} {{ Str::plural('artwork', $gallery->images->count()) }}</span>
            <span>{{ number_format($gallery->view_count) }} views</span>
            @if($gallery->opens_at)
                <span>Opened {{ $gallery->opens_at->format('M j, Y') }}</span>
            @endif
            @if($gallery->closes_at)
                <span>Closes {{ $gallery->closes_at->format('M j, Y') }}</span>
            @endif
        </div>

        @if($gallery->description)
            <p class="ed-desc">{{ $gallery->description }}</p>
        @endif

        @if($publicArtists->isNotEmpty())
            <p class="ed-section-title">Featured artists</p>
            <ul class="ed-artists">
                @foreach($publicArtists as $artist)
                    <li><a href="{{ route('artist.profile', $artist->slug) }}">{{ $artist->name }}</a></li>
                @endforeach
            </ul>
        @endif

        @if($gallery->images->isNotEmpty())
            <p class="ed-section-title">Artworks in this exhibition</p>
            <ol class="ed-artworks">
                @foreach($gallery->images->take(60) as $img)
                    <li>
                        <a href="{{ url('/gallery/' . $gallery->slug . '/artwork/' . $img->id) }}">
                            <span>{{ $img->title ?: $img->original_name ?: 'Untitled' }}@if($img->artist) <span class="ed-art-meta">by {{ $img->artist->name }}</span>@endif</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- SEO OS (Iteration 3): related exhibitions (relevance-based
             internal linking — shared artists, then shared venue). --}}
        @if($relatedGalleries->isNotEmpty())
            <p class="ed-section-title">Related exhibitions</p>
            <ul class="ed-artists" style="flex-direction: column; align-items: stretch; gap: 0.375rem;">
                @foreach($relatedGalleries as $related)
                    <li style="width: 100%;">
                        <a href="{{ $related->public_url }}" style="display: block; padding: 8px 12px; border-radius: 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07);">
                            <span style="display: block; font-size: 0.875rem; color: rgba(255,255,255,0.85);">{{ $related->title }}</span>
                            <span style="display: block; font-size: 0.7rem; color: rgba(255,255,255,0.4); margin-top: 2px;">
                                {{ $related->images_count }} {{ Str::plural('artwork', (int) $related->images_count) }}
                                @if($related->venueTemplate) · {{ $related->venueTemplate->name }} @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="ed-section-title">Explore</p>
        <p class="ed-links">
            <a href="{{ route('discover') }}">Browse more 3D exhibitions</a>
            @if($hasUpcomingEvents)
                &middot; <a href="{{ route('gallery.events.index', $gallery->slug) }}">Upcoming events</a>
            @endif
            &middot; <a href="{{ route('artists.index') }}">Browse artists</a>
            &middot; <a href="{{ route('venues.index') }}">Browse venues</a>
        </p>
    </section>
    {{-- No-JS visitors see the details panel as plain flowing content. --}}
    <noscript><style>#exhibition-details { transform: none; visibility: visible; position: static; width: auto; box-shadow: none; border-left: none; border-top: 1px solid rgba(139,92,246,0.3); max-width: 720px; margin: 0 auto; }</style></noscript>
    @endunless

    {{-- ── Tour overlay ──────────────────────────────────────────────────────── --}}
    {{-- A11Y-8 FIX: Tour HUD now has role="group" + aria-label so screen
         readers announce "Tour controls, Previous Pause Next" when focus
         enters the HUD. Each button also has an aria-label (in addition to
         the existing title attribute) because title is not reliably
         announced by screen readers. The tour-counter and tour-title-display
         spans have aria-live="polite" so screen readers announce progress
         as the tour advances. --}}
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

    {{-- ── UI overlay ─────────────────────────────────────────────────────────── --}}
    <div id="ui-layer">
        {{-- Header --}}
        <div class="absolute top-6 left-6">
            <h1 class="text-white text-4xl font-bold drop-shadow-lg mb-2">{{ $gallery->title }}</h1>
            <p class="text-white/80 text-sm max-w-md drop-shadow hidden md:block">
                {{ Str::limit($gallery->description, 120) }}
            </p>
        </div>

        {{-- Tour button + audio toggle + speed indicator --}}
        <div class="absolute top-6 right-6 flex items-center gap-3">
            {{-- P2-16: Audio mute/unmute toggle button --}}
            <button id="audio-toggle"
                aria-label="Mute audio"
                aria-pressed="false"
                style="display:flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;width:44px;height:44px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;font-size:1.1rem;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                🔊
            </button>
            <button id="exhibition-details-btn"
                aria-label="Open exhibition details and artwork list"
                aria-expanded="false"
                aria-controls="exhibition-details"
                style="display:flex;align-items:center;gap:6px;min-height:44px;padding:10px 16px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:rgba(255,255,255,0.75);font-size:0.8rem;font-weight:500;letter-spacing:0.05em;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                <svg style="width:14px;height:14px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 10h16M4 14h10M4 18h10"/></svg>
                INFO
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

        {{-- Controls hint --}}
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

        {{-- Artwork info panel --}}
        {{-- A11Y-2: aria-live="polite" so screen readers announce artwork
             title/description changes when the visitor focuses a new artwork. --}}
        <div id="info-panel" aria-live="polite" aria-atomic="true">
            <h3 id="artwork-title">Artwork Title</h3>
            <p id="artwork-description">Description will appear here</p>

            <div id="artwork-meta" class="mt-3 pt-3 border-t border-white/10" style="display:none;">
                <a id="artwork-artist-link" href="#" target="_blank" class="inline-flex items-center gap-2 text-purple-400 hover:text-purple-300 text-sm font-medium transition mb-2" style="display:none;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span id="artwork-artist-name"></span>
                </a>
                <div id="artwork-details" class="text-xs text-gray-400 space-y-0.5"></div>
                <div id="artwork-price-row" class="mt-2 flex items-center gap-2" style="display:none;">
                    <span id="artwork-price" class="text-green-400 font-semibold text-sm"></span>
                    <span id="artwork-for-sale-badge" class="text-[10px] px-1.5 py-0.5 rounded bg-green-900/40 text-green-400 border border-green-700/30" style="display:none;">FOR SALE</span>
                </div>
                <a id="artwork-external-link" href="#" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-xs text-blue-400 hover:text-blue-300 transition" style="display:none;">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    View on artist's site
                </a>
            </div>

            <div class="mt-3 pt-3 border-t border-white/10">
                {{-- (Task H45 / audit MX8) — Share this artwork button.
                     Generates a deep-link URL with ?artwork=<id> that
                     auto-focuses this artwork when visited. --}}
                <button id="share-artwork-btn" class="inline-flex items-center gap-1.5 text-xs text-purple-400 hover:text-purple-300 transition mb-2" style="display:none;">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    Share this artwork
                </button>
                <p class="text-xs text-gray-400 desktop-hint">Press E to close</p>
                <p class="text-xs text-gray-400 mobile-hint hidden">Double-tap to close</p>
            </div>
        </div>

        {{-- Crosshair --}}
        {{-- A11Y-9: Crosshair is purely visual — pointer-events:none so it
             doesn't intercept clicks, aria-hidden so screen readers skip
             the empty div, and an sr-only text alternative is provided
             below for AT users who want to know what the crosshair is. --}}
        <div id="crosshair" aria-hidden="true"></div>
        <span class="sr-only" aria-hidden="false" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">
            A center crosshair indicates where the camera is pointing. The crosshair turns purple when an artwork is in focus.
        </span>

        {{-- Branding: Studio = Custom Logo | Free = Watermark | Pro = Nothing --}}
        @if ($gallery->user->plan === 'studio' && $gallery->custom_logo_path)
            <div class="ui-interactive" style="position: absolute; bottom: 28px; right: 28px; background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 10px 14px;">
                <img src="{{ asset('storage/' . $gallery->custom_logo_path) }}"
                     alt="Gallery Logo"
                     style="max-height: 50px; max-width: 200px; object-fit: contain; display: block;">
            </div>
        @elseif (!$gallery->user->isPro())
            <a class="ui-interactive" href="/pricing" target="_blank"
               style="position: absolute; bottom: 28px; right: 28px; display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 6px 14px 6px 10px; text-decoration: none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <span style="font-family: system-ui, sans-serif; font-size: 11.5px; font-weight: 500; color: rgba(255,255,255,0.7); letter-spacing: 0.01em; white-space: nowrap;">
                    Created with <span style="color: #a78bfa; font-weight: 600;">Exospace</span> 3D
                </span>
            </a>
        @endif

        {{-- Focus mode indicator --}}
        <div id="focus-indicator" style="position: absolute; top: 30px; left: 50%; transform: translateX(-50%); background: rgba(139, 92, 246, 0.15); backdrop-filter: blur(10px); padding: 0.75rem 1.5rem; border-radius: 20px; border: 1px solid rgba(139, 92, 246, 0.4); color: rgba(139, 92, 246, 1); font-size: 0.875rem; font-weight: 600; letter-spacing: 0.05em; opacity: 0; transition: opacity 0.3s ease; pointer-events: none; z-index: 50;">
            <span class="desktop-text">FOCUS MODE • Press E to Exit</span>
            <span class="mobile-text hidden">FOCUS MODE • Double-tap to Exit</span>
        </div>
    </div>

    {{-- ── Mobile overlay (joystick + look pad + buttons) ────────────────────── --}}
    <div id="mobile-overlay">
        <div id="joystick-zone">
            <div id="joystick-base"><div id="joystick-thumb"></div></div>
        </div>
        <div id="look-zone"></div>
        <button id="sprint-btn">SPRINT</button>
        <button id="speed-dial">1x</button>
        <div id="mobile-hint">Left: move • Right: look • Double-tap: focus</div>
    </div>

    {{-- ── Gallery data injection (consumed by main.js) ──────────────────────── --}}
    <script nonce="@nonce">
        // (Task H35 / audit C4) — WebGL detection. If WebGL is unavailable,
        // show the fallback div and hide the curtain. The 3D viewer won't
        // try to boot.
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

        // PERF-E29 (3D audit — iteration 5): register the service worker on
        // the GALLERY view. It was only registered on the marketing layout,
        // but this page is where the heavy engine assets live (three.js
        // chunk, DRACO/Basis wasm, HDRIs, artwork textures). The SW caches
        // them stale-while-revalidate, so a visitor's second gallery opens
        // with the engine served from disk instantly. Registered after
        // window load so it never competes with the 3D boot for CPU, and
        // failures are silent (progressive enhancement).
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js').catch(function() {});
            });
        }

        // (Task H35 / audit C4) — expose prefers-reduced-motion for the
        // 3D viewer's main.js to consume. When true, the viewer should
        // disable bloom, vignette, camera lean, and tour tweens.
        window.EXOSPACE_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // (Task H35 / audit C4) — hide PerformanceControls panel unless
        // ?debug=1 is in the URL. Previously visible to every visitor.
        window.EXOSPACE_DEBUG = new URLSearchParams(window.location.search).has('debug');

        window.GALLERY_DATA = @json($galleryData);

        @if(app()->environment('local'))
        if (!window.GALLERY_DATA || window.GALLERY_DATA.images.length === 0) {
            console.warn("[DEV] No backend data — using mock data.");
            const mockImages = Array.from({ length: 24 }, (_, i) => ({
                id: i,
                url: `https://picsum.photos/seed/${i + 100}/600/800`,
                aspectRatio: 0.75,
                title: `Artwork Piece ${i + 1}`,
                description: `Description for artwork ${i + 1}.`
            }));
            window.GALLERY_DATA = {
                title: "Exospace Demo Gallery",
                description: "A demo 3D gallery running in standalone mode.",
                wall_texture: "white",
                floor_material: "wood",
                lighting_preset: "bright",
                frame_style: "modern",
                room_layout: "square",
                venue_slug: "white-cube",
                imageCount: mockImages.length,
                audioUrl: null,
                userPlan: "studio",
                images: mockImages
            };
        }
        @else
        if (!window.GALLERY_DATA || window.GALLERY_DATA.images.length === 0) {
            console.warn("Gallery has no artworks — showing empty state.");
            window.GALLERY_DATA.images = [];
            window.GALLERY_DATA._isEmpty = true;
        }
        @endif

        window.EXOSPACE_TRACK_URL = '{{ route("gallery.track", $gallery) }}';
        window.EXOSPACE_SESSION = (function() {
            const k = 'exo_sid_{{ $gallery->id }}';
            let s = sessionStorage.getItem(k);
            if (!s) {
                s = crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2) + Date.now().toString(36);
                sessionStorage.setItem(k, s);
            }
            return s;
        })();

        // Update artwork info panel with artist + metadata
        window.updateArtworkMeta = function(data) {
            const metaPanel    = document.getElementById('artwork-meta');
            const artistLink   = document.getElementById('artwork-artist-link');
            const artistName   = document.getElementById('artwork-artist-name');
            const detailsEl    = document.getElementById('artwork-details');
            const priceRow     = document.getElementById('artwork-price-row');
            const priceEl      = document.getElementById('artwork-price');
            const forSaleBadge = document.getElementById('artwork-for-sale-badge');
            const externalLink = document.getElementById('artwork-external-link');

            if (!metaPanel) return;
            metaPanel.style.display    = 'none';
            artistLink.style.display   = 'none';
            priceRow.style.display     = 'none';
            forSaleBadge.style.display = 'none';
            externalLink.style.display = 'none';
            detailsEl.innerHTML = '';

            const hasArtist   = data.artist && data.artist.name;
            const hasDetails  = data.medium || data.year || data.dimensions || data.edition;
            const hasPrice    = data.formattedPrice || data.price;
            const hasExternal = data.externalUrl;
            if (!hasArtist && !hasDetails && !hasPrice && !hasExternal) return;

            metaPanel.style.display = 'block';
            if (hasArtist) {
                artistName.textContent = data.artist.name;
                artistLink.href = data.artist.url || ('/artist/' + data.artist.slug);
                artistLink.style.display = 'inline-flex';
            }
            const parts = [];
            if (data.medium)     parts.push(data.medium);
            if (data.year)       parts.push(data.year);
            if (data.dimensions) parts.push(data.dimensions);
            if (data.edition)    parts.push(data.edition);
            if (parts.length)    detailsEl.innerHTML = parts.map(p => `<div>${p}</div>`).join('');

            if (hasPrice) {
                priceEl.textContent = data.formattedPrice || ('$' + Number(data.price).toFixed(2));
                priceRow.style.display = 'flex';
                if (data.forSale) forSaleBadge.style.display = 'inline-block';
            } else if (data.forSale) {
                priceEl.textContent = 'Price on request';
                priceRow.style.display = 'flex';
                forSaleBadge.style.display = 'inline-block';
            }
            if (hasExternal) {
                externalLink.href = data.externalUrl;
                externalLink.style.display = 'inline-flex';
            }

            // (Task H45 / audit MX8) — show the "Share this artwork" button
            // with the deep-link URL. Uses the native Web Share API on
            // mobile (opens the share sheet); falls back to clipboard copy.
            const shareBtn = document.getElementById('share-artwork-btn');
            if (shareBtn && data.id) {
                shareBtn.style.display = 'inline-flex';
                shareBtn.dataset.artworkId = data.id;
            }
        };

        // (Task H45 / audit MX8) — share the current artwork via deep-link.
        window.shareArtwork = function() {
            const btn = document.getElementById('share-artwork-btn');
            if (!btn) return;
            const artworkId = btn.dataset.artworkId;
            if (!artworkId) return;

            const baseUrl = window.location.origin + window.location.pathname;
            const shareUrl = baseUrl + '?artwork=' + artworkId;
            const shareText = document.getElementById('artwork-title')?.textContent || 'Check out this artwork';

            // Use native Web Share API if available (mobile browsers)
            if (navigator.share) {
                navigator.share({
                    title: shareText,
                    text: shareText + ' — on Exospace',
                    url: shareUrl,
                }).catch(() => {}); // user cancelled — no-op
            } else {
                // Fallback: copy to clipboard + show toast
                navigator.clipboard?.writeText(shareUrl).then(() => {
                    if (window.toast) {
                        window.toast('Link copied to clipboard!', 'success');
                    }
                }).catch(() => {
                    // Final fallback: open a prompt with the URL
                    window.prompt('Copy this link:', shareUrl);
                });
            }
        };

        // ── CSP-safe event wiring (replaces inline onclick / onsubmit / onmouseover) ──
        // All UI buttons in the gallery viewer used to use inline event
        // handlers (onclick="toggleAudioMute()", etc.) which CSP blocks.
        // Wire them up here via addEventListener on DOMContentLoaded.
        document.addEventListener('DOMContentLoaded', () => {
            // Audio mute toggle
            const audioBtn = document.getElementById('audio-toggle');
            if (audioBtn && typeof window.toggleAudioMute === 'function') {
                audioBtn.addEventListener('click', () => window.toggleAudioMute());
            }
            // Tour button (the one inside the gallery UI, not the curtain)
            const tourBtn = document.getElementById('in-gallery-tour-btn');
            if (tourBtn && typeof window.startGuidedTour === 'function') {
                tourBtn.addEventListener('click', () => window.startGuidedTour());
            }
            // Share artwork button
            const shareBtn = document.getElementById('share-artwork-btn');
            if (shareBtn) {
                shareBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    window.shareArtwork();
                });
            }
            // Newsletter signup form
            const newsletterForm = document.getElementById('newsletter-signup-form');
            if (newsletterForm && typeof window.submitNewsletterSignup === 'function') {
                newsletterForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    window.submitNewsletterSignup(newsletterForm);
                });
            }
            // Skip-intro hover state (replaces inline onmouseover / onmouseout)
            const skipLink = document.getElementById('skip-intro-link');
            if (skipLink) {
                skipLink.addEventListener('mouseenter', () => {
                    skipLink.style.color = 'rgba(255,255,255,0.6)';
                });
                skipLink.addEventListener('mouseleave', () => {
                    skipLink.style.color = 'rgba(255,255,255,0.35)';
                });
            }

            // SEO OS (Iteration 2): exhibition-details slide-over panel.
            // The panel content is always in the DOM (crawlable); this only
            // toggles visibility. Escape closes it; focus moves into the
            // panel on open and back to the trigger on close.
            const edPanel = document.getElementById('exhibition-details');
            const edBtn = document.getElementById('exhibition-details-btn');
            const edClose = edPanel ? edPanel.querySelector('.ed-close') : null;

            function setEdOpen(open) {
                if (!edPanel || !edBtn) return;
                edPanel.classList.toggle('open', open);
                edBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    (edClose || edPanel).focus();
                } else {
                    edBtn.focus();
                }
            }
            if (edBtn && edPanel) {
                edBtn.addEventListener('click', () => setEdOpen(!edPanel.classList.contains('open')));
            }
            if (edClose) {
                edClose.addEventListener('click', () => setEdOpen(false));
            }
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && edPanel && edPanel.classList.contains('open')) {
                    setEdOpen(false);
                }
            });

            // Curtain link opens the same panel (progressive enhancement —
            // without JS the href still anchors to the section).
            const curtainDetailsLink = document.getElementById('curtain-details-link');
            if (curtainDetailsLink && edPanel) {
                curtainDetailsLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    setEdOpen(true);
                });
            }
        });
    </script>
</body>
</html>
