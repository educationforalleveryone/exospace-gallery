<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ Str::limit($gallery->description, 150) }}">
    <title>{{ $gallery->title }} | Exospace 3D Gallery</title>
    
    @vite(['resources/css/app.css'])
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            margin: 0; 
            overflow: hidden; 
            background-color: #000; 
            font-family: system-ui, -apple-system, sans-serif;
        }
        #canvas-container { width: 100vw; height: 100vh; display: block; }
        
        /* Entrance Curtain */
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
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 3rem;
            animation: pulse 2s ease-in-out infinite;
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
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* UI Overlay */
        #ui-layer { position: absolute; inset: 0; pointer-events: none; z-index: 10; }
        .ui-interactive { pointer-events: auto; }
        
        /* Info Panel - Enhanced Glassmorphism */
        #info-panel {
            position: absolute;
            top: 50%;
            right: 40px;
            transform: translateY(-50%);
            background: rgba(10, 10, 20, 0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            padding: 2rem;
            border-radius: 16px;
            width: 380px;
            max-height: 70vh;
            overflow-y: auto;
            word-wrap: break-word;
            color: white;
            border: 1px solid rgba(139, 92, 246, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(255, 255, 255, 0.05) inset;
            opacity: 0;
            transform: translateY(-50%) translateX(30px);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            z-index: 100;
        }

        #info-panel.show {
            opacity: 1;
            transform: translateY(-50%) translateX(0);
            pointer-events: auto;
        }

        #info-panel h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #8b5cf6, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.3;
        }

        #info-panel p {
            font-size: 0.95rem;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.85);
        }

        #info-panel::-webkit-scrollbar {
            width: 6px;
        }

        #info-panel::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        #info-panel::-webkit-scrollbar-thumb {
            background: rgba(139, 92, 246, 0.5);
            border-radius: 3px;
        }

        #info-panel::-webkit-scrollbar-thumb:hover {
            background: rgba(139, 92, 246, 0.7);
        }
        
        /* ✨ PERF: Low-end device overrides */
        .low-end-device #info-panel {
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            background: rgba(0, 0, 0, 0.92);
        }
        
        /* Crosshair */
        #crosshair {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        #crosshair::before,
        #crosshair::after {
            content: '';
            position: absolute;
            background: rgba(255, 255, 255, 0.8);
        }
        #crosshair::before {
            width: 2px;
            height: 100%;
            left: 50%;
            transform: translateX(-50%);
        }
        #crosshair::after {
            height: 2px;
            width: 100%;
            top: 50%;
            transform: translateY(-50%);
        }
        #crosshair.active { opacity: 1; }
        /* Crosshair Purple State (when focused on artwork) */
        #crosshair.focused::before,
        #crosshair.focused::after {
            background: rgba(139, 92, 246, 0.95);
            box-shadow: 0 0 10px rgba(139, 92, 246, 0.6);
        }

        /* ==========================================
           MOBILE IMMERSION SPRINT - UI STYLES
           ========================================== */

        /* Hide desktop controls on mobile */
        @media (pointer: coarse), (hover: none) {
            #desktop-controls {
                display: none !important;
            }
        }

        /* Mobile Overlay Container */
        #mobile-overlay {
            position: fixed;
            inset: 0;
            pointer-events: none; /* Allow clicks to pass through to canvas where no UI exists */
            z-index: 50;
            display: none; /* Shown via JS on mobile detection */
        }

        #mobile-overlay.active {
            display: block;
        }

        /* Ensure interactive elements within overlay receive pointer events */
        #mobile-overlay #speed-dial,
        #mobile-overlay #sprint-btn,
        #mobile-overlay #joystick-zone,
        #mobile-overlay #look-zone {
            pointer-events: auto;
        }

        /* Virtual Joystick Zone (Left 40% of screen) */
        #joystick-zone {
            position: absolute;
            left: 0;
            bottom: 0;
            width: 45%;
            height: 50%;
            pointer-events: auto;
            touch-action: none;
        }

        /* Joystick Base */
        #joystick-base {
            position: absolute;
            left: 60px;
            bottom: 80px;
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            backdrop-filter: blur(10px);
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        #joystick-base.active {
            opacity: 1;
        }

        #joystick-base.inactive {
            opacity: 0.3;
        }

        /* Joystick Thumb/Handle */
        #joystick-thumb {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
            pointer-events: none;
            transition: transform 0.1s ease-out;
        }

        /* Sprint Button (Floating above joystick) */
        #sprint-btn {
            position: absolute;
            left: 90px;
            bottom: 220px;
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: auto;
            touch-action: none;
            backdrop-filter: blur(10px);
            transition: all 0.2s ease;
            user-select: none;
        }

        #sprint-btn:active {
            background: rgba(139, 92, 246, 0.4);
            transform: scale(0.95);
            border-color: rgba(139, 92, 246, 0.8);
        }

        #sprint-btn.active {
            background: rgba(139, 92, 246, 0.6);
            box-shadow: 0 0 20px rgba(139, 92, 246, 0.5);
        }

        #sprint-btn svg {
            width: 28px;
            height: 28px;
            color: white;
            opacity: 0.9;
        }

        /* Look Zone (Right 55% of screen) */
        #look-zone {
            position: absolute;
            right: 0;
            top: 0;
            width: 55%;
            height: 100%;
            pointer-events: auto;
            touch-action: none;
            z-index: 49;
        }

        /* ==========================================
           ISSUE 1B: Modified Speed Toggle (Single Button)
           ========================================== */
        /* Speed Toggle (Top Right) - Repositioned */
        #speed-dial {
            position: absolute;
            right: 20px;
            top: 100px; /* Moved to top, below speed indicator */
            pointer-events: auto;
            z-index: 100; /* Ensure it's above other elements */
        }

        .speed-btn {
            width: 60px;
            height: 60px;
            background: rgba(0, 0, 0, 0.6);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 800;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            transition: all 0.2s ease;
            user-select: none;
            touch-action: manipulation;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .speed-btn.active {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            border-color: rgba(139, 92, 246, 0.6);
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.5);
            transform: scale(1.05);
        }

        .speed-btn:active {
            transform: scale(0.9);
        }

        /* Mobile Interaction Hint */
        #mobile-hint {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-size: 0.9rem;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.5s ease;
            z-index: 100;
        }

        #mobile-hint.show {
            opacity: 1;
        }

        /* Double-tap visual feedback */
        #tap-feedback {
            position: absolute;
            width: 60px;
            height: 60px;
            border: 3px solid rgba(139, 92, 246, 0.8);
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(0);
            pointer-events: none;
            z-index: 60;
        }

        #tap-feedback.animate {
            animation: tapRipple 0.6s ease-out forwards;
        }

        @keyframes tapRipple {
            0% { transform: translate(-50%, -50%) scale(0); opacity: 1; }
            100% { transform: translate(-50%, -50%) scale(2); opacity: 0; }
        }

        /* Prevent text selection on mobile UI */
        #mobile-overlay * {
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        /* iOS specific: Prevent callout menu */
        @supports (-webkit-touch-callout: none) {
            #mobile-overlay {
                -webkit-touch-callout: none;
            }
        }

        /* ==========================================
           ISSUE 2B & 2D: Contextual Hint Visibility Styles
           ========================================== */
        
        /* Show/hide hints based on device */
        @media (pointer: coarse), (hover: none) {
            #info-panel .desktop-hint {
                display: none;
            }
            #info-panel .mobile-hint {
                display: block !important;
            }
        }

        #focus-indicator .mobile-text {
            display: none;
        }

        @media (pointer: coarse), (hover: none) {
            #focus-indicator .desktop-text {
                display: none;
            }
            #focus-indicator .mobile-text {
                display: inline !important;
            }
        }

        /* ==========================================
           GUIDED TOUR — UI STYLES
           ========================================== */
        #tour-overlay {
            position: fixed; inset: 0; z-index: 150;
            pointer-events: none;
            display: none;
        }
        #tour-overlay.active { display: block; }

        /* Top progress bar */
        #tour-progress-bar {
            position: absolute; top: 0; left: 0; height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            transition: width 0.6s ease;
            width: 0%;
        }

        /* Tour HUD — bottom centre */
        #tour-hud {
            position: absolute;
            bottom: 32px; left: 50%; transform: translateX(-50%);
            display: flex; align-items: center; gap: 12px;
            background: rgba(0,0,0,0.72);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 999px;
            padding: 10px 20px;
            pointer-events: auto;
            user-select: none;
        }
        .low-end-device #tour-hud {
            backdrop-filter: none; -webkit-backdrop-filter: none;
            background: rgba(0,0,0,0.90);
        }

        .tour-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.08);
            color: white; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s, transform 0.15s;
            flex-shrink: 0;
        }
        .tour-btn:hover { background: rgba(255,255,255,0.18); transform: scale(1.08); }
        .tour-btn:active { transform: scale(0.93); }
        .tour-btn svg { width: 16px; height: 16px; pointer-events: none; }

        #tour-counter {
            font-size: 0.8rem; color: rgba(255,255,255,0.55);
            min-width: 48px; text-align: center; letter-spacing: 0.04em;
        }
        #tour-title-display {
            font-size: 0.875rem; font-weight: 600; color: white;
            max-width: 220px; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }

        #tour-exit-btn {
            background: rgba(239,68,68,0.15);
            border-color: rgba(239,68,68,0.35);
            color: rgba(239,68,68,0.9);
        }
        #tour-exit-btn:hover { background: rgba(239,68,68,0.3); }

        /* Pause / play icon swap */
        #tour-play-icon  { display: none; }
        #tour-pause-icon { display: block; }
        #tour-hud.paused #tour-play-icon  { display: block; }
        #tour-hud.paused #tour-pause-icon { display: none; }

        /* Auto-advance countdown ring */
        #tour-countdown-ring {
            position: relative; width: 36px; height: 36px; flex-shrink: 0;
        }
        #tour-countdown-ring svg {
            width: 36px; height: 36px;
            transform: rotate(-90deg);
        }
        #tour-countdown-ring circle {
            fill: none; stroke-width: 2.5;
        }
        #tour-ring-bg  { stroke: rgba(255,255,255,0.12); }
        #tour-ring-arc {
            stroke: #8b5cf6;
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.1s linear;
        }

        /* In-gallery T key hint */
        #tour-trigger-btn {
            position: absolute; bottom: 6px; left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.65);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 4px 10px;
            color: rgba(255,255,255,0.6);
            font-size: 0.7rem; letter-spacing: 0.06em;
            pointer-events: auto; cursor: pointer;
            white-space: nowrap;
            transition: opacity 0.3s;
        }
        #tour-trigger-btn:hover { color: white; border-color: rgba(139,92,246,0.5); }

    </style>

    <script type="importmap">
    {
        "imports": {
            "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
            "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
        }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<script src="{{ asset('js/gsap.min.js') }}"></script>
<body>

    <!-- Entrance Curtain (Shown First) -->
    <div id="entrance-curtain">
        <div style="max-width: 800px; text-align: center; padding: 0 2rem;">
            <!-- Logo -->
            <div class="entrance-logo">EXOSPACE</div>
            
            <!-- Gallery Info -->
            <h1 style="font-size: 3rem; font-weight: 800; color: white; margin-bottom: 1rem; line-height: 1.2;">
                {{ $gallery->title }}
            </h1>
            
            @if($gallery->description)
            <p style="font-size: 1.125rem; color: rgba(255,255,255,0.7); margin-bottom: 3rem; max-width: 600px; margin-left: auto; margin-right: auto;">
                {{ $gallery->description }}
            </p>
            @endif
            
            <!-- Stats -->
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

            <!-- 🆕 Loading Progress Bar (Shows during silent preload) -->
            <div id="curtain-progress" style="width: 300px; margin: 0 auto 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span id="curtain-progress-text" style="font-size: 0.875rem; color: rgba(255,255,255,0.6);">Preparing exhibition...</span>
                    <span id="curtain-progress-percent" style="font-size: 0.875rem; color: rgba(255,255,255,0.8); font-weight: 600;">0%</span>
                </div>
                <div style="width: 100%; height: 3px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden;">
                    <div id="curtain-progress-bar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); transition: width 0.3s ease;"></div>
                </div>
            </div>
            
            <!-- Enter Button -->
            <button id="enter-btn" class="entrance-button" style="opacity: 0.5; pointer-events: none;">
                <span style="font-size: 1.125rem; font-weight: 600; letter-spacing: 0.05em;">ENTER EXHIBITION</span>
                <svg style="width: 1.5rem; height: 1.5rem; margin-left: 0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                </svg>
            </button>
            
            <p style="margin-top: 2rem; font-size: 0.875rem; color: rgba(255,255,255,0.4);">
                Use WASD to move • Mouse to look around • Press T for guided tour
            </p>
        </div>
    </div>

    <!-- 3D Canvas -->
    <div id="canvas-container"></div>

    <!-- Guided Tour Overlay -->
    <div id="tour-overlay">
        <div id="tour-progress-bar"></div>
        <div id="tour-hud">
            <!-- Prev -->
            <button class="tour-btn" id="tour-prev-btn" title="Previous artwork">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>

            <!-- Pause / Resume -->
            <button class="tour-btn" id="tour-pause-btn" title="Pause / Resume">
                <svg id="tour-pause-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
                </svg>
                <svg id="tour-play-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
            </button>

            <!-- Countdown ring -->
            <div id="tour-countdown-ring">
                <svg viewBox="0 0 36 36">
                    <circle class="" id="tour-ring-bg" cx="18" cy="18" r="15.9"/>
                    <circle id="tour-ring-arc" cx="18" cy="18" r="15.9"
                        style="stroke-dasharray: calc(2 * 3.14159 * 15.9); stroke-dashoffset: calc(2 * 3.14159 * 15.9);"/>
                </svg>
            </div>

            <!-- Counter + title -->
            <span id="tour-counter">1 / 1</span>
            <span id="tour-title-display">—</span>

            <!-- Next -->
            <button class="tour-btn" id="tour-next-btn" title="Next artwork">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>

            <!-- Exit tour -->
            <button class="tour-btn tour-exit-btn" id="tour-exit-btn" title="Exit tour">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- UI Overlay -->
    <div id="ui-layer">
        <!-- Header -->
        <div class="absolute top-6 left-6">
            <h1 class="text-white text-4xl font-bold drop-shadow-lg mb-2">{{ $gallery->title }}</h1>
            <p class="text-white/80 text-sm max-w-md drop-shadow hidden md:block">
                {{ Str::limit($gallery->description, 120) }}
            </p>
        </div>

        <!-- Speed Indicator + Tour Button -->
        <div class="absolute top-6 right-6 flex items-center gap-3">
            <button id="in-gallery-tour-btn"
                onclick="startGuidedTour()"
                style="display:flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(0,0,0,0.70);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:rgba(255,255,255,0.75);font-size:0.8rem;font-weight:500;letter-spacing:0.05em;cursor:pointer;transition:all 0.2s ease;backdrop-filter:blur(8px);"
                onmouseenter="this.style.borderColor='rgba(139,92,246,0.6)';this.style.color='white'"
                onmouseleave="this.style.borderColor='rgba(255,255,255,0.15)';this.style.color='rgba(255,255,255,0.75)'">
                <svg style="width:13px;height:13px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                TOUR
            </button>
            <div class="bg-black/70 backdrop-blur-md px-4 py-2 rounded-lg border border-white/10" id="speed-indicator">
                <p class="text-white/90 text-sm font-mono">
                    Speed: <span class="text-blue-400 font-bold" id="speed-value">1x</span>
                </p>
            </div>
        </div>

        <!-- Controls Info -->
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

        <!-- Artwork Info Panel -->
        <div id="info-panel">
            <h3 class="text-xl font-bold mb-2" id="artwork-title">Artwork Title</h3>
            <p class="text-gray-400 text-sm" id="artwork-description">Description will appear here</p>
            
            <!-- ISSUE 2A: Modified HTML for Close Instructions -->
            <div class="mt-3 pt-3 border-t border-white/10" id="info-panel-close-hint">
                <p class="text-xs text-gray-500 desktop-hint">Press E to close</p>
                <p class="text-xs text-gray-500 mobile-hint hidden">Double-tap to close</p>
            </div>
        </div>

        <!-- Crosshair -->
        <div id="crosshair"></div>

        <!-- Branding: Studio = Custom Logo | Free = Watermark | Pro = Nothing -->
        @if ($gallery->user->plan === 'studio' && $gallery->custom_logo_path)
            <!-- Studio: Custom Logo -->
            <div class="ui-interactive" style="
                position: absolute;
                bottom: 28px;
                right: 28px;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 12px;
                padding: 10px 14px;
                transition: background 0.2s ease;
            " onmouseenter="this.style.background='rgba(0,0,0,0.65)'" onmouseleave="this.style.background='rgba(0,0,0,0.45)'">
                <img src="{{ asset('storage/' . $gallery->custom_logo_path) }}" 
                     alt="Gallery Logo" 
                     style="max-height: 50px; max-width: 200px; object-fit: contain; display: block;">
            </div>
        @elseif (!$gallery->user->isPro())
            <!-- Free: Exospace Watermark -->
            <div class="ui-interactive" style="
                position: absolute;
                bottom: 28px;
                right: 28px;
                display: flex;
                align-items: center;
                gap: 8px;
                background: rgba(0,0,0,0.45);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 20px;
                padding: 6px 14px 6px 10px;
                text-decoration: none;
                transition: background 0.2s ease;
            " onmouseenter="this.style.background='rgba(0,0,0,0.65)'" onmouseleave="this.style.background='rgba(0,0,0,0.45)'" href="/pricing" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <span style="font-family: system-ui, sans-serif; font-size: 11.5px; font-weight: 500; color: rgba(255,255,255,0.7); letter-spacing: 0.01em; white-space: nowrap;">
                    Created with <span style="color: #a78bfa; font-weight: 600;">Exospace</span> 3D
                </span>
            </div>
        @endif
        <!-- Pro: Clean view (no branding) -->

        <!-- Focus Mode Indicator -->
        <div id="focus-indicator" style="
            position: absolute;
            top: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(139, 92, 246, 0.15);
            backdrop-filter: blur(10px);
            padding: 0.75rem 1.5rem;
            border-radius: 20px;
            border: 1px solid rgba(139, 92, 246, 0.4);
            color: rgba(139, 92, 246, 1);
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            z-index: 50;
        ">
            <!-- ISSUE 2C: Modified HTML for Focus Indicator -->
            <span class="desktop-text">🎯 FOCUS MODE • Press E to Exit</span>
            <span class="mobile-text hidden">🎯 FOCUS MODE • Double-tap to Exit</span>
        </div>

        <!-- ==========================================
             MOBILE IMMERSION SPRINT - TOUCH CONTROLS
             ========================================== -->
        <div id="mobile-overlay">
            <!-- Left Zone: Joystick -->
            <div id="joystick-zone">
                <div id="joystick-base" class="inactive">
                    <div id="joystick-thumb"></div>
                </div>
            </div>
            
            <!-- Sprint Button -->
            <div id="sprint-btn" role="button" aria-label="Sprint">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            
            <!-- Right Zone: Look Pad -->
            <div id="look-zone"></div>
            
            <!-- ISSUE 1A: Modified HTML (Speed Toggle Single Button) -->
            <!-- Speed Toggle (Single Button) -->
            <div id="speed-dial">
                <button class="speed-btn active" id="speed-toggle-btn" data-speed="0">1x</button>
            </div>
            
            <!-- BONUS FIX: Updated Mobile Hint Text -->
            <!-- Mobile Hint (Contextual) -->
            <div id="mobile-hint">
                <p>👆 Double-tap artwork to view details</p>
            </div>
            
            <!-- Tap Feedback Animation -->
            <div id="tap-feedback"></div>
        </div>
    </div>

    <!-- Gallery Data Injection -->
    <script>
        // Laravel Data Injection
        window.GALLERY_DATA = @json($galleryData);
        
        // ==========================================
        // MOCK DATA FALLBACK (For standalone testing)
        // ==========================================
        // If running directly in browser without Laravel backend, use this data:
        if (!window.GALLERY_DATA || window.GALLERY_DATA.images.length === 0) {
            console.warn("No backend data found. Using mock data for testing.");
            const mockImages = Array.from({ length: 24 }, (_, i) => ({
                id: i,
                url: `https://picsum.photos/seed/${i + 100}/600/800`,
                aspectRatio: 0.75,
                title: `Artwork Piece ${i + 1}`,
                description: `This is a detailed description for artwork number ${i + 1}.`
            }));
            
            window.GALLERY_DATA = {
                title: "Exospace Demo Gallery",
                description: "A demo 3D gallery running in standalone mode. Variable speed and dynamic proximity lighting enabled.",
                wall_texture: "white",
                floor_material: "wood",
                lighting_preset: "bright",
                frame_style: "modern",
                room_layout: "square", // Options: 'square', 'corridor', 'l-shape', 'rotunda'
                imageCount: mockImages.length,
                audioUrl: null,
                'userPlan': 'studio',
                'customLogoUrl': 'https://via.placeholder.com/200x50.png?text=Studio+Logo',
                images: mockImages
            };
        }
        console.log('🎨 Gallery Loaded:', window.GALLERY_DATA);
    </script>

    <!-- Main Application -->
    <script type="module">
        import * as THREE from 'three';
        import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
        import { RGBELoader } from 'three/addons/loaders/RGBELoader.js';
        // DO NOT import AudioLoader here. It is already inside the 'THREE' object above.

        // Alias for global data access to match requested code snippets
        const galleryData = window.GALLERY_DATA;

        // ===================================
        // CONFIGURATION
        // ===================================
        const CONFIG = {
            camera: {
                fov: 75,
                near: 0.1,
                far: 100,
                height: 1.6,
                // ✨ NEW: Physics-based movement parameters
                damping: 10.0,           // Higher = heavier stop feel (friction)
                acceleration: 40.0,      // How fast you reach top speed
                maxSpeed: 3.0,          // Maximum velocity cap
                maxLean: 0.02,          // Maximum camera roll angle (radians) for cinematic tilt
                leanSpeed: 0.1          // How fast the camera tilts into turns
            },
            // SECTION 2: Updated Movement & Lighting Config
            movement: {
                baseSpeed: 0.1,
                speedMultipliers: [1, 2, 4, 8], // 1x, 2x, 4x, 8x
                currentSpeedIndex: 0,
                sprintMultiplier: 1.5
            },
            room: {
                wallHeight: 4,
                artworkSpacing: 3.5,
                minWallLength: 8,
                wallDepth: 0.3
            },
            // ==========================================
            // FIX 2: Drastically Reduce Bright & Dramatic (Keep Moody Perfect)
            // ==========================================
            lighting: {
                bright: { 
                    ambient: 0.2,   // ✨ DRASTICALLY REDUCED (was 0.3)
                    spot: 0.45,     // ✨ DRASTICALLY REDUCED (was 0.6)
                    ceiling: 0xffffff, 
                    fillLight: 0.12, // ✨ DRASTICALLY REDUCED (was 0.2)
                    proximityDistance: 5,
                    hdri: '/assets/textures/env/studio.hdr',
                    envIntensity: 0.25,  // ✨ DRASTICALLY REDUCED (was 0.4)
                    toneMappingExposure: 0.5 // ✨ DRASTICALLY REDUCED (was 0.65)
                },
                moody: { 
                    ambient: 0.18,   // ✨ KEEP AS IS (you like this)
                    spot: 0.5,       // ✨ KEEP AS IS
                    ceiling: 0xe8e8e8, 
                    fillLight: 0.15, // ✨ KEEP AS IS
                    proximityDistance: 5,
                    hdri: '/assets/textures/env/rural_evening.hdr',
                    envIntensity: 0.3,   // ✨ KEEP AS IS
                    toneMappingExposure: 0.55 // ✨ KEEP AS IS
                },
                dramatic: { 
                    ambient: 0.12,   // ✨ DRASTICALLY REDUCED (was 0.1, but increased slightly for visibility)
                    spot: 0.6,       // ✨ REDUCED (was 0.8)
                    ceiling: 0x2a2a2a, 
                    fillLight: 0.06, // ✨ DRASTICALLY REDUCED (was 0.08)
                    proximityDistance: 5,
                    hdri: '/assets/textures/env/night.hdr',
                    envIntensity: 0.3,   // ✨ REDUCED (was 0.4)
                    toneMappingExposure: 0.5 // ✨ DRASTICALLY REDUCED (was 0.6)
                }
            },
            performance: {
                autoDetectQuality: true,
                lowEndThreshold: 30, // FPS threshold
                textureMaxSize: 2048,
                shadowsEnabled: false
            }
        };

        // ===================================
        // TEXTURE CONFIGURATION
        // ===================================
        const TEXTURE_PATHS = {
            walls: {
                white: '/assets/textures/walls/white.jpg',
                concrete: '/assets/textures/walls/concrete.jpg',
                brick: '/assets/textures/walls/brick.jpg',
                wood: '/assets/textures/walls/wood.jpg'
            },
            floors: {
                wood: '/assets/textures/floors/wood.jpg',
                marble: '/assets/textures/floors/marble.jpg',
                concrete: '/assets/textures/floors/concrete.jpg'
            }
        };

        // ===================================
        // CORE SCENE SETUP
        // ===================================
        class GalleryScene {
            constructor() {
                this.container = document.getElementById('canvas-container');
                this.loadingProgress = 0;
                this.textures = {};
                this.artworks = [];
                // 🎬 CINEMATIC FOCUS SYSTEM - State Management
                this.isInspecting = false;
                this.originalCameraPos = new THREE.Vector3();
                this.originalCameraQuat = new THREE.Quaternion();
                this.focusTween = null;
                this.raycaster = new THREE.Raycaster();
                this.mouse = new THREE.Vector2();
                this.focusedArtwork = null;
                
                // ✨ NEW: Physics-based movement system
                this.velocity = new THREE.Vector3();              // Current velocity
                this.direction = new THREE.Vector3();             // Movement direction
                this.lookDirection = new THREE.Vector3();         // Camera look direction
                this.clock = new THREE.Clock();                   // Delta time tracker
                this.currentLean = 0;                             // Current camera roll angle
                
                // ✨ PERF: Pre-allocated reusable objects (avoid GC pressure every frame)
                this._reusableEuler = new THREE.Euler(0, 0, 0, 'YXZ');
                this._reusableVector = new THREE.Vector2(0, 0);
                this._lightingFrameCount = 0;
                // ⚡ PERF: Low-end state flags (set properly in detectLowEnd / _applyLowEndSettings)
                this.isLowEnd = false;
                this._skipHdri = false;
                this._maxAnisotropy = undefined; // undefined = auto (high-end); 1 = low-end
                this._lowEndFrameSkip = false;   // toggled each frame for 30fps cap
                this._lShapeBounds = null;       // set only for l-shape layout
                
                // ✨ NEW: SFX System Properties
                this.sfx = {}; // Stores all sound effect audio objects
                this.footstepTimer = 0; // Tracks time since last footstep
                this.lastStepTime = 0; // Timestamp of last footstep played
                this.sfxEnabled = true; // Global SFX toggle (can be controlled later)
                this.isSprinting = false; // Track sprint state for footsteps
                this.speedMultiplier = 1; // Track speed multiplier for footsteps
                
                this.init();
                this.initAudio();        // CHANGE 3: Call initAudio() in Constructor
            }

            init() {
                // Scene
                this.scene = new THREE.Scene();
                this.scene.background = new THREE.Color(0x0a0a0a);
                
                // ⚡ FIX L: On low-end, tighter fog + camera far plane culls more geometry
                const lowEndEarly = this._earlyLowEndCheck();
                this.scene.fog = new THREE.Fog(0x0a0a0a, lowEndEarly ? 5 : 10, lowEndEarly ? 14 : 30);

                // Camera — tighter far plane on low-end matches the fog distance
                this.camera = new THREE.PerspectiveCamera(
                    CONFIG.camera.fov,
                    window.innerWidth / window.innerHeight,
                    CONFIG.camera.near,
                    lowEndEarly ? 20 : CONFIG.camera.far
                );
                
                // Start in center of room at eye level
                this.camera.position.set(0, CONFIG.camera.height, 0);

                // ⚡ PERF FIX 1: Pre-detect low-end hardware BEFORE creating renderer
                // so we can set antialias=false early (can't change after creation).
                // Uses CPU cores + RAM — no WebGL context needed at this stage.
                const earlyLowEnd = this._earlyLowEndCheck();

                // Renderer — antialias off on low-end saves 2–4x fill rate
                this.renderer = new THREE.WebGLRenderer({ 
                    antialias: !earlyLowEnd,
                    powerPreference: earlyLowEnd ? 'low-power' : 'high-performance'
                });
                this.renderer.setSize(window.innerWidth, window.innerHeight);
                // ⚡ PERF FIX 2: Cap pixel ratio at 1 on low-end (biggest single win on mobile)
                this.renderer.setPixelRatio(earlyLowEnd ? 1 : Math.min(window.devicePixelRatio, 2));
                this.renderer.shadowMap.enabled = CONFIG.performance.shadowsEnabled;
                this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

                this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
                this.renderer.toneMappingExposure = 0.8;
                this.renderer.outputColorSpace = THREE.SRGBColorSpace;

                this.container.appendChild(this.renderer.domElement);
                
                // ✨ PERF: Stop render loop when tab is hidden (saves battery + CPU)
                this._isVisible = true;
                document.addEventListener('visibilitychange', () => {
                    this._isVisible = !document.hidden;
                });
                
                // Full GPU-aware detection (now that renderer context exists)
                this.detectLowEnd();

                // Controls
                this.setupControls();
                
                // Load assets then build
                this.loadAssets();
            }

            // ⚡ PERF FIX 1 (part 2): CPU/RAM check that runs BEFORE renderer creation
            _earlyLowEndCheck() {
                if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) return true;
                if (navigator.deviceMemory && navigator.deviceMemory < 4) return true;
                return false;
            }

            // Full hardware tier detection (GPU string + FPS benchmark)
            detectLowEnd() {
                let isLowEnd = this._earlyLowEndCheck(); // start from pre-check result
                const reasons = [];

                // --- CHECK 1: CPU core count ---
                if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) {
                    isLowEnd = true;
                    reasons.push(`CPU cores: ${navigator.hardwareConcurrency}`);
                }

                // --- CHECK 2: GPU string analysis ---
                try {
                    const gl = this.renderer.getContext();
                    const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                    if (debugInfo) {
                        const rendererStr = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '';
                        const vendorStr   = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL)   || '';
                        console.log('🎮 GPU:', rendererStr, '|', vendorStr);

                        // Software / fallback renderers (no real GPU)
                        const isSoftware = /Microsoft Basic Render|SwiftShader|llvmpipe|softpipe|ANGLE.*Basic/i.test(rendererStr);

                        // Budget mobile GPUs
                        const isBudgetMobile = /Mali-[34567]|Mali-T|Adreno [23]|Adreno [45]0[0-5]|PowerVR SGX|PowerVR G6|VideoCore/i.test(rendererStr);

                        // Intel integrated (older gen — HD 3000/4000/5xx series)
                        const isOldIntel = /Intel.*HD Graphics [234]\d{3}|Intel.*HD Graphics 5[0-4]\d|Intel.*GMA/i.test(rendererStr);

                        if (isSoftware) {
                            isLowEnd = true;
                            reasons.push(`Software renderer: ${rendererStr}`);
                        }
                        if (isBudgetMobile) {
                            isLowEnd = true;
                            reasons.push(`Budget mobile GPU: ${rendererStr}`);
                        }
                        if (isOldIntel) {
                            isLowEnd = true;
                            reasons.push(`Old Intel iGPU: ${rendererStr}`);
                        }
                    }
                } catch (e) {
                    // Can't read GPU info — be conservative on unknown hardware
                    reasons.push('GPU info unavailable');
                    // Don't force low-end just because the extension is missing
                }

                // --- CHECK 3: Device memory (if available) ---
                if (navigator.deviceMemory && navigator.deviceMemory < 4) {
                    isLowEnd = true;
                    reasons.push(`RAM: ${navigator.deviceMemory}GB`);
                }

                // --- CHECK 4: Runtime FPS benchmark ---
                // We do a quick 30-frame FPS sample 2 seconds after load.
                // If the real FPS is below threshold, downgrade mid-session.
                this._scheduleFpsBenchmark();

                this.isLowEnd = isLowEnd;

                if (isLowEnd) {
                    console.log('⚡ Low-end mode:', reasons.join(', '));
                    this._applyLowEndSettings();
                } else {
                    console.log('🚀 High-end mode: full quality enabled');
                }

                return isLowEnd;
            }

            // Apply all low-end quality reductions in one place
            _applyLowEndSettings() {
                this.renderer.setPixelRatio(1);
                this.renderer.shadowMap.enabled = false;
                // Reduce fog distance to draw fewer objects
                if (this.scene.fog) {
                    this.scene.fog.near = 5;
                    this.scene.fog.far = 14;
                }
                document.body.classList.add('low-end-device');
                this.isLowEnd = true;
                // ⚡ PERF FIX 3: Skip HDRI on low-end — it's a 10MB file that burns
                // GPU memory and bandwidth for a reflection benefit barely visible at low res.
                this._skipHdri = true;
                // ⚡ PERF FIX 4: Cap anisotropy at 1 — budget GPUs expose high values
                // but paying for them tanks fillrate. 1 = nearest-mipmap, essentially free.
                this._maxAnisotropy = 1;
            }

            // FPS benchmark: sample real frame rate after warmup, auto-downgrade if needed
            _scheduleFpsBenchmark() {
                // ⚡ FIX J: If already flagged low-end, skip the benchmark entirely.
                // No point burning 3+ seconds of rAF budget to confirm what we already know.
                if (this.isLowEnd) return;

                let frameCount = 0;
                let startTime = null;
                const SAMPLE_FRAMES = 20;  // was 60 — 20 frames is plenty for a reliable average
                const FPS_THRESHOLD = 35;
                const WARMUP_MS = 2000;    // was 3000 — scene is loaded well within 2s

                const measureFrame = (timestamp) => {
                    if (!startTime) startTime = timestamp;

                    const elapsed = timestamp - startTime;
                    if (elapsed < WARMUP_MS) {
                        requestAnimationFrame(measureFrame);
                        return;
                    }

                    frameCount++;

                    if (frameCount >= SAMPLE_FRAMES) {
                        const measuredFps = frameCount / ((timestamp - startTime - WARMUP_MS) / 1000);
                        if (measuredFps < FPS_THRESHOLD && !this.isLowEnd) {
                            this._applyLowEndSettings();
                        }
                        return;
                    }

                    requestAnimationFrame(measureFrame);
                };

                requestAnimationFrame(measureFrame);
            }

            // SECTION 3: Replace setupControls() method entirely
            setupControls() {
                this.controls = new PointerLockControls(this.camera, document.body);
                
                // Movement state
                this.moveState = {
                    forward: false,
                    backward: false,
                    left: false,
                    right: false,
                    sprint: false
                };

                // Speed control
                this.currentSpeedMultiplier = CONFIG.movement.speedMultipliers[CONFIG.movement.currentSpeedIndex];

                // Keyboard events
                document.addEventListener('keydown', (e) => {
                    switch(e.code) {
                        case 'KeyW': this.moveState.forward = true; break;
                        case 'KeyS': this.moveState.backward = true; break;
                        case 'KeyA': this.moveState.left = true; break;
                        case 'KeyD': this.moveState.right = true; break;
                        case 'ShiftLeft': this.moveState.sprint = true; break;
                        case 'KeyE': this.toggleArtworkInfo(); break;
                        case 'KeyT':
                            // Handled globally outside class (see bottom of script)
                            break;
                        
                        // Speed multipliers
                        case 'Digit1': this.setSpeedMultiplier(0); break; // 1x
                        case 'Digit2': this.setSpeedMultiplier(1); break; // 2x
                        case 'Digit3': this.setSpeedMultiplier(2); break; // 4x
                        case 'Digit4': this.setSpeedMultiplier(3); break; // 8x
                    }
                });

                document.addEventListener('keyup', (e) => {
                    switch(e.code) {
                        case 'KeyW': this.moveState.forward = false; break;
                        case 'KeyS': this.moveState.backward = false; break;
                        case 'KeyA': this.moveState.left = false; break;
                        case 'KeyD': this.moveState.right = false; break;
                        case 'ShiftLeft': this.moveState.sprint = false; break;
                    }
                });

                // Pointer lock events
                this.controls.addEventListener('lock', () => {
                    document.getElementById('crosshair').classList.add('active');
                });

                this.controls.addEventListener('unlock', () => {
                    document.getElementById('crosshair').classList.remove('active');
                });

                // Click to lock
                this.container.addEventListener('click', () => {
                    this.controls.lock();
                });

                // Window resize
                window.addEventListener('resize', () => {
                    this.camera.aspect = window.innerWidth / window.innerHeight;
                    this.camera.updateProjectionMatrix();
                    this.renderer.setSize(window.innerWidth, window.innerHeight);
                });
                
                // Initialize mobile controls if detected
                this.setupMobileControls();
            }

            setSpeedMultiplier(index) {
                CONFIG.movement.currentSpeedIndex = index;
                this.currentSpeedMultiplier = CONFIG.movement.speedMultipliers[index];
                
                // Update UI
                const speedDisplay = document.getElementById('speed-value');
                if (speedDisplay) {
                    speedDisplay.textContent = `${this.currentSpeedMultiplier}x`;
                    
                    // Flash animation
                    speedDisplay.parentElement.parentElement.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        speedDisplay.parentElement.parentElement.style.transform = 'scale(1)';
                    }, 200);
                }
                
                console.log(`🏃 Speed set to ${this.currentSpeedMultiplier}x`);
            }

            async loadAssets() {
                const textureLoader = new THREE.TextureLoader();
                const data = window.GALLERY_DATA;

                // We need the preset early to load the correct HDRI
                const preset = data.lighting_preset || 'bright';
                
                // ✨ FIX: Store as instance variable so other methods can access it
                this.lightingPreset = preset;
                this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                
                this.updateProgress(5, 'Initializing textures...');

                try {
                    const promises = [];
                    let loadedCount = 0;

                    // ⚡ PERF FIX 4 (enforcement): anisotropy capped to 1 on low-end
                    const safeAnisotropy = this._maxAnisotropy !== undefined
                        ? this._maxAnisotropy
                        : Math.min(this.renderer.capabilities.getMaxAnisotropy(), 4);

                    const configureTexture = (texture) => {
                        texture.colorSpace = THREE.SRGBColorSpace;
                        texture.generateMipmaps = !this.isLowEnd; // ⚡ Skip mipmaps on low-end
                        texture.anisotropy = safeAnisotropy;
                        return texture;
                    };

                    // ✨ CHANGE 5: PERF: HDRI loads async in background — doesn't block gallery build
                    // loadEnvironmentMap() is called after buildGallery() completes

                    // 1. Load Wall Texture
                    this.updateProgress(10, 'Loading wall texture...');
                    const wallPath = TEXTURE_PATHS.walls[data.wall_texture] || TEXTURE_PATHS.walls.white;
                    promises.push(new Promise(resolve => {
                        textureLoader.load(
                            wallPath, 
                            (tex) => {
                                this.textures.wall = configureTexture(tex);
                                loadedCount++;
                                this.updateProgress(15, `Loaded ${data.wall_texture} wall texture`);
                                resolve();
                            },
                            undefined,
                            (err) => {
                                console.warn(`Wall texture failed, using fallback color`);
                                this.textures.wall = null;
                                resolve();
                            }
                        );
                    }));

                    // 2. Load Floor Texture
                    this.updateProgress(20, 'Loading floor texture...');
                    const floorPath = TEXTURE_PATHS.floors[data.floor_material] || TEXTURE_PATHS.floors.wood;
                    promises.push(new Promise(resolve => {
                        textureLoader.load(
                            floorPath, 
                            (tex) => {
                                this.textures.floor = configureTexture(tex);
                                loadedCount++;
                                this.updateProgress(25, `Loaded ${data.floor_material} floor texture`);
                                resolve();
                            },
                            undefined,
                            (err) => {
                                console.warn(`Floor texture failed, using fallback color`);
                                this.textures.floor = null;
                                resolve();
                            }
                        );
                    }));

                    await Promise.all(promises);
                    promises.length = 0;

                    // 🎨 Load canvas normal map for tactile art effect
                    // ⚡ PERF FIX 7: Skip normal map entirely on low-end — saves a texture sample per fragment
                    promises.push(new Promise(resolve => {
                        if (this.isLowEnd) { resolve(); return; } // skip on budget hardware
                        textureLoader.load('/assets/textures/shared/canvas_normal.jpg', (tex) => {
                            // Enable seamless tiling across artwork surfaces
                            tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
                            
                            // Store in textures collection
                            this.textures.canvasNormal = tex;
                            
                            console.log('✅ Canvas normal map loaded');
                            resolve();
                        }, undefined, (error) => {
                            console.error('❌ Failed to load canvas normal map:', error);
                            // Continue without normal map if it fails
                            resolve();
                        });
                    }));

                    await Promise.all(promises);
                    promises.length = 0;

                    // ✨ CHANGE 6: 3. Load Artworks — directly as THREE.Texture (no canvas intermediate)
                    this.updateProgress(30, 'Loading artwork...');
                    this.artworkImages = [];
                    
                    const artworkPromises = data.images.map((img, index) => {
                        return new Promise((resolve) => {
                            textureLoader.load(
                                img.url,
                                (texture) => {
                                    texture.colorSpace = THREE.SRGBColorSpace;
                                    texture.generateMipmaps = !this.isLowEnd; // ⚡ skip on low-end
                                    texture.anisotropy = safeAnisotropy;
                                    
                                    // Derive aspect ratio from texture image
                                    const aspectRatio = img.aspectRatio || 
                                        (texture.image.width / texture.image.height) || 1;
                                    
                                    this.artworkImages.push({
                                        id: img.id,
                                        texture: texture,      // ✨ Store texture directly
                                        aspectRatio: aspectRatio,
                                        title: img.title,
                                        description: img.description
                                    });
                                    loadedCount++;
                                    
                                    const percent = 30 + ((index + 1) / data.images.length) * 60;
                                    this.updateProgress(
                                        percent,
                                        `Loading artwork ${index + 1}/${data.images.length}`
                                    );
                                    resolve();
                                },
                                undefined,
                                (err) => {
                                    resolve(); // Skip failed images silently
                                }
                            );
                        });
                    });

                    await Promise.all(artworkPromises);
                    
                    this.updateProgress(95, 'Building gallery...');
                    console.log(`✅ Loaded ${loadedCount} assets successfully`);
                    
                    this.buildGallery();
                    
                    this.updateProgress(100, 'Complete!');
                    setTimeout(() => this.hideLoader(), 500);
                    
                } catch (error) {
                    console.error('Critical asset loading error:', error);
                    this.hideLoader();
                }
            }

            // CHANGE 2: Add Audio Methods to GalleryScene Class
            /**
             * Initialize background music (Three.js Audio)
             */
            initAudio() {
                console.log('🔊 Initializing audio system...');
                
                // Create audio listener and attach to camera
                this.listener = new THREE.AudioListener();
                this.camera.add(this.listener);
                
                // ✨ NEW: Load SFX Buffers
                console.log('🎧 Loading sound effects...');
                
                const sfxLoader = new THREE.AudioLoader();
                
                // Load footstep sound
                sfxLoader.load('/assets/audio/sfx/footstep.mp3', (buffer) => {
                    this.sfx.footstep = new THREE.Audio(this.listener);
                    this.sfx.footstep.setBuffer(buffer);
                    this.sfx.footstep.setVolume(0.25); // Subtle footsteps
                    this.sfx.footstep.setPlaybackRate(1.0);
                    console.log('✅ Footstep SFX loaded');
                }, undefined, (error) => {
                    console.warn('⚠️ Failed to load footstep.mp3:', error);
                });
                
                // Load interaction click sound
                sfxLoader.load('/assets/audio/sfx/interaction_click.mp3', (buffer) => {
                    this.sfx.click = new THREE.Audio(this.listener);
                    this.sfx.click.setBuffer(buffer);
                    this.sfx.click.setVolume(0.4); // Crisp and clear
                    console.log('✅ Interaction click SFX loaded');
                }, undefined, (error) => {
                    console.warn('⚠️ Failed to load interaction_click.mp3:', error);
                });
                
                // Load background music if configured
                if (!galleryData.audioUrl) {
                    console.log('No background music configured for this gallery');
                    return;
                }
                
                console.log('🎵 Loading background music from:', galleryData.audioUrl);
                
                // Create positional audio source
                this.sound = new THREE.Audio(this.listener);
                
                // Load audio file using the core THREE object
                const audioLoader = new THREE.AudioLoader(); 
                audioLoader.load(
                    galleryData.audioUrl,
                    (buffer) => {
                        this.sound.setBuffer(buffer);
                        this.sound.setLoop(true);
                        this.sound.setVolume(0.5); // 50% volume
                        this.audioReady = true;
                        console.log('✅ Background music loaded and ready');
                    },
                    (progress) => {
                        console.log('🎵 Music loading:', Math.round((progress.loaded / progress.total) * 100) + '%');
                    },
                    (error) => {
                        console.error('❌ Error loading background music:', error);
                    }
                );
            }

            /**
             * Play background music (call after user interaction)
             */
            playAudio() {
                if (!this.audioReady || !this.sound) {
                    console.log('Audio not ready or not available');
                    return;
                }
                
                if (this.sound.isPlaying) {
                    console.log('Audio already playing');
                    return;
                }
                
                try {
                    this.sound.play();
                    console.log('Audio playback started');
                } catch (error) {
                    console.error('Error playing audio:', error);
                }
            }

            buildGallery() {
                const data = window.GALLERY_DATA;

                this.lightingPreset = data.lighting_preset;
                this.setupLighting(data.lighting_preset);

                // Dispatch to the correct room builder based on layout
                const layout = data.room_layout || 'square';
                if      (layout === 'corridor') this.createRoomCorridor(data);
                else if (layout === 'l-shape')  this.createRoomLShape(data);
                else if (layout === 'rotunda')  this.createRoomRotunda(data);
                else                            this.createRoom(data); // square (default)

                this.placeArtworks(data);
                this.animate();
                this.loadEnvironmentMap();
            }

            // ✨ PERF: Non-blocking HDRI loader — gallery renders first, env fades in after
            loadEnvironmentMap() {
                // ⚡ PERF FIX 3 (enforcement): Don't load the heavy HDRI on low-end hardware.
                // Standard point lights already light the scene well without it.
                if (this._skipHdri) return;

                const preset = this.lightingPreset || 'bright';
                const lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                const hdriPath = lightingConfig.hdri;
                
                if (!hdriPath) return;
                
                const rgbeLoader = new RGBELoader();
                rgbeLoader.load(
                    hdriPath,
                    (texture) => {
                        texture.mapping = THREE.EquirectangularReflectionMapping;
                        this.scene.environment = texture;
                        
                        if (lightingConfig.envIntensity !== undefined) {
                            this.scene.environmentIntensity = lightingConfig.envIntensity;
                        }
                        if (lightingConfig.toneMappingExposure !== undefined) {
                            this.renderer.toneMappingExposure = lightingConfig.toneMappingExposure;
                        }
                    },
                    undefined,
                    (error) => {
                        // Silent fail — standard lights already cover this
                    }
                );
            }

            // ============================================
            // SMART ROOM SIZING Implementation
            // ============================================
            createRoom(data) {
                const imageCount = data.imageCount;
                
                // SMART ROOM SIZING (NO EMPTY SPACES)
                const spacing = CONFIG.room.artworkSpacing;
                const minWallLength = CONFIG.room.minWallLength;
                
                // Calculate how many images per wall we need
                const imagesPerWall = Math.ceil(imageCount / 4);
                
                // Calculate minimum wall length to fit those images
                const calculatedWallLength = (imagesPerWall * spacing) + spacing;
                
                // Use the larger of calculated or minimum
                const wallLength = Math.max(minWallLength, calculatedWallLength);
                const wallHeight = CONFIG.room.wallHeight;
                
                console.log(`📐 Room sizing: ${imageCount} images → ${imagesPerWall} per wall → ${wallLength}m walls`);

                // FLOOR
                const floorMaterial = this.getFloorMaterial(data.floor_material);
                
                if (floorMaterial.map) {
                    const repeatX = (wallLength * 2) / 2; 
                    const repeatY = (wallLength * 2) / 2;
                    floorMaterial.map.repeat.set(repeatX, repeatY);
                    floorMaterial.map.needsUpdate = true;
                }

                const floor = new THREE.Mesh(
                    new THREE.PlaneGeometry(wallLength * 2, wallLength * 2),
                    floorMaterial
                );
                floor.rotation.x = -Math.PI / 2;
                floor.receiveShadow = !this.isLowEnd;
                this.scene.add(floor);

                // WALLS
                const wallMaterial = this.getWallMaterial(data.wall_texture);
                
                if (wallMaterial.map) {
                    const repeatX = wallLength / 2.5;
                    const repeatY = wallHeight / 2.5;
                    wallMaterial.map.repeat.set(repeatX, repeatY);
                    wallMaterial.map.needsUpdate = true;
                }

                const wallConfigs = [
                    { name: 'front', pos: [0, wallHeight/2, -wallLength/2], rot: [0, 0, 0] },
                    { name: 'back', pos: [0, wallHeight/2, wallLength/2], rot: [0, Math.PI, 0] },
                    { name: 'left', pos: [-wallLength/2, wallHeight/2, 0], rot: [0, Math.PI/2, 0] },
                    { name: 'right', pos: [wallLength/2, wallHeight/2, 0], rot: [0, -Math.PI/2, 0] }
                ];

                // ✨ PERF: Single shared geometry for all 4 walls (saves GPU memory + upload time)
                const sharedWallGeometry = new THREE.BoxGeometry(wallLength, wallHeight, CONFIG.room.wallDepth);

                wallConfigs.forEach(config => {
                    const wallMesh = new THREE.Mesh(
                        sharedWallGeometry,
                        wallMaterial
                    );
                    wallMesh.position.set(...config.pos);
                    wallMesh.rotation.set(...config.rot);
                    wallMesh.receiveShadow = !this.isLowEnd;
                    wallMesh.castShadow = !this.isLowEnd;
                    wallMesh.name = `wall_${config.name}`;
                    this.scene.add(wallMesh);
                });

                // CEILING — ⚡ FIX F: Lambert on low-end, shared plane geo
                const ceilingMaterial = this.isLowEnd
                    ? new THREE.MeshLambertMaterial({ color: this.lightingConfig.ceiling })
                    : new THREE.MeshStandardMaterial({
                        color: this.lightingConfig.ceiling,
                        roughness: 0.5,
                        metalness: 0.0,
                        emissive: this.lightingConfig.ceiling,
                        emissiveIntensity: 0.1
                    });

                const ceiling = new THREE.Mesh(
                    new THREE.PlaneGeometry(wallLength * 2, wallLength * 2),
                    ceilingMaterial
                );
                ceiling.rotation.x = Math.PI / 2;
                ceiling.position.y = wallHeight;
                ceiling.receiveShadow = !this.isLowEnd;
                ceiling.name = 'ceiling';
                this.scene.add(ceiling);

                // ⚡ FIX A: Radically fewer PointLights on low-end.
                // Each PointLight adds a full per-fragment lighting pass in WebGL.
                // Low-end: 0 ceiling PointLights (ambient + hemisphere carry the scene)
                // High-end: 2x2 = 4 ceiling lights (was 3x3 = 9)
                const roomLightingConfig = this.lightingConfig;
                if (!this.isLowEnd) {
                    const gridSize = 2;
                    const startX = -(wallLength / 2) + (wallLength / (gridSize + 1));
                    const startZ = -(wallLength / 2) + (wallLength / (gridSize + 1));
                    const stepX = wallLength / (gridSize + 1);
                    const stepZ = wallLength / (gridSize + 1);
                    for (let i = 0; i < gridSize; i++) {
                        for (let j = 0; j < gridSize; j++) {
                            const fillLight = new THREE.PointLight(
                                0xfff8e8,
                                roomLightingConfig.fillLight * 2.0,
                                wallLength * 1.2
                            );
                            fillLight.position.set(
                                startX + i * stepX,
                                CONFIG.room.wallHeight - 0.5,
                                startZ + j * stepZ
                            );
                            fillLight.castShadow = false;
                            this.scene.add(fillLight);
                        }
                    }
                }
                // Low-end: ambient (intensity boosted in setupLighting) + hemisphere provide flat fill

                console.log(`💡 Created optimized ceiling lights for ${wallLength}m room`);
                console.log(`📐 Room created: ${wallLength}m x ${wallLength}m x ${wallHeight}m`);

                // STORE ROOM BOUNDARIES FOR COLLISION
                this.roomBounds = {
                    minX: -wallLength / 2,
                    maxX: wallLength / 2,
                    minZ: -wallLength / 2,
                    maxZ: wallLength / 2
                };
                this._layoutMeta = { type: 'square' };
            }

            // ─────────────────────────────────────────────
            // LAYOUT: CORRIDOR  (wide rectangle, 3:1 ratio)
            // ─────────────────────────────────────────────
            createRoomCorridor(data) {
                const imageCount  = data.imageCount;
                const spacing     = CONFIG.room.artworkSpacing;
                const wallHeight  = CONFIG.room.wallHeight;
                const imagesPerLongWall = Math.ceil(imageCount / 2);
                const length = Math.max(16, (imagesPerLongWall * spacing) + spacing);
                const width  = 6;

                const wallMat  = this.getWallMaterial(data.wall_texture);
                const floorMat = this.getFloorMaterial(data.floor_material);
                if (wallMat.map)  { wallMat.map.repeat.set(length / 2.5, wallHeight / 2.5); wallMat.map.needsUpdate = true; }
                if (floorMat.map) { floorMat.map.repeat.set(length / 2, width / 2); floorMat.map.needsUpdate = true; }

                const sharedWallGeo = new THREE.BoxGeometry(1, wallHeight, CONFIG.room.wallDepth);

                const floor = new THREE.Mesh(new THREE.PlaneGeometry(length, width), floorMat);
                floor.rotation.x = -Math.PI / 2;
                floor.receiveShadow = !this.isLowEnd;
                this.scene.add(floor);

                const ceilMat = this.isLowEnd
                    ? new THREE.MeshLambertMaterial({ color: this.lightingConfig.ceiling })
                    : new THREE.MeshStandardMaterial({ color: this.lightingConfig.ceiling, roughness: 0.5, metalness: 0.0, emissive: this.lightingConfig.ceiling, emissiveIntensity: 0.1 });
                const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(length, width), ceilMat);
                ceiling.rotation.x = Math.PI / 2;
                ceiling.position.y = wallHeight;
                this.scene.add(ceiling);

                [
                    { pos: [0, wallHeight/2, -width/2],  ry: 0,            sx: length },
                    { pos: [0, wallHeight/2,  width/2],  ry: Math.PI,      sx: length },
                    { pos: [-length/2, wallHeight/2, 0], ry: Math.PI/2,    sx: width  },
                    { pos: [ length/2, wallHeight/2, 0], ry: -Math.PI/2,   sx: width  },
                ].forEach(cfg => {
                    const m = new THREE.Mesh(sharedWallGeo, wallMat);
                    m.scale.set(cfg.sx, 1, 1);
                    m.position.set(...cfg.pos);
                    m.rotation.y = cfg.ry;
                    m.receiveShadow = !this.isLowEnd;
                    m.castShadow    = !this.isLowEnd;
                    this.scene.add(m);
                });

                if (!this.isLowEnd) {
                    [-length / 4, length / 4].forEach(xp => {
                        const l = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 2.5, length * 0.7);
                        l.position.set(xp, wallHeight - 0.3, 0);
                        l.castShadow = false;
                        this.scene.add(l);
                    });
                }

                this.camera.position.set(-length / 2 + 1.5, CONFIG.camera.height, 0);
                this.roomBounds = { minX: -length/2+0.5, maxX: length/2-0.5, minZ: -width/2+0.5, maxZ: width/2-0.5 };
                this._layoutMeta = { type: 'corridor', length, width };
            }

            // ─────────────────────────────────────────────
            // LAYOUT: L-SHAPE
            //
            // Top-down (X/Z plane):
            //
            //  Z=-lenA/2 ┌──────┐
            //             │      │  Wing A (vertical)
            //             │      │  X: 0..wingW
            //  Z=jZ      ─┤      ├──────────────────┐
            //             │      │                   │  Wing B (horizontal)
            //  Z=lenA/2  └──────┴──────────────────┘  X: wingW..wingW+lenB
            //             X=0   X=wingW            X=wingW+lenB
            //
            // jZ = lenA/2 - wingW  (junction Z — where wings meet)
            // ─────────────────────────────────────────────
            createRoomLShape(data) {
                const imageCount = data.imageCount;
                const spacing    = CONFIG.room.artworkSpacing;
                const wallHeight = CONFIG.room.wallHeight;
                const wd         = CONFIG.room.wallDepth;
                const wingW      = 6;  // width of each wing (corridor width)

                // Split images 60/40 across the two wings
                const countA = Math.ceil(imageCount * 0.6);
                const countB = imageCount - countA;

                // Wing lengths — enough wall space for their share of images
                const lenA = Math.max(12, (Math.ceil(countA / 2) * spacing) + spacing);
                const lenB = Math.max(12, (Math.ceil(countB / 2) * spacing) + spacing);

                // Junction Z coordinate (bottom of Wing A = top of Wing B)
                const jZ = lenA / 2 - wingW;

                // Wing centres (for floor/ceiling panels and lights)
                const aCX = wingW / 2,          aCZ = 0;
                const bCX = wingW + lenB / 2,   bCZ = lenA / 2 - wingW / 2;

                const wallMat  = this.getWallMaterial(data.wall_texture);
                const floorMat = this.getFloorMaterial(data.floor_material);
                if (wallMat.map) {
                    wallMat.map.wrapS = wallMat.map.wrapT = THREE.RepeatWrapping;
                    wallMat.map.repeat.set(lenA / 2.5, wallHeight / 2.5);
                    wallMat.map.needsUpdate = true;
                }
                if (floorMat.map) {
                    floorMat.map.wrapS = floorMat.map.wrapT = THREE.RepeatWrapping;
                    floorMat.map.needsUpdate = true;
                }

                const ceilMatA = this.isLowEnd
                    ? new THREE.MeshLambertMaterial({ color: this.lightingConfig.ceiling })
                    : new THREE.MeshStandardMaterial({ color: this.lightingConfig.ceiling, roughness: 0.5, metalness: 0.0 });
                const ceilMatB = ceilMatA.clone ? ceilMatA.clone() : ceilMatA;

                // ── Floor + Ceiling panels ──────────────────────────────────
                const addPanel = (cx, cz, w, d, mat, isFloor) => {
                    if (floorMat.map && isFloor) {
                        floorMat.map.repeat.set(w / 2, d / 2);
                        floorMat.map.needsUpdate = true;
                    }
                    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, d), mat);
                    mesh.rotation.x = isFloor ? -Math.PI / 2 : Math.PI / 2;
                    mesh.position.set(cx, isFloor ? 0 : wallHeight, cz);
                    mesh.receiveShadow = !this.isLowEnd;
                    this.scene.add(mesh);
                };
                addPanel(aCX, aCZ,  wingW, lenA,  floorMat, true);   // Wing A floor
                addPanel(bCX, bCZ,  lenB,  wingW, floorMat, true);  // Wing B floor
                addPanel(aCX, aCZ,  wingW, lenA,  ceilMatA, false);  // Wing A ceiling (full length — covers corner)
                // FIX 3: Wing B ceiling starts at X=wingW+lenB/2 (its own rect only).
                // Wing A ceiling already covers the inner corner square, so no gap.
                addPanel(bCX, bCZ,  lenB,  wingW, ceilMatB, false); // Wing B ceiling

                // ── Walls ───────────────────────────────────────────────────
                // addWall(centreX, centreZ, rotationY, length)
                // All walls are BoxGeometry(length, wallHeight, wallDepth).
                const wallGeo = new THREE.BoxGeometry(1, wallHeight, wd);
                const addWall = (cx, cz, ry, len) => {
                    const m = new THREE.Mesh(wallGeo, wallMat);
                    m.scale.set(len, 1, 1);
                    m.position.set(cx, wallHeight / 2, cz);
                    m.rotation.y = ry;
                    m.receiveShadow = !this.isLowEnd;
                    m.castShadow    = !this.isLowEnd;
                    this.scene.add(m);
                };

                const H  = Math.PI / 2;
                const PI = Math.PI;

                // FIX 1: right wall of Wing A only runs from -lenA/2 to jZ (NOT to lenA/2).
                // The passage is at Z∈[jZ..lenA/2] — no wall there.
                const upperH = jZ - (-lenA / 2);           // = lenA/2 - wingW
                const upperMidZ = -lenA / 2 + upperH / 2; // midpoint of the upper segment

                // Wing A — left outer wall  (X=0, full length)
                addWall(0,              aCZ,          H,  lenA);
                // Wing A — top wall         (Z=-lenA/2, full width)
                addWall(aCX,           -lenA / 2,     0,  wingW);
                // Wing A — right wall (X=wingW, top to junction ONLY — passage below junction stays open)
                addWall(wingW,          upperMidZ,    H,  upperH);

                // Wing B — top wall  (Z=jZ, runs from junction rightward to end of Wing B)
                //   FIX 2 (partial): starts at X=wingW, length=lenB, so centre = wingW + lenB/2
                addWall(wingW + lenB / 2,  jZ,        0,  lenB);
                // Wing B — right end wall   (X=wingW+lenB)
                addWall(wingW + lenB,      bCZ,        H,  wingW);
                // FIX 2: bottom wall covers Wing B only (X: wingW → wingW+lenB).
                // Previously "(wingW+lenB)/2, length=wingW+lenB" covered X=0..wingW+lenB, sealing Wing A's base.
                addWall(wingW + lenB / 2,  lenA / 2,  PI, lenB);
                // Wing A — bottom wall (Z=lenA/2, only the Wing A width, closing that face)
                addWall(aCX,               lenA / 2,  PI, wingW);
                // NOTE: inner corner (X=wingW, Z∈[jZ..lenA/2]) is intentionally open — the L-shaped passage.

                // ── Lighting ────────────────────────────────────────────────
                if (!this.isLowEnd) {
                    const mkLight = (cx, cz) => {
                        const l = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 2.5, 14);
                        l.position.set(cx, wallHeight - 0.3, cz);
                        l.castShadow = false;
                        this.scene.add(l);
                    };
                    mkLight(aCX, -lenA / 4);   // upper half of Wing A
                    mkLight(aCX,  lenA / 4);   // lower half of Wing A
                    mkLight(bCX,  bCZ);        // Wing B
                }

                // ── Camera start ─────────────────────────────────────────────
                // Start near the top of Wing A, looking down
                this.camera.position.set(aCX, CONFIG.camera.height, -lenA / 2 + 1.5);

                // ── L-shaped collision ───────────────────────────────────────
                // Store both wing bounding boxes; collision checked with isInLShape()
                const margin = 0.5;
                this._lShapeBounds = {
                    // Wing A: full vertical strip.
                    // maxX extends PAST wingW into Wing B's space so the player
                    // never falls into the gap between the two bounding boxes.
                    a: { minX: 0 + margin, maxX: wingW + margin, minZ: -lenA/2 + margin, maxZ: lenA/2 - margin },
                    // Wing B: horizontal strip.
                    // minX overlaps into Wing A's right edge for the same reason.
                    // minZ = jZ (not jZ+margin) so the player can enter from Wing A
                    // the moment they reach the junction row.
                    b: { minX: wingW - margin, maxX: wingW + lenB - margin, minZ: jZ, maxZ: lenA/2 - margin }
                };
                // _enforceRoomBounds checks inA || inB. The overlapping bounds mean
                // the player is always valid in at least one box as they cross the junction.
                // Also keep a loose outer box for roomBounds (used by raycaster etc.)
                this.roomBounds = {
                    minX: 0, maxX: wingW + lenB,
                    minZ: -lenA / 2, maxZ: lenA / 2
                };

                this._layoutMeta = { type: 'l-shape', wingW, lenA, lenB, jZ, aCX, aCZ, bCX, bCZ, countA, countB };
            }

            // ─────────────────────────────────────────────
            // LAYOUT: ROTUNDA  (circular cylinder room)
            // ─────────────────────────────────────────────
            createRoomRotunda(data) {
                const imageCount = data.imageCount;
                const wallHeight = CONFIG.room.wallHeight;
                const spacing    = CONFIG.room.artworkSpacing;
                const circumference = imageCount * spacing;
                const radius = Math.max(7, circumference / (2 * Math.PI));

                const wallMat = this.getWallMaterial(data.wall_texture);
                if (wallMat.map) {
                    wallMat.map.wrapS = THREE.RepeatWrapping;
                    wallMat.map.repeat.set(Math.max(4, imageCount / 2), wallHeight / 2.5);
                    wallMat.map.needsUpdate = true;
                }
                wallMat.side = THREE.BackSide;

                const cylinderGeo = new THREE.CylinderGeometry(radius, radius, wallHeight, Math.max(32, imageCount * 2), 1, true);
                const cylinder = new THREE.Mesh(cylinderGeo, wallMat);
                cylinder.position.y = wallHeight / 2;
                this.scene.add(cylinder);

                const floorMat = this.getFloorMaterial(data.floor_material);
                if (floorMat.map) { floorMat.map.repeat.set(radius * 2 / 2, radius * 2 / 2); floorMat.map.needsUpdate = true; }
                const floor = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), floorMat);
                floor.rotation.x = -Math.PI / 2;
                floor.receiveShadow = !this.isLowEnd;
                this.scene.add(floor);

                const ceilMat = this.isLowEnd
                    ? new THREE.MeshLambertMaterial({ color: this.lightingConfig.ceiling, side: THREE.BackSide })
                    : new THREE.MeshStandardMaterial({ color: this.lightingConfig.ceiling, roughness: 0.5, metalness: 0.0, emissive: this.lightingConfig.ceiling, emissiveIntensity: 0.1, side: THREE.BackSide });
                const ceil = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), ceilMat);
                ceil.rotation.x = -Math.PI / 2;
                ceil.position.y = wallHeight;
                this.scene.add(ceil);

                if (!this.isLowEnd) {
                    const cl = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 3.0, radius * 2.5);
                    cl.position.set(0, wallHeight - 0.4, 0);
                    cl.castShadow = false;
                    this.scene.add(cl);
                }

                this._rotundaRadius = radius;
                this.roomBounds = { minX: -(radius - 1), maxX: radius - 1, minZ: -(radius - 1), maxZ: radius - 1 };
                this._layoutMeta = { type: 'rotunda', radius };
            }


            getWallMaterial(type) {
                const fallbackColors = {
                    white: 0xf5f5f5,
                    concrete: 0x8a8a8a,
                    brick: 0xa0826d,
                    wood: 0x8b6f47
                };

                // ⚡ FIX C: Lambert (diffuse-only) on low-end — ~4x cheaper than Standard (PBR)
                const MatClass = this.isLowEnd ? THREE.MeshLambertMaterial : THREE.MeshStandardMaterial;
                const stdProps = this.isLowEnd ? {} : { roughness: 0.8, metalness: 0.1 };

                if (!this.textures.wall) {
                    return new MatClass({
                        color: fallbackColors[type] || fallbackColors.white,
                        ...stdProps
                    });
                }

                const texture = this.textures.wall.clone();
                texture.needsUpdate = true;
                texture.wrapS = THREE.RepeatWrapping;
                texture.wrapT = THREE.RepeatWrapping;

                const properties = {
                    white:    { roughness: 0.8, metalness: 0.1 },
                    concrete: { roughness: 0.9, metalness: 0.0 },
                    brick:    { roughness: 0.95, metalness: 0.0 },
                    wood:     { roughness: 0.7, metalness: 0.1 }
                };
                const props = properties[type] || properties.white;

                return new MatClass({
                    map: texture,
                    ...(this.isLowEnd ? {} : props),
                    side: THREE.FrontSide
                });
            }
            // SECTION 4: Update Floor Materials
            getFloorMaterial(type) {
                const fallbackColors = {
                    wood: 0x5c4033,
                    marble: 0xe8e8e8,
                    concrete: 0x6b6b6b
                };

                // ⚡ FIX C (floor): Lambert on low-end
                if (this.isLowEnd) {
                    const mat = new THREE.MeshLambertMaterial({
                        color: fallbackColors[type] || fallbackColors.wood
                    });
                    if (this.textures.floor) {
                        const t = this.textures.floor.clone();
                        t.wrapS = t.wrapT = THREE.RepeatWrapping;
                        mat.map = t;
                        mat.color.set(0xffffff);
                        mat.needsUpdate = true;
                    }
                    return mat;
                }

                const lightingConfig = this.lightingConfig || CONFIG.lighting.bright;
                const envIntensity = lightingConfig.envIntensity || 1.0;

                const stdProps = {
                    wood:     { roughness: 0.7, metalness: 0.1, envMapIntensity: 0.6 * envIntensity },
                    marble:   { roughness: 0.3, metalness: 0.2, envMapIntensity: 1.2 * envIntensity },
                    concrete: { roughness: 0.9, metalness: 0.05, envMapIntensity: 0.3 * envIntensity }
                };
                const props = stdProps[type] || stdProps.wood;
                const mat = new THREE.MeshStandardMaterial({
                    color: fallbackColors[type] || fallbackColors.wood,
                    ...props
                });
                if (this.textures.floor) {
                    const t = this.textures.floor.clone();
                    t.wrapS = t.wrapT = THREE.RepeatWrapping;
                    mat.map = t;
                    mat.color.set(0xffffff);
                    mat.needsUpdate = true;
                }
                return mat;
            }

            setupLighting(preset) {
                this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                const config = this.lightingConfig;

                // ⚡ FIX B: On low-end boost ambient intensity to compensate for
                // removed ceiling PointLights. Ambient is free — no per-fragment cost.
                const ambientIntensity = this.isLowEnd ? config.ambient * 3.5 : config.ambient;
                const ambientLight = new THREE.AmbientLight(0xffffff, ambientIntensity);
                this.scene.add(ambientLight);

                // Hemisphere light gives cheap sky/ground gradient — keep on all tiers
                const hemisphereLight = new THREE.HemisphereLight(
                    0xffffff,
                    0x444444,
                    this.isLowEnd ? 0.8 : 0.3
                );
                this.scene.add(hemisphereLight);

                // Directional light — skip on low-end (another per-fragment cost)
                if (!this.isLowEnd) {
                    const dirLight = new THREE.DirectionalLight(0xffffff, 0.3);
                    dirLight.position.set(0, 10, 5);
                    dirLight.target.position.set(0, 0, 0);
                    dirLight.castShadow = false;
                    this.scene.add(dirLight);
                    this.scene.add(dirLight.target);
                }
            }

            // ─── shared artwork factory ───────────────────────────────────
            _makeArtworkGroup(img, data) {
                const maxWidth = 2.0, maxHeight = 2.5;
                let width, height;
                if (img.aspectRatio > 1) { width = maxWidth; height = width / img.aspectRatio; }
                else                     { height = maxHeight; width = height * img.aspectRatio; }

                const frame = this.createFrame(width, height, data.frame_style);
                if (!this._sharedPlaneGeo) this._sharedPlaneGeo = new THREE.PlaneGeometry(1, 1);

                let artworkMat;
                if (this.isLowEnd) {
                    artworkMat = new THREE.MeshLambertMaterial({ map: img.texture });
                } else {
                    artworkMat = new THREE.MeshStandardMaterial({
                        map: img.texture,
                        normalMap: this.textures.canvasNormal || null,
                        normalScale: new THREE.Vector2(0.35, 0.35),
                        roughness: 0.75, metalness: 0.0,
                    });
                    if (artworkMat.normalMap) artworkMat.normalMap.repeat.set(width * 2.5, height * 2.5);
                }

                const artwork = new THREE.Mesh(this._sharedPlaneGeo, artworkMat);
                artwork.scale.set(width * 0.95, height * 0.95, 1);
                artwork.position.z = 0.05;

                const group = new THREE.Group();
                group.add(frame);
                group.add(artwork);
                group.userData = { type: 'artwork', id: img.id, title: img.title, description: img.description };
                return { group, width, height };
            }

            _placeAndRegister(group, data) {
                this.scene.add(group);
                this.artworks.push(group);
                this.addArtworkLight(group, data.lighting_preset);
            }

            placeArtworks(data) {
                if (this.artworkImages.length === 0) return;
                const layout = (this._layoutMeta || {}).type || 'square';
                if      (layout === 'corridor') { this._placeArtworksCorridor(data); return; }
                else if (layout === 'l-shape')  { this._placeArtworksLShape(data);   return; }
                else if (layout === 'rotunda')  { this._placeArtworksRotunda(data);  return; }

                // ── SQUARE ────────────────────────────────────────────────────
                const imageCount = this.artworkImages.length;
                const spacing = CONFIG.room.artworkSpacing;
                const wallLength = Math.max(CONFIG.room.minWallLength,
                    (Math.ceil(imageCount / 4) * spacing) + spacing);
                const imagesPerWall = Math.ceil(imageCount / 4);
                const eyeLevel = CONFIG.camera.height;
                const walls = [
                    { start: [-wallLength/2+spacing, eyeLevel, -wallLength/2+0.2], dir:[1,0,0],  normal:[0,0,1]  },
                    { start: [ wallLength/2-spacing, eyeLevel,  wallLength/2-0.2], dir:[-1,0,0], normal:[0,0,-1] },
                    { start: [-wallLength/2+0.2,     eyeLevel,  wallLength/2-spacing], dir:[0,0,-1], normal:[1,0,0]  },
                    { start: [ wallLength/2-0.2,     eyeLevel, -wallLength/2+spacing], dir:[0,0,1],  normal:[-1,0,0] },
                ];
                let wi = 0, pos = 0;
                this.artworkImages.forEach(img => {
                    const wall = walls[wi];
                    const { group } = this._makeArtworkGroup(img, data);
                    const off = pos * spacing;
                    group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
                    group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
                    this._placeAndRegister(group, data);
                    pos++;
                    if (pos >= imagesPerWall) { pos = 0; wi = Math.min(wi+1, walls.length-1); }
                });
            }

            _placeArtworksCorridor(data) {
                const { length, width } = this._layoutMeta;
                const spacing = CONFIG.room.artworkSpacing;
                const eyeLevel = CONFIG.camera.height;
                const half = Math.ceil(this.artworkImages.length / 2);
                const longWalls = [
                    { start: [-length/2+spacing, eyeLevel, -width/2+0.2], dir:[1,0,0],  normal:[0,0,1]  },
                    { start: [ length/2-spacing, eyeLevel,  width/2-0.2], dir:[-1,0,0], normal:[0,0,-1] },
                ];
                let wi = 0, pos = 0;
                this.artworkImages.forEach(img => {
                    const wall = longWalls[wi];
                    const { group } = this._makeArtworkGroup(img, data);
                    const off = pos * spacing;
                    group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
                    group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
                    this._placeAndRegister(group, data);
                    pos++;
                    if (pos >= half) { pos = 0; wi = Math.min(wi+1, 1); }
                });
            }

            _placeArtworksLShape(data) {
                // Strategy: fill Wing A walls slot-by-slot alternating left/right.
                // Once a slot Z would land in the open passage (>= jZ), spill ALL
                // remaining artworks to Wing B. This guarantees zero dropped artworks
                // regardless of image count.
                const { wingW, lenA, lenB, jZ } = this._layoutMeta;
                const spacing  = CONFIG.room.artworkSpacing;
                const eyeLevel = CONFIG.camera.height;
                const all      = this.artworkImages;

                // Wing A: two walls (left X=0.2, right X=wingW-0.2), alternating
                // Both run top→bottom (Z increases). Stop before the open passage at jZ.
                const wA = [
                    { x: 0.2,       normal: [1,0,0]  },
                    { x: wingW-0.2, normal: [-1,0,0] },
                ];
                const zStart   = -lenA / 2 + spacing;
                const zLimit   = jZ - spacing / 2; // last safe Z before passage

                let spillFrom = all.length; // index where Wing B starts (default = all in A)
                let sideA = 0, rowA = 0;
                for (let i = 0; i < all.length; i++) {
                    const candidateZ = zStart + rowA * spacing;
                    if (candidateZ >= zLimit) {
                        spillFrom = i;
                        break;
                    }
                    const w = wA[sideA];
                    const { group } = this._makeArtworkGroup(all[i], data);
                    group.position.set(w.x, eyeLevel, candidateZ);
                    group.lookAt(w.x + w.normal[0], eyeLevel, candidateZ + w.normal[2]);
                    this._placeAndRegister(group, data);

                    sideA = 1 - sideA; // alternate left/right
                    if (sideA === 0) rowA++; // advance row after both sides done
                }

                // Wing B: all remaining artworks (spillover from Wing A + pre-allocated countB)
                const remainingImgs = all.slice(spillFrom);
                if (remainingImgs.length === 0) return;

                const wB = [
                    { z: jZ + 0.2,      normal: [0,0,1]  }, // top wall, faces +Z (inward)
                    { z: lenA/2 - 0.2,  normal: [0,0,-1] }, // bottom wall, faces -Z (inward)
                ];
                const xStart = wingW + spacing;
                let sideB = 0, rowB = 0;
                remainingImgs.forEach(img => {
                    const w = wB[sideB];
                    const candidateX = xStart + rowB * spacing;
                    const { group } = this._makeArtworkGroup(img, data);
                    group.position.set(candidateX, eyeLevel, w.z);
                    group.lookAt(candidateX + w.normal[0], eyeLevel, w.z + w.normal[2]);
                    this._placeAndRegister(group, data);

                    sideB = 1 - sideB;
                    if (sideB === 0) rowB++;
                });
            }

            _placeArtworksRotunda(data) {
                const radius   = this._rotundaRadius;
                const n        = this.artworkImages.length;
                const eyeLevel = CONFIG.camera.height;
                this.artworkImages.forEach((img, i) => {
                    const angle = (i / n) * Math.PI * 2;
                    const { group } = this._makeArtworkGroup(img, data);
                    group.position.set(Math.sin(angle)*(radius-0.3), eyeLevel, Math.cos(angle)*(radius-0.3));
                    group.lookAt(0, eyeLevel, 0);
                    this._placeAndRegister(group, data);
                });
            }

            // ⚡ PERF FIX 6: Shared frame geometries — one unit BoxGeometry per piece type,
            // scaled per-artwork via mesh.scale. Saves (artworkCount * 4) geometry uploads.
            _getFrameGeos() {
                if (!this._frameGeoH) {
                    // Unit geometries — we scale them per frame
                    this._frameGeoH = new THREE.BoxGeometry(1, 1, 1); // horizontal bar (top/bottom)
                    this._frameGeoV = new THREE.BoxGeometry(1, 1, 1); // vertical bar (left/right)
                }
                return { h: this._frameGeoH, v: this._frameGeoV };
            }

            createFrame(width, height, style) {
                const frameDepth = 0.08;
                const frameWidth = 0.1;
                
                const colors = {
                    modern: 0x2c2c2c,
                    classic: 0x8b7355,
                    minimal: 0xffffff
                };

                const lightingConfig = this.lightingConfig || CONFIG.lighting.bright;

                // ⚡ FIX E: Lambert on low-end — frames don't need PBR metalness shine
                const frameMat = this.isLowEnd
                    ? new THREE.MeshLambertMaterial({ color: colors[style] || colors.modern })
                    : new THREE.MeshStandardMaterial({
                        color: colors[style] || colors.modern,
                        roughness: 0.3,
                        metalness: 0.8,
                        envMapIntensity: 1.5 * (lightingConfig.envIntensity || 1.0)
                    });

                const frame = new THREE.Group();
                const { h: hGeo, v: vGeo } = this._getFrameGeos();
                
                // ⚡ Use shared geometries, drive size via mesh.scale
                const pieceConfigs = [
                    { geo: hGeo, sx: width + frameWidth * 2, sy: frameWidth, sz: frameDepth, px: 0, py:  height/2 + frameWidth/2, pz: 0 },
                    { geo: hGeo, sx: width + frameWidth * 2, sy: frameWidth, sz: frameDepth, px: 0, py: -height/2 - frameWidth/2, pz: 0 },
                    { geo: vGeo, sx: frameWidth, sy: height, sz: frameDepth, px: -width/2 - frameWidth/2, py: 0, pz: 0 },
                    { geo: vGeo, sx: frameWidth, sy: height, sz: frameDepth, px:  width/2 + frameWidth/2, py: 0, pz: 0 },
                ];

                pieceConfigs.forEach(({ geo, sx, sy, sz, px, py, pz }) => {
                    const mesh = new THREE.Mesh(geo, frameMat);
                    mesh.scale.set(sx, sy, sz);
                    mesh.position.set(px, py, pz);
                    mesh.castShadow = !this.isLowEnd;
                    frame.add(mesh);
                });

                return frame;
            }

            addArtworkLight(artworkGroup, preset) {
                // ⚡ FIX H: On low-end, NO per-artwork PointLights.
                // Boosted ambient + hemisphere already illuminate artworks adequately.
                // Even one hidden PointLight costs GPU state — skip creating them entirely.
                if (this.isLowEnd) return;

                const config = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                const artworkLight = new THREE.PointLight(
                    0xfff5e6,
                    config.spot * 3.5,
                    10
                );

                const normal = new THREE.Vector3(0, 0, 1);
                normal.applyQuaternion(artworkGroup.quaternion);

                artworkLight.position.copy(artworkGroup.position);
                artworkLight.position.y += 0.3;
                artworkLight.position.add(normal.multiplyScalar(0.8));

                artworkLight.castShadow = false;
                artworkLight.visible = false;

                this.scene.add(artworkLight);
                artworkGroup.userData.light = artworkLight;
            }

            updateProximityLighting() {
                if (!this.artworks || this.artworks.length === 0) return;

                // ⚡ FIX G: On low-end there are NO per-artwork PointLights (addArtworkLight
                // is skipped below), so there is nothing to update here.
                if (this.isLowEnd) return;

                const playerPos = this.camera.position;
                const lightingConfig = this.lightingConfig || CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright;
                const proximityDist = lightingConfig.proximityDistance || 5;
                const sqrProximityDist = proximityDist * proximityDist;

                let closestArtwork = null;
                let closestDistSqr = Infinity;

                for (const artwork of this.artworks) {
                    const artPos = artwork.position;
                    const dx = playerPos.x - artPos.x;
                    const dz = playerPos.z - artPos.z;
                    const distSqr = dx * dx + dz * dz;
                    if (distSqr < closestDistSqr && distSqr < sqrProximityDist) {
                        closestDistSqr = distSqr;
                        closestArtwork = artwork;
                    }
                }

                const targetIntensity = (CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright).spot * 3.5;

                for (const artwork of this.artworks) {
                    const light = artwork.userData.light;
                    if (!light) continue;

                    if (artwork === closestArtwork) {
                        if (!light.visible) { light.visible = true; light.intensity = 0; }
                        light.intensity = Math.min(light.intensity + 0.2, targetIntensity);
                    } else {
                        // ⚡ Early-exit: skip artworks already fully dark (saves 20+ iterations)
                        if (light.intensity <= 0) continue;
                        light.intensity = Math.max(0, light.intensity - 0.1);
                        if (light.intensity === 0) light.visible = false;
                    }
                }
            }

            // ─── Unified collision enforcement for all room shapes ────────────
            _enforceRoomBounds() {
                const pos = this.camera.position;
                const prevX = pos.x, prevZ = pos.z;

                if (this._lShapeBounds) {
                    // L-shape: player must be inside Wing A OR Wing B
                    const { a, b } = this._lShapeBounds;
                    const inA = pos.x >= a.minX && pos.x <= a.maxX && pos.z >= a.minZ && pos.z <= a.maxZ;
                    const inB = pos.x >= b.minX && pos.x <= b.maxX && pos.z >= b.minZ && pos.z <= b.maxZ;

                    if (!inA && !inB) {
                        // Outside both wings — push back to the nearest valid point
                        // Find closest point in A
                        const cAx = Math.max(a.minX, Math.min(a.maxX, pos.x));
                        const cAz = Math.max(a.minZ, Math.min(a.maxZ, pos.z));
                        const dA  = (pos.x - cAx) ** 2 + (pos.z - cAz) ** 2;
                        // Find closest point in B
                        const cBx = Math.max(b.minX, Math.min(b.maxX, pos.x));
                        const cBz = Math.max(b.minZ, Math.min(b.maxZ, pos.z));
                        const dB  = (pos.x - cBx) ** 2 + (pos.z - cBz) ** 2;
                        // Push to whichever wing is closer
                        if (dA <= dB) { pos.x = cAx; pos.z = cAz; }
                        else          { pos.x = cBx; pos.z = cBz; }
                    }
                } else if (this._rotundaRadius) {
                    // Rotunda: keep inside the circle
                    const r = this._rotundaRadius - 0.5;
                    const d = Math.sqrt(pos.x * pos.x + pos.z * pos.z);
                    if (d > r) { pos.x = (pos.x / d) * r; pos.z = (pos.z / d) * r; }
                } else if (this.roomBounds) {
                    // Rectangle (square, corridor): axis-aligned clamp
                    const b = this.roomBounds;
                    pos.x = Math.max(b.minX, Math.min(b.maxX, pos.x));
                    pos.z = Math.max(b.minZ, Math.min(b.maxZ, pos.z));
                }

                // Zero velocity on axes where we were pushed back
                if (pos.x !== prevX) this.velocity.x = 0;
                if (pos.z !== prevZ) this.velocity.z = 0;
            }

            // SECTION 6: Replace updateMovement() method
            updateMovement() {
                if (!this.controls.isLocked || this.isInspecting) return;
                
                // ✨ Get delta time for frame-independent movement
                const delta = Math.min(this.clock.getDelta(), 0.1); // Cap at 100ms to prevent huge jumps
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 1: Apply Damping (Friction) - The "Heavy Stop" Effect
                // ═══════════════════════════════════════════════════════════════
                const dampingFactor = Math.pow(1 / CONFIG.camera.damping, delta);
                this.velocity.multiplyScalar(dampingFactor);
                
                // Stop completely when velocity is very small (prevents eternal drifting)
                if (this.velocity.length() < 0.001) {
                    this.velocity.set(0, 0, 0);
                }
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 2: Process Input & Apply Acceleration
                // ═══════════════════════════════════════════════════════════════
                this.direction.set(0, 0, 0);
                let isMoving = false;
                
                // Gather input direction
                if (this.moveState.forward) {
                    this.direction.z -= 1;
                    isMoving = true;
                }
                if (this.moveState.backward) {
                    this.direction.z += 1;
                    isMoving = true;
                }
                if (this.moveState.left) {
                    this.direction.x -= 1;
                    isMoving = true;
                }
                if (this.moveState.right) {
                    this.direction.x += 1;
                    isMoving = true;
                }
                
                // Apply acceleration in the input direction
                if (this.direction.length() > 0) {
                    this.direction.normalize();
                    
                    // Consider speed multiplier and sprint
                    const speedMultiplier = this.currentSpeedMultiplier || 1;
                    const sprintMultiplier = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                    const totalMultiplier = speedMultiplier * sprintMultiplier;
                    
                    // Add acceleration to velocity
                    this.velocity.x += this.direction.x * CONFIG.camera.acceleration * delta * totalMultiplier;
                    this.velocity.z += this.direction.z * CONFIG.camera.acceleration * delta * totalMultiplier;
                }
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 3: Clamp to Maximum Speed
                // ═══════════════════════════════════════════════════════════════
                const speedMultiplier = this.currentSpeedMultiplier || 1;
                const sprintMultiplier = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                const maxSpeed = CONFIG.camera.maxSpeed * speedMultiplier * sprintMultiplier;
                
                const currentSpeed = Math.sqrt(this.velocity.x * this.velocity.x + this.velocity.z * this.velocity.z);
                if (currentSpeed > maxSpeed) {
                    const scale = maxSpeed / currentSpeed;
                    this.velocity.x *= scale;
                    this.velocity.z *= scale;
                }
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 4: Apply Velocity to Camera Position
                // ═══════════════════════════════════════════════════════════════
                this.controls.moveRight(this.velocity.x * delta);
                this.controls.moveForward(-this.velocity.z * delta);
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 5: Room Boundaries (Collision)
                // ═══════════════════════════════════════════════════════════════
                this._enforceRoomBounds();

                this.camera.position.y = CONFIG.camera.height;
                
                // ═══════════════════════════════════════════════════════════════
                // STEP 6: Cinematic Lean (Banking) Effect
                // ═══════════════════════════════════════════════════════════════
                // Calculate target lean based on sideways velocity
                const targetLean = -this.velocity.x * CONFIG.camera.maxLean;
                
                // Smoothly interpolate (lerp) current lean toward target
                this.currentLean += (targetLean - this.currentLean) * CONFIG.camera.leanSpeed;
                
                // NOTE: Lean is applied in animate() after camera rotation is clamped
                
                // ═══════════════════════════════════════════════════════════════
                // ✨ NEW: STEP 7: Dynamic Footstep System
                // ═══════════════════════════════════════════════════════════════
                if (!this.isInspecting && this.sfxEnabled && this.sfx.footstep) {
                    // Check if user is actively pressing movement keys (not just coasting from velocity)
                    const hasMovementInput = this.moveState.forward || this.moveState.backward || 
                                           this.moveState.left || this.moveState.right;
                    
                    const currentSpeed = this.velocity.length();
                    const isMovingNow = hasMovementInput && currentSpeed > 0.05; // Higher threshold + require input
                    
                    // Store sprint state for reference
                    this.isSprinting = this.moveState.sprint || false;
                    this.speedMultiplier = this.currentSpeedMultiplier || 1.0;
                    
                    if (isMovingNow) {
                        // Calculate step interval based on speed
                        // Base interval: 0.5s walking, 0.3s sprinting
                        const baseInterval = 0.5;
                        const sprintMultiplier = this.isSprinting ? 0.6 : 1.0; // Faster steps when sprinting
                        const speedMult = this.speedMultiplier || 1.0; // Account for 1x-8x speed
                        
                        // Faster movement = shorter interval between steps
                        let stepInterval = (baseInterval * sprintMultiplier) / Math.sqrt(speedMult);
                        stepInterval = Math.max(0.2, Math.min(0.6, stepInterval)); // Clamp between 0.2s - 0.6s
                        
                        // Increment footstep timer
                        this.footstepTimer += delta;
                        
                        // Play footstep when timer exceeds interval
                        if (this.footstepTimer >= stepInterval) {
                            // Stop any currently playing footstep to allow rapid steps
                            if (this.sfx.footstep.isPlaying) {
                                this.sfx.footstep.stop();
                            }
                            
                            // Vary footstep pitch slightly for realism (0.95 - 1.05)
                            const pitchVariation = 0.95 + Math.random() * 0.1;
                            this.sfx.footstep.setPlaybackRate(pitchVariation);
                            
                            // Play the footstep
                            this.sfx.footstep.play();
                            
                            // Reset timer
                            this.footstepTimer = 0;
                            this.lastStepTime = Date.now();
                        }
                    } else {
                        // ✨ CRITICAL FIX: Immediately stop footstep when movement stops
                        this.footstepTimer = 0;
                        if (this.sfx.footstep.isPlaying) {
                            this.sfx.footstep.stop();
                        }
                    }
                }
            }

            checkArtworkFocus() {
                if (!this.controls.isLocked) return;

                this._reusableVector.set(0, 0);
                this.raycaster.setFromCamera(this._reusableVector, this.camera);
                const intersects = this.raycaster.intersectObjects(this.artworks, true);

                const crosshair = document.getElementById('crosshair');
                
                if (intersects.length > 0) {
                    const artwork = intersects[0].object.parent;
                    if (artwork.userData.type === 'artwork' && artwork !== this.focusedArtwork) {
                        this.focusedArtwork = artwork;
                        if (crosshair && !this.isInspecting) {
                            crosshair.classList.add('focused');
                        }
                    }
                } else {
                    this.focusedArtwork = null;
                    if (crosshair && !this.isInspecting) {
                        crosshair.classList.remove('focused');
                    }
                }
            }

            toggleArtworkInfo() {
                const panel = document.getElementById('info-panel');
                const crosshair = document.getElementById('crosshair');
                const focusIndicator = document.getElementById('focus-indicator');
                
                // ═══════════════════════════════════════════════
                // CASE 1: EXIT FOCUS MODE
                // ═══════════════════════════════════════════════
                if (this.isInspecting) {
                    console.log('🎬 Exiting Focus Mode');
                    
                    if (this.focusTween) {
                        this.focusTween.kill();
                        this.focusTween = null;
                    }
                    
                    panel.classList.remove('show');
                    
                    // ✨ NEW: Play exit click sound
                    if (this.sfxEnabled && this.sfx.click && !this.sfx.click.isPlaying) {
                        this.sfx.click.play();
                    }
                    
                    if (focusIndicator) focusIndicator.style.opacity = '0';
                    if (crosshair) crosshair.classList.remove('focused');
                    
                    gsap.to(this.camera.position, {
                        x: this.originalCameraPos.x,
                        y: this.originalCameraPos.y,
                        z: this.originalCameraPos.z,
                        duration: 1.2,
                        ease: "power2.inOut",
                        onUpdate: () => {
                            this.camera.quaternion.slerp(this.originalCameraQuat, 0.1);
                        },
                        onComplete: () => {
                            console.log('✅ Returned to original position');
                            this.isInspecting = false;
                            
                            // ✨ NEW: Reset velocity and lean
                            this.velocity.set(0, 0, 0);
                            this.currentLean = 0;
                            this.camera.rotation.z = 0;
                            
                            // Only re-lock on desktop (mobile doesn't use PointerLock)
                            if (!this.isMobile && !this.controls.isLocked) {
                                this.controls.lock();
                            }
                        }
                    });
                    
                    return;
                }
                
                // ═══════════════════════════════════════════════
                // CASE 2: ENTER FOCUS MODE
                // ═══════════════════════════════════════════════
                if (this.focusedArtwork) {
                    console.log('🎬 Entering Focus Mode for:', this.focusedArtwork.userData.title);
                    
                    this.originalCameraPos.copy(this.camera.position);
                    this.originalCameraQuat.copy(this.camera.quaternion);
                    this.isInspecting = true;
                    
                    // ✨ NEW: Play focus click sound
                    if (this.sfxEnabled && this.sfx.click && !this.sfx.click.isPlaying) {
                        this.sfx.click.play();
                    }
                    
                    // ✨ NEW: Zero out velocity to prevent sliding during focus mode
                    this.velocity.set(0, 0, 0);
                    this.currentLean = 0;
                    this.camera.rotation.z = 0; // Reset camera roll
                    
                    if (this.controls.isLocked) {
                        this.controls.unlock();
                    }
                    
                    if (focusIndicator) {
                        focusIndicator.style.opacity = '1';
                    }
                    
                    if (crosshair) {
                        crosshair.classList.add('focused');
                    }
                    
                    const artwork = this.focusedArtwork;
                    const artworkWorldPos = new THREE.Vector3();
                    artwork.getWorldPosition(artworkWorldPos);
                    
                    const artworkDirection = new THREE.Vector3(0, 0, 1);
                    artwork.getWorldDirection(artworkDirection);
                    
                    const focusDistance = 1.8;
                    const targetPos = artworkWorldPos.clone().add(
                        artworkDirection.multiplyScalar(focusDistance)
                    );
                    targetPos.y = CONFIG.camera.height;
                    
                    console.log('📍 Target Position:', targetPos);
                    console.log('🎯 Artwork Position:', artworkWorldPos);
                    
                    this.focusTween = gsap.to(this.camera.position, {
                        x: targetPos.x,
                        y: targetPos.y,
                        z: targetPos.z,
                        duration: 1.5,
                        ease: "power2.inOut",
                        
                        onUpdate: () => {
                            this.camera.lookAt(artworkWorldPos);
                        },
                        
                        onComplete: () => {
                            console.log('✅ Focus animation complete');
                            this.camera.lookAt(artworkWorldPos);
                            
                            setTimeout(() => {
                                const data = artwork.userData;
                                let displayTitle = data.title || 'Untitled';
                                if (displayTitle.includes('.')) {
                                    displayTitle = displayTitle.split('.').slice(0, -1).join('.');
                                    displayTitle = displayTitle.replace(/[_-]/g, ' ');
                                }
                                
                                document.getElementById('artwork-title').textContent = displayTitle;
                                document.getElementById('artwork-description').textContent = 
                                    data.description || 'No description available.';
                                panel.classList.add('show');
                                
                                console.log('📋 Info panel displayed');
                            }, 400);
                        }
                    });
                }
            }

            animate() {
                requestAnimationFrame(() => this.animate());
                
                // Skip rendering entirely when tab is not visible
                if (!this._isVisible) return;

                // ⚡ PERF FIX 8: On low-end, cap render to ~30fps to halve GPU load.
                // rAF fires at display refresh (60/120Hz) — we simply skip odd frames.
                if (this.isLowEnd) {
                    this._lowEndFrameSkip = !this._lowEndFrameSkip;
                    if (this._lowEndFrameSkip) return;
                }
                
                // Increment frame counter for throttling
                this._lightingFrameCount++;
                
                // Reuse pre-allocated Euler (no GC pressure)
                const euler = this._reusableEuler;
                euler.setFromQuaternion(this.camera.quaternion);
                
                // CRITICAL: Clamp pitch to prevent gimbal lock
                const maxPitch = 1.4; // ~80 degrees
                euler.x = Math.max(-maxPitch, Math.min(maxPitch, euler.x));
                
                // Force roll to ONLY our cinematic lean (remove any drift)
                euler.z = this.currentLean || 0;
                
                // Apply the corrected rotation back to camera
                this.camera.quaternion.setFromEuler(euler);
                
                // Mobile uses modified movement system
                if (this.isMobile) {
                    this.updateMovementMobile();
                } else {
                    this.updateMovement();
                }

                // ⚡ PERF FIX 9: Throttle intervals are doubled on low-end
                // (already running at ~30fps, so every 4th/6th frame = ~7.5fps for these tasks — plenty)
                const lightThrottle  = this.isLowEnd ? 4 : 2;
                const focusThrottle  = this.isLowEnd ? 6 : 3;

                if (this._lightingFrameCount % lightThrottle === 0) {
                    this.updateProximityLighting();
                }
                
                if (this._lightingFrameCount % focusThrottle === 0) {
                    this.checkArtworkFocus();
                }
                
                this.renderer.render(this.scene, this.camera);
            }

            // ==========================================
            // MOBILE IMMERSION SPRINT - TOUCH CONTROLS
            // ==========================================
            
            setupMobileControls() {
                // Detection: Check for touch device
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) 
                    || (navigator.maxTouchPoints > 0 && 'ontouchstart' in window);
                
                if (!isMobile) {
                    console.log('📱 Mobile controls skipped: Desktop detected');
                    return;
                }
                
                console.log('📱 Mobile device detected - Initializing touch controls');
                
                this.isMobile = true;
                this.mobileState = {
                    joystick: { active: false, touchId: null, originX: 0, originY: 0, deltaX: 0, deltaY: 0 },
                    look: { active: false, touchId: null, lastX: 0, lastY: 0, deltaX: 0, deltaY: 0 },
                    sprint: false,
                    lastTap: 0,
                    tapTimeout: null
                };
                
                // Show mobile overlay
                const overlay = document.getElementById('mobile-overlay');
                if (overlay) overlay.classList.add('active');
                
                // Disable PointerLockControls for mobile (we use direct Euler control)
                this.controls.enabled = false;
                
                // Hide crosshair on mobile (not needed)
                const crosshair = document.getElementById('crosshair');
                if (crosshair) crosshair.style.display = 'none';
                
                // Initialize touch handlers
                this.initJoystick();
                this.initLookPad();
                
                // ISSUE 3: Replaced initSprintButton with toggle logic
                this.initSprintButton();
                
                // ISSUE 1C: Replaced initSpeedDial with cycling single button logic
                this.initSpeedDial();
                
                // ISSUE 2F: Replaced initDoubleTap with proximity hint system
                this.initDoubleTap();
                
                // Show hint briefly
                setTimeout(() => {
                    const hint = document.getElementById('mobile-hint');
                    if (hint) {
                        hint.classList.add('show');
                        setTimeout(() => hint.classList.remove('show'), 4000);
                    }
                }, 2000);
                
                // Prevent default touch behaviors (scrolling, zooming)
                document.addEventListener('touchmove', (e) => {
                    if (e.target.closest('#mobile-overlay')) {
                        e.preventDefault();
                    }
                }, { passive: false });
                
                // Prevent zoom on double tap
                let lastTouchEnd = 0;
                document.addEventListener('touchend', (e) => {
                    const now = Date.now();
                    if (now - lastTouchEnd <= 300) {
                        e.preventDefault();
                    }
                    lastTouchEnd = now;
                }, { passive: false });
            }
            
            initJoystick() {
                const zone = document.getElementById('joystick-zone');
                const base = document.getElementById('joystick-base');
                const thumb = document.getElementById('joystick-thumb');
                
                if (!zone || !base || !thumb) return;
                
                const maxDistance = 35; // Max thumb movement in pixels
                
                zone.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    
                    // Only accept first touch in left zone
                    if (this.mobileState.joystick.active) return;
                    
                    const touch = e.changedTouches[0];
                    this.mobileState.joystick.active = true;
                    this.mobileState.joystick.touchId = touch.identifier;
                    
                    // Position joystick base at touch point
                    const rect = zone.getBoundingClientRect();
                    this.mobileState.joystick.originX = touch.clientX;
                    this.mobileState.joystick.originY = touch.clientY;
                    
                    // Visual feedback
                    base.style.left = `${touch.clientX - 60}px`;
                    base.style.bottom = `${window.innerHeight - touch.clientY - 60}px`;
                    base.classList.remove('inactive');
                    base.classList.add('active');
                    
                }, { passive: false });
                
                zone.addEventListener('touchmove', (e) => {
                    e.preventDefault();
                    
                    if (!this.mobileState.joystick.active) return;
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.joystick.touchId);
                    if (!touch) return;
                    
                    // Calculate delta from origin
                    let deltaX = touch.clientX - this.mobileState.joystick.originX;
                    let deltaY = touch.clientY - this.mobileState.joystick.originY;
                    
                    // Clamp to max distance
                    const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
                    if (distance > maxDistance) {
                        const ratio = maxDistance / distance;
                        deltaX *= ratio;
                        deltaY *= ratio;
                    }
                    
                    this.mobileState.joystick.deltaX = deltaX;
                    this.mobileState.joystick.deltaY = deltaY;
                    
                    // Move thumb visually
                    thumb.style.transform = `translate(calc(-50% + ${deltaX}px), calc(-50% + ${deltaY}px))`;
                    
                    // Map to movement state (normalized -1 to 1)
                    const normalize = (val) => Math.max(-1, Math.min(1, val / maxDistance));
                    this.moveState.forward = deltaY < -5;
                    this.moveState.backward = deltaY > 5;
                    this.moveState.left = deltaX < -5;
                    this.moveState.right = deltaX > 5;
                    
                    // Store intensity for analog movement feel
                    this.mobileState.joystick.intensity = {
                        x: normalize(deltaX),
                        y: normalize(-deltaY) // Invert Y (up is forward)
                    };
                    
                }, { passive: false });
                
                const endJoystick = (e) => {
                    if (!this.mobileState.joystick.active) return;
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.joystick.touchId);
                    if (!touch) return;
                    
                    // Reset state
                    this.mobileState.joystick.active = false;
                    this.mobileState.joystick.touchId = null;
                    this.mobileState.joystick.deltaX = 0;
                    this.mobileState.joystick.deltaY = 0;
                    this.mobileState.joystick.intensity = { x: 0, y: 0 };
                    
                    // Reset movement
                    this.moveState.forward = false;
                    this.moveState.backward = false;
                    this.moveState.left = false;
                    this.moveState.right = false;
                    
                    // Visual reset
                    thumb.style.transform = 'translate(-50%, -50%)';
                    base.classList.remove('active');
                    base.classList.add('inactive');
                };
                
                zone.addEventListener('touchend', endJoystick, { passive: false });
                zone.addEventListener('touchcancel', endJoystick, { passive: false });
            }
            
            initLookPad() {
                const zone = document.getElementById('look-zone');
                if (!zone) return;
                
                // Sensitivity settings (tune these based on testing)
                const sensitivity = {
                    yaw: 0.003,   // Horizontal look
                    pitch: 0.003  // Vertical look
                };
                
                zone.addEventListener('touchstart', (e) => {
                    // Only accept if not already looking and not in joystick zone
                    if (this.mobileState.look.active) return;
                    
                    // Don't steal joystick touches
                    const touch = e.changedTouches[0];
                    if (touch.clientX < window.innerWidth * 0.45) return;
                    
                    e.preventDefault();
                    
                    this.mobileState.look.active = true;
                    this.mobileState.look.touchId = touch.identifier;
                    this.mobileState.look.lastX = touch.clientX;
                    this.mobileState.look.lastY = touch.clientY;
                    
                }, { passive: false });
                
                zone.addEventListener('touchmove', (e) => {
                    if (!this.mobileState.look.active) return;
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.joystick.touchId);
                    if (!touch) return;
                    
                    e.preventDefault();
                    
                    // Calculate delta
                    const deltaX = touch.clientX - this.mobileState.look.lastX;
                    const deltaY = touch.clientY - this.mobileState.look.lastY;
                    
                    this.mobileState.look.lastX = touch.clientX;
                    this.mobileState.look.lastY = touch.clientY;
                    
                    // Apply rotation directly to camera Euler angles
                    // ⚡ PERF FIX 5: Reuse pre-allocated Euler — avoids GC on every touch event
                    const euler = this._reusableEuler;
                    euler.set(0, 0, 0, 'YXZ');
                    euler.setFromQuaternion(this.camera.quaternion);
                    
                    // Yaw (Y-axis) - unlimited rotation
                    euler.y -= deltaX * sensitivity.yaw;
                    
                    // Pitch (X-axis) - clamped to prevent flip
                    euler.x -= deltaY * sensitivity.pitch;
                    euler.x = Math.max(-1.4, Math.min(1.4, euler.x));
                    
                    // Apply back to camera
                    this.camera.quaternion.setFromEuler(euler);
                    
                }, { passive: false });
                
                const endLook = (e) => {
                    if (!this.mobileState.look.active) return;
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.joystick.touchId);
                    if (!touch) return;
                    
                    this.mobileState.look.active = false;
                    this.mobileState.look.touchId = null;
                };
                
                zone.addEventListener('touchend', endLook, { passive: false });
                zone.addEventListener('touchcancel', endLook, { passive: false });
            }
            
            // ISSUE 3: Modified initSprintButton - Toggle on click
            initSprintButton() {
                const btn = document.getElementById('sprint-btn');
                if (!btn) return;
                
                // Toggle sprint mode on touch
                btn.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    
                    // Toggle state
                    this.mobileState.sprint = !this.mobileState.sprint;
                    this.moveState.sprint = this.mobileState.sprint;
                    
                    // Update visual state
                    if (this.mobileState.sprint) {
                        btn.classList.add('active');
                        console.log('🏃 Sprint ON');
                    } else {
                        btn.classList.remove('active');
                        console.log('🚶 Sprint OFF');
                    }
                    
                    // Visual feedback
                    btn.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        btn.style.transform = this.mobileState.sprint ? 'scale(1.1)' : 'scale(1)';
                    }, 100);
                    
                }, { passive: false });
                
                // Prevent default touch behaviors
                btn.addEventListener('touchend', (e) => {
                    e.preventDefault();
                }, { passive: false });
            }
            
            // ISSUE 1C: Modified initSpeedDial - Cycle through speeds with both touch and click
            initSpeedDial() {
                const btn = document.getElementById('speed-toggle-btn');
                if (!btn) return;
                
                // Speed labels cycle: 1x -> 2x -> 4x -> 8x -> 1x
                const speedLabels = ['1x', '2x', '4x', '8x'];
                let currentIndex = 0;
                
                // Handle speed change logic
                const handleSpeedChange = (e) => {
                    if (e) e.preventDefault();
                    
                    // Cycle to next speed
                    currentIndex = (currentIndex + 1) % speedLabels.length;
                    
                    // Update button text and data attribute
                    btn.textContent = speedLabels[currentIndex];
                    btn.dataset.speed = currentIndex;
                    
                    // Keep active styling
                    btn.classList.add('active');
                    
                    // Apply speed multiplier
                    this.setSpeedMultiplier(currentIndex);
                    
                    // Visual feedback - brief scale animation
                    btn.style.transform = 'scale(0.85)';
                    setTimeout(() => {
                        btn.style.transform = 'scale(1.05)';
                    }, 100);
                    
                    console.log(`🚀 Speed changed to ${speedLabels[currentIndex]}`);
                };
                
                // Bind both touch and click events for maximum compatibility
                btn.addEventListener('touchstart', handleSpeedChange, { passive: false });
                btn.addEventListener('click', handleSpeedChange);
                
                // Prevent double-firing on some devices
                btn.addEventListener('touchend', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }, { passive: false });
            }
            
            // ISSUE 2F: Modified initDoubleTap - Distance check and proximity hint
            initDoubleTap() {
                const zone = document.getElementById('look-zone');
                const hint = document.getElementById('mobile-hint');
                if (!zone) return;
                
                // ✅ PROXIMITY HINT SYSTEM: Show hint when near artwork
                this.proximityHintInterval = setInterval(() => {
                    if (!this.isMobile || this.isInspecting) {
                        if (hint) hint.classList.remove('show');
                        return;
                    }
                    
                    const playerPos = this.camera.position;
                    let nearestArtwork = null;
                    let nearestDist = Infinity;
                    
                    // Find nearest artwork
                    for (const artwork of this.artworks) {
                        const dist = playerPos.distanceTo(artwork.position);
                        if (dist < nearestDist) {
                            nearestDist = dist;
                            nearestArtwork = artwork;
                        }
                    }
                    
                    // Show hint if within optimal viewing distance (3-6 meters)
                    const optimalMin = 2.0;
                    const optimalMax = 6.0;
                    
                    if (nearestArtwork && nearestDist >= optimalMin && nearestDist <= optimalMax) {
                        if (hint && !hint.classList.contains('show')) {
                            hint.classList.add('show');
                        }
                    } else {
                        if (hint && hint.classList.contains('show')) {
                            hint.classList.remove('show');
                        }
                    }
                }, 500); // Check every 500ms
                
                zone.addEventListener('touchstart', (e) => {
                    // Only track single touches in look zone
                    if (e.touches.length !== 1) return;
                    const touch = e.changedTouches[0];
                    if (touch.clientX < window.innerWidth * 0.45) return;
                    
                    const now = Date.now();
                    const timeSinceLastTap = now - this.mobileState.lastTap;
                    
                    if (timeSinceLastTap < 300 && timeSinceLastTap > 0) {
                        // Double tap detected!
                        this.handleDoubleTap(touch.clientX, touch.clientY);
                    }
                    
                    this.mobileState.lastTap = now;
                    
                }, { passive: false });
            }
            
            // ISSUE 2E: Modified handleDoubleTap - Distance constraint
            handleDoubleTap(x, y) {
                console.log('👆 Double-tap detected at:', x, y);
                
                // Raycast to find what was tapped
                const mouse = new THREE.Vector2(
                    (x / window.innerWidth) * 2 - 1,
                    -(y / window.innerHeight) * 2 + 1
                );
                
                this.raycaster.setFromCamera(mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.artworks, true);
                
                if (intersects.length > 0) {
                    const artwork = intersects[0].object.parent;
                    if (artwork.userData.type === 'artwork') {
                        
                        // ✅ DISTANCE CHECK: Only allow double-tap within optimal range
                        const playerPos = this.camera.position;
                        const artPos = artwork.position;
                        const distance = playerPos.distanceTo(artPos);
                        const maxDoubleTapDistance = 6.0; // meters - adjust as needed
                        
                        if (distance > maxDoubleTapDistance) {
                            console.log(`❌ Double-tap too far (${distance.toFixed(1)}m), ignoring`);
                            return; // Too far away, ignore double-tap
                        }
                        
                        console.log(`🎯 Double-tap hit artwork: ${artwork.userData.title} (${distance.toFixed(1)}m)`);
                        this.focusedArtwork = artwork;
                        
                        // Visual feedback (only show if close enough)
                        const feedback = document.getElementById('tap-feedback');
                        if (feedback) {
                            feedback.style.left = `${x}px`;
                            feedback.style.top = `${y}px`;
                            feedback.classList.remove('animate');
                            void feedback.offsetWidth;
                            feedback.classList.add('animate');
                        }
                        
                        // Play click sound
                        if (this.sfxEnabled && this.sfx.click && !this.sfx.click.isPlaying) {
                            this.sfx.click.play();
                        }
                        
                        // Trigger focus mode
                        this.toggleArtworkInfo();
                    }
                }
            }
            
            findTouch(touchList, identifier) {
                for (let i = 0; i < touchList.length; i++) {
                    if (touchList[i].identifier === identifier) {
                        return touchList[i];
                    }
                }
                return null;
            }
            
            // Override updateMovement for mobile analog support
            updateMovementMobile() {
                if (this.isInspecting) return;
                
                const delta = Math.min(this.clock.getDelta(), 0.1);
                const dampingFactor = Math.pow(1 / CONFIG.camera.damping, delta);
                
                // Apply damping
                this.velocity.multiplyScalar(dampingFactor);
                
                if (this.velocity.length() < 0.001) {
                    this.velocity.set(0, 0, 0);
                }
                
                // Mobile analog movement (if joystick is active with intensity)
                if (this.isMobile && this.mobileState.joystick.active && this.mobileState.joystick.intensity) {
                    const intensity = this.mobileState.joystick.intensity;
                    const speedMultiplier = this.currentSpeedMultiplier || 1;
                    const sprintMult = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                    
                    // Get camera direction for movement
                    const direction = new THREE.Vector3();
                    this.camera.getWorldDirection(direction);
                    direction.y = 0;
                    direction.normalize();
                    
                    // Calculate right vector
                    const right = new THREE.Vector3();
                    right.crossVectors(direction, new THREE.Vector3(0, 1, 0)).normalize();
                    
                    // Apply movement based on joystick intensity
                    const moveSpeed = CONFIG.camera.acceleration * delta * speedMultiplier * sprintMult;
                    
                    this.velocity.x += (direction.x * intensity.y + right.x * intensity.x) * moveSpeed;
                    this.velocity.z += (direction.z * intensity.y + right.z * intensity.x) * moveSpeed;
                }
                // Fallback to digital movement (desktop style)
                else if (!this.isMobile) {
                    this.direction.set(0, 0, 0);
                    let isMoving = false;
                    
                    if (this.moveState.forward) { this.direction.z -= 1; isMoving = true; }
                    if (this.moveState.backward) { this.direction.z += 1; isMoving = true; }
                    if (this.moveState.left) { this.direction.x -= 1; isMoving = true; }
                    if (this.moveState.right) { this.direction.x += 1; isMoving = true; }
                    
                    if (isMoving && this.direction.length() > 0) {
                        this.direction.normalize();
                        const speedMultiplier = this.currentSpeedMultiplier || 1;
                        const sprintMult = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                        const totalMult = speedMultiplier * sprintMult;
                        
                        this.velocity.x += this.direction.x * CONFIG.camera.acceleration * delta * totalMult;
                        this.velocity.z += this.direction.z * CONFIG.camera.acceleration * delta * totalMult;
                    }
                }
                
                // Clamp speed
                const speedMultiplier = this.currentSpeedMultiplier || 1;
                const sprintMult = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                const maxSpeed = CONFIG.camera.maxSpeed * speedMultiplier * sprintMult;
                
                const currentSpeed = Math.sqrt(this.velocity.x ** 2 + this.velocity.z ** 2);
                if (currentSpeed > maxSpeed) {
                    const scale = maxSpeed / currentSpeed;
                    this.velocity.x *= scale;
                    this.velocity.z *= scale;
                }
                
                // Apply velocity
                if (!this.isMobile || !this.mobileState.joystick.active) {
                    // Desktop: use controls.moveRight/Forward
                    if (this.controls.isLocked) {
                        this.controls.moveRight(this.velocity.x * delta);
                        this.controls.moveForward(-this.velocity.z * delta);
                    }
                } else {
                    // Mobile: direct position update (no PointerLock)
                    this.camera.position.x += this.velocity.x * delta;
                    this.camera.position.z += this.velocity.z * delta;
                }
                
                // Room boundaries
                this._enforceRoomBounds();
                
                this.camera.position.y = CONFIG.camera.height;
                
                // Cinematic lean
                const targetLean = -this.velocity.x * CONFIG.camera.maxLean;
                this.currentLean += (targetLean - this.currentLean) * CONFIG.camera.leanSpeed;
                
                // Footsteps
                if (this.sfxEnabled && this.sfx.footstep) {
                    const hasInput = this.isMobile 
                        ? this.mobileState.joystick.active 
                        : (this.moveState.forward || this.moveState.backward || this.moveState.left || this.moveState.right);
                    
                    const currentSpeed = this.velocity.length();
                    const isMovingNow = hasInput && currentSpeed > 0.05;
                    
                    if (isMovingNow) {
                        const baseInterval = 0.5;
                        const sprintMult = this.moveState.sprint ? 0.6 : 1.0;
                        const speedMult = this.currentSpeedMultiplier || 1.0;
                        let stepInterval = (baseInterval * sprintMult) / Math.sqrt(speedMult);
                        stepInterval = Math.max(0.2, Math.min(0.6, stepInterval));
                        
                        this.footstepTimer += delta;
                        
                        if (this.footstepTimer >= stepInterval) {
                            if (this.sfx.footstep.isPlaying) this.sfx.footstep.stop();
                            const pitchVariation = 0.95 + Math.random() * 0.1;
                            this.sfx.footstep.setPlaybackRate(pitchVariation);
                            this.sfx.footstep.play();
                            this.footstepTimer = 0;
                        }
                    } else {
                        this.footstepTimer = 0;
                        if (this.sfx.footstep.isPlaying) this.sfx.footstep.stop();
                    }
                }
            }

            // ✅ UPDATED: Progress updates on curtain instead of separate loader
            updateProgress(percent, text) {
                const bar = document.getElementById('curtain-progress-bar');
                const percentText = document.getElementById('curtain-progress-percent');
                const statusText = document.getElementById('curtain-progress-text');
                
                if (bar) bar.style.width = `${percent}%`;
                if (percentText) percentText.textContent = `${Math.round(percent)}%`;
                if (statusText) statusText.textContent = text;
                
                // ✅ Enable "Enter" button when loading completes
                if (percent >= 100) {
                    const enterBtn = document.getElementById('enter-btn');
                    enterBtn.style.opacity = '1';
                    enterBtn.style.pointerEvents = 'auto';
                    enterBtn.style.transition = 'all 0.3s ease';

                    if (statusText) statusText.textContent = 'Ready to enter';

                    // Add pulse animation to button
                    enterBtn.style.animation = 'pulse 2s ease-in-out infinite';


                }
            }

            hideLoader() {
                // ✅ No separate loader to hide - we fade the curtain instead
                console.log('✅ Loading complete - gallery ready');
            }
        }

        // ✅ GLOBAL: Store scene instance so we can access it from button click
        let galleryScene = null;

        // ✅ SILENT PRELOAD: Start loading immediately when page loads
        document.addEventListener('DOMContentLoaded', () => {
            console.log('🎬 Starting silent preload of 3D gallery...');
            galleryScene = new GalleryScene();
        });

        // ✅ ENTRANCE BUTTON: Resume audio + fade to 3D
        document.getElementById('enter-btn').addEventListener('click', () => {
            console.log('🎯 User clicked Enter Exhibition');
            
            // ✅ CRITICAL: Resume audio context (required by browsers)
            if (galleryScene && galleryScene.listener && galleryScene.listener.context) {
                if (galleryScene.listener.context.state === 'suspended') {
                    console.log('🔊 Resuming audio context...');
                    galleryScene.listener.context.resume().then(() => {
                        console.log('✅ Audio context resumed - music should play');
                        
                        // Play the audio
                        if (galleryScene.playAudio) {
                            galleryScene.playAudio();
                        }
                    });
                } else {
                    console.log('🔊 Audio context already running');
                    if (galleryScene.playAudio) {
                        galleryScene.playAudio();
                    }
                }
            }
            
            // ✅ MOBILE: Initialize controls immediately on enter
            if (galleryScene && galleryScene.isMobile) {
                console.log('📱 Mobile mode: Controls active');
                // Ensure mobile overlay is visible
                const overlay = document.getElementById('mobile-overlay');
                if (overlay) overlay.classList.add('active');
            }
            
            // ✅ Smooth fade transition
            const curtain = document.getElementById('entrance-curtain');
            curtain.style.opacity = '0';
            curtain.style.transition = 'opacity 1s ease';
            
            setTimeout(() => {
                curtain.remove();
                console.log('✅ Entered 3D gallery');
            }, 1000);
        });

        // ================================================================
        // GUIDED TOUR SYSTEM
        // ================================================================
        class GuidedTour {
            constructor(scene) {
                this.scene       = scene;            // GalleryScene instance
                this.artworks    = [];               // populated after gallery builds
                this.index       = 0;                // current artwork index
                this.active      = false;
                this.paused      = false;
                this._dwellMs    = 5000;             // ms to dwell at each artwork
                this._dwellTimer = null;
                this._countdownRaf = null;
                this._countdownStart = null;

                // DOM refs
                this._overlay    = document.getElementById('tour-overlay');
                this._hud        = document.getElementById('tour-hud');
                this._progressBar = document.getElementById('tour-progress-bar');
                this._counter    = document.getElementById('tour-counter');
                this._titleEl    = document.getElementById('tour-title-display');
                this._ringArc    = document.getElementById('tour-ring-arc');
                this._circumference = 2 * Math.PI * 15.9; // matches SVG r="15.9"

                // Buttons
                document.getElementById('tour-prev-btn').addEventListener('click',  () => this.prev());
                document.getElementById('tour-next-btn').addEventListener('click',  () => this.next());
                document.getElementById('tour-pause-btn').addEventListener('click', () => this.togglePause());
                document.getElementById('tour-exit-btn').addEventListener('click',  () => this.stop());
            }

            // ── Public API ───────────────────────────────────────────────

            start(fromIndex = 0) {
                this.artworks = this.scene.artworks || [];
                if (this.artworks.length === 0) return;

                this.active  = false; // reset so _enterTourMode works cleanly
                this.paused  = false;
                this.index   = fromIndex;

                this._enterTourMode();
                this._goTo(this.index);
            }

            stop() {
                if (!this.active) return;
                this._clearDwell();
                this._clearCountdown();
                this.active = false;
                this.paused = false;

                // Hide overlay
                this._overlay.classList.remove('active');
                this._hud.classList.remove('paused');

                // Hide info panel
                const panel = document.getElementById('info-panel');
                if (panel) panel.classList.remove('show');

                // Kill any in-flight tween
                if (this.scene.focusTween) {
                    this.scene.focusTween.kill();
                    this.scene.focusTween = null;
                }

                // Restore camera to pre-tour position smoothly
                this.scene.isInspecting = false;
                this.scene.velocity.set(0, 0, 0);

                gsap.to(this.scene.camera.position, {
                    x: this._preTourPos.x,
                    y: this._preTourPos.y,
                    z: this._preTourPos.z,
                    duration: 1.4,
                    ease: 'power2.inOut',
                    onUpdate: () => {
                        this.scene.camera.quaternion.slerp(this._preTourQuat, 0.08);
                    },
                    onComplete: () => {
                        if (!this.scene.isMobile && !this.scene.controls.isLocked) {
                            this.scene.controls.lock();
                        }
                    }
                });
            }

            next() {
                this._clearDwell();
                this._clearCountdown();
                this.index = (this.index + 1) % this.artworks.length;
                if (this.index === 0) {
                    // Finished — stop tour instead of looping
                    this.stop();
                    return;
                }
                this._goTo(this.index);
            }

            prev() {
                this._clearDwell();
                this._clearCountdown();
                this.index = (this.index - 1 + this.artworks.length) % this.artworks.length;
                this._goTo(this.index);
            }

            togglePause() {
                this.paused = !this.paused;
                this._hud.classList.toggle('paused', this.paused);

                if (this.paused) {
                    this._clearDwell();
                    this._clearCountdown();
                    // Freeze ring where it is
                    if (this._ringArc) {
                        const elapsed = performance.now() - (this._countdownStart || performance.now());
                        const remaining = Math.max(0, 1 - elapsed / this._dwellMs);
                        const offset = this._circumference * remaining;
                        this._ringArc.style.transition = 'none';
                        this._ringArc.style.strokeDashoffset = offset;
                    }
                } else {
                    // Resume — restart dwell from now (full duration, not remaining)
                    this._startDwell();
                }
            }

            // ── Private ─────────────────────────────────────────────────

            _enterTourMode() {
                // Save pre-tour state
                this._preTourPos  = this.scene.camera.position.clone();
                this._preTourQuat = this.scene.camera.quaternion.clone();

                // Lock out player controls
                this.active = true;
                this.scene.isInspecting = true;
                this.scene.velocity.set(0, 0, 0);
                this.scene.currentLean = 0;

                if (this.scene.controls.isLocked) this.scene.controls.unlock();

                // Show tour overlay
                this._overlay.classList.add('active');
            }

            _goTo(idx) {
                const artwork = this.artworks[idx];
                if (!artwork) return;

                // Close any open info panel first
                const panel = document.getElementById('info-panel');
                if (panel) panel.classList.remove('show');

                // Update HUD
                const n = this.artworks.length;
                const raw = artwork.userData.title || 'Untitled';
                const title = raw.includes('.')
                    ? raw.split('.').slice(0, -1).join('.').replace(/[_-]/g, ' ')
                    : raw;

                this._counter.textContent    = `${idx + 1} / ${n}`;
                this._titleEl.textContent    = title;
                this._progressBar.style.width = `${((idx + 1) / n) * 100}%`;

                // Fly camera to artwork
                const artworkWorldPos = new THREE.Vector3();
                artwork.getWorldPosition(artworkWorldPos);

                const artDir = new THREE.Vector3(0, 0, 1);
                artwork.getWorldDirection(artDir);

                const focusDist = 1.8;
                const targetPos = artworkWorldPos.clone().add(artDir.multiplyScalar(focusDist));
                targetPos.y = CONFIG.camera.height;

                // Kill any in-flight tween
                if (this.scene.focusTween) {
                    this.scene.focusTween.kill();
                    this.scene.focusTween = null;
                }

                this.scene.focusTween = gsap.to(this.scene.camera.position, {
                    x: targetPos.x,
                    y: targetPos.y,
                    z: targetPos.z,
                    duration: 1.8,
                    ease: 'power2.inOut',
                    onUpdate: () => {
                        this.scene.camera.lookAt(artworkWorldPos);
                    },
                    onComplete: () => {
                        this.scene.camera.lookAt(artworkWorldPos);

                        // Show info panel
                        setTimeout(() => {
                            if (!this.active) return;
                            document.getElementById('artwork-title').textContent = title;
                            document.getElementById('artwork-description').textContent =
                                artwork.userData.description || 'No description available.';
                            if (panel) panel.classList.add('show');

                            // Play click SFX
                            if (this.scene.sfxEnabled && this.scene.sfx.click && !this.scene.sfx.click.isPlaying) {
                                this.scene.sfx.click.play();
                            }

                            // Start dwell timer (auto-advance)
                            if (!this.paused) this._startDwell();
                        }, 350);
                    }
                });
            }

            _startDwell() {
                this._clearDwell();
                this._clearCountdown();
                this._countdownStart = performance.now();
                this._animateCountdown();
                this._dwellTimer = setTimeout(() => {
                    if (!this.paused && this.active) this.next();
                }, this._dwellMs);
            }

            _clearDwell() {
                if (this._dwellTimer) { clearTimeout(this._dwellTimer); this._dwellTimer = null; }
            }

            _animateCountdown() {
                this._clearCountdown();
                const circumference = this._circumference;
                const start = performance.now();
                const duration = this._dwellMs;
                const arc = this._ringArc;
                if (!arc) return;

                const tick = (now) => {
                    if (!this.active || this.paused) return;
                    const elapsed = now - start;
                    const fraction = Math.min(elapsed / duration, 1);
                    // Offset goes from circumference (empty) down to 0 (full)
                    arc.style.strokeDashoffset = circumference * (1 - fraction);
                    if (fraction < 1) {
                        this._countdownRaf = requestAnimationFrame(tick);
                    }
                };
                this._countdownRaf = requestAnimationFrame(tick);
            }

            _clearCountdown() {
                if (this._countdownRaf) { cancelAnimationFrame(this._countdownRaf); this._countdownRaf = null; }
                if (this._ringArc) this._ringArc.style.strokeDashoffset = this._circumference;
            }
        }

        // ── Tour wiring ─────────────────────────────────────────────────
        let guidedTour = null;

        function startGuidedTour() {
            if (!galleryScene) return;
            if (!guidedTour) guidedTour = new GuidedTour(galleryScene);
            // Resume audio context on first user gesture
            if (galleryScene.listener && galleryScene.listener.context &&
                galleryScene.listener.context.state === 'suspended') {
                galleryScene.listener.context.resume().then(() => {
                    if (galleryScene.playAudio) galleryScene.playAudio();
                });
            }
            // Fade out curtain if still visible
            const curtain = document.getElementById('entrance-curtain');
            if (curtain) {
                curtain.style.opacity = '0';
                curtain.style.transition = 'opacity 0.8s ease';
                setTimeout(() => { curtain.remove(); }, 800);
            }
            guidedTour.start(0);
        }

        // Tour keyboard controls
        document.addEventListener('keydown', (e) => {
            if (e.code === 'KeyT') {
                if (guidedTour && guidedTour.active) {
                    guidedTour.stop();
                } else {
                    startGuidedTour();
                }
                return;
            }
            // Arrow keys — only active during guided tour
            if (guidedTour && guidedTour.active) {
                if (e.code === 'ArrowRight' || e.code === 'ArrowDown') {
                    e.preventDefault();
                    guidedTour.next();
                } else if (e.code === 'ArrowLeft' || e.code === 'ArrowUp') {
                    e.preventDefault();
                    guidedTour.prev();
                } else if (e.code === 'Space') {
                    e.preventDefault();
                    guidedTour.togglePause();
                }
            }
        });

        // Hide in-gallery tour button while tour is active, show when stopped
        const _origTourStart = GuidedTour.prototype._enterTourMode;
        GuidedTour.prototype._enterTourMode = function() {
            _origTourStart.call(this);
            const btn = document.getElementById('in-gallery-tour-btn');
            if (btn) btn.style.display = 'none';
        };
        const _origTourStop = GuidedTour.prototype.stop;
        GuidedTour.prototype.stop = function() {
            _origTourStop.call(this);
            const btn = document.getElementById('in-gallery-tour-btn');
            if (btn) btn.style.display = 'flex';
        };

    </script>
</body>
</html>