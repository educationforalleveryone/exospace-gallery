@php
    $isEmbed = request()->boolean('embed');
    // (Task H50) — if ?artwork=<id> is in the URL, generate a per-artwork
    // OG image that shows the artwork's image + title + artist.
    $artworkParam = request()->integer('artwork');
    $ogImageUrl = $artworkParam
        ? route('gallery.og-image', $gallery->slug) . '?artwork=' . $artworkParam
        : route('gallery.og-image', $gallery->slug);
    $publicUrl = $gallery->public_url;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- (Task H35 / audit M9) — removed maximum-scale=1.0, user-scalable=no.
         WCAG 2.1 AA (1.4.4 Resize Text) requires users be able to zoom.
         The 3D canvas intercepts touch events regardless; the curtain UI
         (title, description, "Enter" button) should be zoomable. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ Str::limit($gallery->description, 150) }}">

    {{-- Open Graph / social card --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $gallery->title }}">
    <meta property="og:description" content="{{ Str::limit($gallery->description, 150) }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ $publicUrl }}">
    <meta property="og:site_name" content="Exospace">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $gallery->title }}">
    <meta name="twitter:description" content="{{ Str::limit($gallery->description, 150) }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">

    @if($isEmbed)<meta name="robots" content="noindex,nofollow">@endif

    <title>{{ $gallery->title }} | Exospace 3D Gallery</title>

    {{-- P2-11: JSON-LD structured data for search engines (schema.org) --}}
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ExhibitionEvent",
        "name": {{ json_encode($gallery->title) }},
        "description": {{ json_encode(Str::limit($gallery->description, 300)) }},
        "url": {{ json_encode($publicUrl) }},
        "image": {{ json_encode($ogImageUrl) }},
        "organizer": {
            "@type": "Organization",
            "name": "Exospace",
            "url": {{ json_encode(config('app.url')) }}
        },
        "eventStatus": @if($gallery->opens_at && $gallery->opens_at->isFuture()) "https://schema.org/EventScheduled" @else "https://schema.org/EventInProgress" @endif,
        @if($gallery->opens_at)"startDate": {{ json_encode($gallery->opens_at->toIso8601String()) }},@endif
        @if($gallery->closes_at)"endDate": {{ json_encode($gallery->closes_at->toIso8601String()) }},@endif
        "location": {
            "@type": "VirtualLocation",
            "url": {{ json_encode($publicUrl) }}
        }
    }
    </script>
    @if($galleryData['images']->isNotEmpty())
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ItemList",
        "name": {{ json_encode($gallery->title . ' — Artworks') }},
        "url": {{ json_encode($publicUrl) }},
        "numberOfItems": {{ $galleryData['imageCount'] }},
        "itemListElement": [
            @foreach($galleryData['images'] as $i => $img)
            {
                "@type": "ListItem",
                "position": {{ $i + 1 }},
                "item": {
                    "@type": "VisualArtwork",
                    "name": {{ json_encode($img['title'] ?? $img['original_name'] ?? 'Untitled') }}@if(!empty($img['artist'])),
                    "creator": {
                        "@type": "Person",
                        "name": {{ json_encode($img['artist']['name']) }},
                        "url": {{ json_encode($img['artist']['url']) }}
                    }@endif@if(!empty($img['medium'])),
                    "artMedium": {{ json_encode($img['medium']) }}@endif@if(!empty($img['year'])),
                    "dateCreated": {{ json_encode((string)$img['year']) }}@endif@if(!empty($img['dimensions'])),
                    "artworkSurface": {{ json_encode($img['dimensions']) }}@endif@if($img['forSale'] && !empty($img['price'])),
                    "offers": {
                        "@type": "Offer",
                        "price": {{ json_encode((string)$img['price']) }},
                        "priceCurrency": {{ json_encode($img['currency'] ?? 'USD') }}
                    }@endif
                }
            }@if(!$loop->last),@endif
            @endforeach
        ]
    }
    </script>
    @endif

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
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: rgba(139,92,246,0.2); border: 1px solid rgba(139,92,246,0.4);
            color: white; cursor: pointer; transition: all 0.2s;
        }
        .tour-btn:hover { background: rgba(139,92,246,0.4); }
        .tour-btn svg { width: 16px; height: 16px; }
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
        }
        #sprint-btn, #speed-dial {
            position: absolute; right: 24px; pointer-events: auto;
            padding: 12px 18px; border-radius: 999px;
            background: rgba(0,0,0,0.7); border: 1px solid rgba(139,92,246,0.4);
            color: white; font-weight: 700; font-size: 0.85rem;
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
    <script>window.EXOSPACE_EMBED_MODE = true;</script>
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
                    <span id="curtain-progress-text" style="font-size: 0.875rem; color: rgba(255,255,255,0.6);">Preparing exhibition...</span>
                    <span id="curtain-progress-percent" style="font-size: 0.875rem; color: rgba(255,255,255,0.8); font-weight: 600;">0%</span>
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
               style="display: block; margin-top: 1rem; font-size: 0.8rem; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s;"
               onmouseover="this.style.color='rgba(255,255,255,0.6)'"
               onmouseout="this.style.color='rgba(255,255,255,0.35)'">
                Skip intro →
            </a>

            @if($gallery->scheduleEvents()->active()->upcoming()->exists())
            <a href="{{ route('gallery.events.index', $gallery->slug) }}"
               style="display: inline-flex; align-items: center; gap: 8px; margin-top: 1.5rem; padding: 8px 16px; background: rgba(139,92,246,0.15); border: 1px solid rgba(139,92,246,0.4); border-radius: 999px; color: rgba(195,180,255,0.95); font-size: 0.8rem; font-weight: 500; text-decoration: none; transition: all 0.2s ease;">
                <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                View upcoming events
            </a>
            @endif

            @if(!request()->boolean('embed'))
            <form onsubmit="return submitNewsletterSignup(this)"
                  style="max-width: 380px; margin: 2.5rem auto 0; padding: 1rem 1.25rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; backdrop-filter: blur(8px);">
                <p style="font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-bottom: 0.75rem; letter-spacing: 0.04em;">JOIN THE LIST</p>
                <div style="display: flex; gap: 8px;">
                    <input type="email" name="email" required placeholder="your@email.com"
                           style="flex: 1; padding: 8px 12px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-size: 0.85rem; outline: none;">
                    <button type="submit"
                            style="padding: 8px 16px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border: none; border-radius: 8px; color: white; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s;">
                        Subscribe
                    </button>
                </div>
                <p class="newsletter-msg" style="font-size: 0.75rem; margin-top: 0.5rem; min-height: 1rem;"></p>
            </form>
            @endif
        </div>
    </div>

    {{-- ── 3D canvas ──────────────────────────────────────────────────────────── --}}
    <div id="canvas-container"></div>

    {{-- ── Tour overlay ──────────────────────────────────────────────────────── --}}
    <div id="tour-overlay">
        <div id="tour-progress-bar"></div>
        <div id="tour-hud">
            <button class="tour-btn" id="tour-prev-btn" title="Previous">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="tour-btn" id="tour-pause-btn" title="Pause / Resume">
                <svg id="tour-pause-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                <svg id="tour-play-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </button>
            <div id="tour-countdown-ring">
                <svg viewBox="0 0 36 36" width="36" height="36">
                    <circle id="tour-ring-bg" cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="2"/>
                    <circle id="tour-ring-arc" cx="18" cy="18" r="15.9" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" style="stroke-dasharray: 100; stroke-dashoffset: 100;"/>
                </svg>
            </div>
            <span id="tour-counter">1 / 1</span>
            <span id="tour-title-display">—</span>
            <button class="tour-btn" id="tour-next-btn" title="Next">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button class="tour-btn tour-exit-btn" id="tour-exit-btn" title="Exit tour">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
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
                onclick="toggleAudioMute()"
                aria-label="Mute audio"
                aria-pressed="false"
                style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;font-size:1.1rem;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                🔊
            </button>
            <button id="in-gallery-tour-btn"
                onclick="startGuidedTour()"
                style="display:flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:rgba(255,255,255,0.75);font-size:0.8rem;font-weight:500;letter-spacing:0.05em;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);">
                <svg style="width:13px;height:13px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
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
        <div id="info-panel">
            <h3 id="artwork-title">Artwork Title</h3>
            <p id="artwork-description">Description will appear here</p>

            <div id="artwork-meta" class="mt-3 pt-3 border-t border-white/10" style="display:none;">
                <a id="artwork-artist-link" href="#" target="_blank" class="inline-flex items-center gap-2 text-purple-400 hover:text-purple-300 text-sm font-medium transition mb-2" style="display:none;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span id="artwork-artist-name"></span>
                </a>
                <div id="artwork-details" class="text-xs text-gray-500 space-y-0.5"></div>
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
                <button id="share-artwork-btn" onclick="shareArtwork()" class="inline-flex items-center gap-1.5 text-xs text-purple-400 hover:text-purple-300 transition mb-2" style="display:none;">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    Share this artwork
                </button>
                <p class="text-xs text-gray-500 desktop-hint">Press E to close</p>
                <p class="text-xs text-gray-500 mobile-hint hidden">Double-tap to close</p>
            </div>
        </div>

        {{-- Crosshair --}}
        <div id="crosshair"></div>

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
    <script>
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
    </script>
</body>
</html>
