<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ Str::limit($gallery->description, 150) }}">
    <title>{{ $gallery->title }} | Exospace 3D Gallery</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
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
            pointer-events: none;
            z-index: 50;
            display: none; /* Shown via JS on mobile detection */
        }

        #mobile-overlay.active {
            display: block;
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

        /* Speed Dial (Bottom Right) */
        #speed-dial {
            position: absolute;
            right: 20px;
            bottom: 80px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: auto;
        }

        .speed-btn {
            width: 50px;
            height: 50px;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            transition: all 0.2s ease;
            user-select: none;
            touch-action: manipulation;
        }

        .speed-btn.active {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .speed-btn:active {
            transform: scale(0.95);
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
                Use WASD to move • Mouse to look around • Click for full control
            </p>
        </div>
    </div>

    <!-- 3D Canvas -->
    <div id="canvas-container"></div>

    <!-- UI Overlay -->
    <div id="ui-layer">
        <!-- Header -->
        <div class="absolute top-6 left-6">
            <h1 class="text-white text-4xl font-bold drop-shadow-lg mb-2">{{ $gallery->title }}</h1>
            <p class="text-white/80 text-sm max-w-md drop-shadow hidden md:block">
                {{ Str::limit($gallery->description, 120) }}
            </p>
        </div>

        <!-- Speed Indicator -->
        <div class="absolute top-6 right-6">
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
            <div class="mt-3 pt-3 border-t border-white/10">
                <p class="text-xs text-gray-500">Press E to close</p>
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
            🎯 FOCUS MODE • Press E to Exit
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
            
            <!-- Speed Dial -->
            <div id="speed-dial">
                <button class="speed-btn active" data-speed="0">1x</button>
                <button class="speed-btn" data-speed="1">2x</button>
                <button class="speed-btn" data-speed="2">4x</button>
                <button class="speed-btn" data-speed="3">8x</button>
            </div>
            
            <!-- Mobile Hint -->
            <div id="mobile-hint">
                <p>👆 Double-tap artwork to focus</p>
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
                lighting_preset: "bright", // Options: 'bright', 'moody', 'dramatic'
                frame_style: "modern",
                imageCount: mockImages.length,
                audioUrl: null, // Added for mock data consistency
                // CHANGE #1: Added these fields to Mock Data to support the new Branding UI logic
                'userPlan': 'studio', // Options: 'studio', 'pro', 'free'
                'customLogoUrl': 'https://via.placeholder.com/200x50.png?text=Studio+Logo', // Placeholder for testing
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
                
                // SECTION 7: Optional - Add Fog for Depth (Reduces Brightness Perception)
                // ✨ NEW: Add subtle fog for depth and softer look
                this.scene.fog = new THREE.Fog(0x0a0a0a, 10, 30); // (color, near, far)
                // This adds atmospheric depth and softens the overall scene.

                // Camera
                this.camera = new THREE.PerspectiveCamera(
                    CONFIG.camera.fov,
                    window.innerWidth / window.innerHeight,
                    CONFIG.camera.near,
                    CONFIG.camera.far
                );
                
                // Start in center of room at eye level
                this.camera.position.set(0, CONFIG.camera.height, 0);

                // Renderer
                this.renderer = new THREE.WebGLRenderer({ 
                    antialias: true,
                    powerPreference: 'high-performance'
                });
                this.renderer.setSize(window.innerWidth, window.innerHeight);
                this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
                this.renderer.shadowMap.enabled = true;
                this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

                // SECTION 4: Fine-Tune Tone Mapping (Fix Brightness/Contrast)
                // ✨ Tone Mapping with balanced exposure
                this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
                this.renderer.toneMappingExposure = 0.8; // ✨ Default lower exposure
                this.renderer.outputColorSpace = THREE.SRGBColorSpace;

                // ✨ Optional: Reduce contrast if still too harsh
                // Uncomment below if you want even softer contrast:
                // this.renderer.toneMapping = THREE.LinearToneMapping;

                this.container.appendChild(this.renderer.domElement);

                // Controls
                this.setupControls();
                
                // Load assets then build
                this.loadAssets();
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

                    const configureTexture = (texture) => {
                        texture.colorSpace = THREE.SRGBColorSpace;
                        texture.generateMipmaps = true;
                        texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
                        return texture;
                    };

                    // SECTION 3: Update loadAssets() - Dynamic HDRI Loading
                    this.updateProgress(8, 'Loading environment lighting...');

                    // Get HDRI path from lighting preset config
                    const lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                    const hdriPath = lightingConfig.hdri;

                    if (hdriPath) {
                        const rgbeLoader = new RGBELoader();
                        promises.push(new Promise((resolve) => {
                            rgbeLoader.load(
                                hdriPath,
                                (texture) => {
                                    texture.mapping = THREE.EquirectangularReflectionMapping;
                                    this.scene.environment = texture;
                                    
                                    // Apply environment intensity from preset
                                    if (lightingConfig.envIntensity !== undefined) {
                                        this.scene.environmentIntensity = lightingConfig.envIntensity;
                                    }
                                    
                                    // Apply tone mapping exposure from preset
                                    if (lightingConfig.toneMappingExposure !== undefined) {
                                        this.renderer.toneMappingExposure = lightingConfig.toneMappingExposure;
                                    }
                                    
                                    console.log(`✅ HDRI loaded: ${hdriPath} (Preset: ${preset})`);
                                    resolve();
                                },
                                undefined,
                                (error) => {
                                    console.warn(`⚠️ HDRI loading failed (${hdriPath}), using fallback lighting:`, error);
                                    // Fallback: No HDRI, just use the regular lights we already have
                                    resolve();
                                }
                            );
                        }));
                    } else {
                        console.log('ℹ️ No HDRI specified for this preset, using standard lighting');
                    }

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

                    // 🎨 NEW: Load canvas normal map for tactile art effect
                    promises.push(new Promise(resolve => {
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

                    // 3. Load Artworks
                    this.updateProgress(30, 'Loading artwork...');
                    this.artworkImages = []; 
                    
                    const artworkPromises = data.images.map((img, index) => {
                        return new Promise((resolve) => {
                            const image = new Image();
                            image.crossOrigin = 'anonymous';
                            image.onload = () => {
                                this.artworkImages.push({
                                    id: img.id,
                                    image: image,
                                    aspectRatio: img.aspectRatio,
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
                            };
                            image.onerror = (err) => {
                                console.error(`Failed to load artwork: ${img.url}`, err);
                                resolve();
                            };
                            image.src = img.url;
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
                
                // SECTION 9: Store lighting preset
                this.lightingPreset = data.lighting_preset;
                
                // SETUP 1: Setup lighting
                this.setupLighting(data.lighting_preset);
                
                // SETUP 2: Create room
                this.createRoom(data);
                
                // SETUP 3: Place artworks
                this.placeArtworks(data);
                
                // Start render loop
                this.animate();
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
                floor.receiveShadow = true;
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

                wallConfigs.forEach(config => {
                    const wallMesh = new THREE.Mesh(
                        new THREE.BoxGeometry(wallLength, wallHeight, CONFIG.room.wallDepth),
                        wallMaterial
                    );
                    wallMesh.position.set(...config.pos);
                    wallMesh.rotation.set(...config.rot);
                    wallMesh.receiveShadow = true;
                    wallMesh.castShadow = true;
                    wallMesh.name = `wall_${config.name}`;
                    this.scene.add(wallMesh);
                });

                // CEILING
                const ceilingMaterial = new THREE.MeshStandardMaterial({ 
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
                ceiling.receiveShadow = true;
                ceiling.name = 'ceiling';
                this.scene.add(ceiling);

                // DYNAMIC DISTRIBUTED LIGHTING
                const roomLightingConfig = this.lightingConfig;
                const maxLights = 8;
                const gridSize = Math.min(3, Math.ceil(Math.sqrt(maxLights)));
                
                const startX = -(wallLength / 2) + (wallLength / (gridSize + 1));
                const startZ = -(wallLength / 2) + (wallLength / (gridSize + 1));
                const stepX = wallLength / (gridSize + 1);
                const stepZ = wallLength / (gridSize + 1);

                for (let i = 0; i < gridSize; i++) {
                    for (let j = 0; j < gridSize; j++) {
                        const xPos = startX + (i * stepX);
                        const zPos = startZ + (j * stepZ);
                        
                        const fillLight = new THREE.PointLight(
                            0xfff8e8,
                            roomLightingConfig.fillLight * 1.5, 
                            wallLength * 0.8 
                        );
                        fillLight.position.set(xPos, CONFIG.room.wallHeight - 0.5, zPos);
                        fillLight.castShadow = false; 
                        this.scene.add(fillLight);
                    }
                }

                console.log(`💡 Created optimized ceiling lights for ${wallLength}m room`);
                console.log(`📐 Room created: ${wallLength}m x ${wallLength}m x ${wallHeight}m`);

                // STORE ROOM BOUNDARIES FOR COLLISION
                this.roomBounds = {
                    minX: -wallLength / 2,
                    maxX: wallLength / 2,
                    minZ: -wallLength / 2,
                    maxZ: wallLength / 2
                };
            }

            getWallMaterial(type) {
                const fallbackColors = {
                    white: 0xf5f5f5,
                    concrete: 0x8a8a8a,
                    brick: 0xa0826d,
                    wood: 0x8b6f47
                };

                if (!this.textures.wall) {
                    return new THREE.MeshStandardMaterial({ 
                        color: fallbackColors[type] || fallbackColors.white,
                        roughness: 0.8,
                        metalness: 0.1
                    });
                }

                const texture = this.textures.wall.clone();
                texture.needsUpdate = true;
                texture.wrapS = THREE.RepeatWrapping;
                texture.wrapT = THREE.RepeatWrapping;
                
                const properties = {
                    white: { roughness: 0.8, metalness: 0.1 },
                    concrete: { roughness: 0.9, metalness: 0.0 },
                    brick: { roughness: 0.95, metalness: 0.0 },
                    wood: { roughness: 0.7, metalness: 0.1 }
                };

                const props = properties[type] || properties.white;

                return new THREE.MeshStandardMaterial({ 
                    map: texture,
                    roughness: props.roughness,
                    metalness: props.metalness,
                    side: THREE.FrontSide 
                });
            }

            // SECTION 4: Update Floor Materials (Support for environmentIntensity)
            getFloorMaterial(type) {
                const fallbackColors = {
                    wood: 0x5c4033,
                    marble: 0xe8e8e8,
                    concrete: 0x6b6b6b
                };
                
                // Get preset intensity (initialized in buildGallery -> setupLighting)
                const lightingConfig = this.lightingConfig || CONFIG.lighting.bright;
                const envIntensity = lightingConfig.envIntensity || 1.0;

                const materials = {
                    wood: new THREE.MeshStandardMaterial({
                        map: this.textures.floor || null,
                        color: this.textures.floor ? 0xffffff : fallbackColors.wood,
                        roughness: 0.7,
                        metalness: 0.1,
                        envMapIntensity: 0.6 * envIntensity, // ✨ Scaled by preset
                    }),
                    marble: new THREE.MeshStandardMaterial({
                        map: this.textures.floor || null,
                        color: this.textures.floor ? 0xffffff : fallbackColors.marble,
                        roughness: 0.3,
                        metalness: 0.2,
                        envMapIntensity: 1.2 * envIntensity, // ✨ Scaled by preset
                    }),
                    concrete: new THREE.MeshStandardMaterial({
                        map: this.textures.floor || null,
                        color: this.textures.floor ? 0xffffff : fallbackColors.concrete,
                        roughness: 0.9,
                        metalness: 0.05,
                        envMapIntensity: 0.3 * envIntensity // ✨ Scaled by preset
                    })
                };

                // If texture exists, configure it
                if (this.textures.floor) {
                    const mat = materials[type] || materials.wood;
                    mat.map = this.textures.floor.clone();
                    mat.map.wrapS = THREE.RepeatWrapping;
                    mat.map.wrapT = THREE.RepeatWrapping;
                    mat.needsUpdate = true;
                    return mat;
                }

                return materials[type] || materials.wood;
            }

            // SECTION 6: Adjust Ambient Light (In setupLighting method)
            setupLighting(preset) {
                this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                const config = this.lightingConfig;

                const ambientLight = new THREE.AmbientLight(0xffffff, config.ambient);
                this.scene.add(ambientLight);

                // ✨ NEW: Add subtle hemisphere light for more natural lighting
                const hemisphereLight = new THREE.HemisphereLight(
                    0xffffff,  // Sky color
                    0x444444,  // Ground color
                    0.3        // Intensity (subtle)
                );
                this.scene.add(hemisphereLight);

                const dirLight = new THREE.DirectionalLight(0xffffff, 0.3);
                dirLight.position.set(0, 10, 5);
                dirLight.target.position.set(0, 0, 0);
                dirLight.castShadow = false;
                this.scene.add(dirLight);
                this.scene.add(dirLight.target);

                console.log(`💡 Lighting setup: ${preset} (ambient: ${config.ambient}, fill: ${config.fillLight})`);
            }

            placeArtworks(data) {
                if (this.artworkImages.length === 0) return;

                // Recalculate wall dimensions to match createRoom logic
                const imageCount = this.artworkImages.length;
                const spacing = CONFIG.room.artworkSpacing;
                const minWallLength = CONFIG.room.minWallLength;
                const imagesPerWall = Math.ceil(imageCount / 4);
                const calculatedWallLength = (imagesPerWall * spacing) + spacing;
                const wallLength = Math.max(minWallLength, calculatedWallLength);
                
                const eyeLevel = CONFIG.camera.height;

                const walls = [
                    { name: 'front', start: [-wallLength/2 + spacing, eyeLevel, -wallLength/2 + 0.2], direction: [1, 0, 0], normal: [0, 0, 1] },
                    { name: 'back', start: [wallLength/2 - spacing, eyeLevel, wallLength/2 - 0.2], direction: [-1, 0, 0], normal: [0, 0, -1] },
                    { name: 'left', start: [-wallLength/2 + 0.2, eyeLevel, wallLength/2 - spacing], direction: [0, 0, -1], normal: [1, 0, 0] },
                    { name: 'right', start: [wallLength/2 - 0.2, eyeLevel, -wallLength/2 + spacing], direction: [0, 0, 1], normal: [-1, 0, 0] }
                ];

                let wallIndex = 0;
                let positionOnWall = 0;

                this.artworkImages.forEach((img, index) => {
                    const wall = walls[wallIndex];
                    
                    const maxWidth = 2.0;
                    const maxHeight = 2.5;
                    let width, height;
                    
                    if (img.aspectRatio > 1) {
                        width = maxWidth;
                        height = width / img.aspectRatio;
                    } else {
                        height = maxHeight;
                        width = height * img.aspectRatio;
                    }

                    const frame = this.createFrame(width, height, data.frame_style);
                    
                    const canvas = document.createElement('canvas');
                    canvas.width = img.image.width;
                    canvas.height = img.image.height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img.image, 0, 0);
                    
                    const texture = new THREE.CanvasTexture(canvas);
                    texture.colorSpace = THREE.SRGBColorSpace;
                    
                    const artworkGeo = new THREE.PlaneGeometry(width * 0.95, height * 0.95);
                    
                    // 🎨 ENHANCED: Canvas material with normal mapping for realistic texture
                    const artworkMat = new THREE.MeshStandardMaterial({
                        map: texture,
                        
                        // Physical canvas properties
                        normalMap: this.textures.canvasNormal,          // Apply woven texture
                        normalScale: new THREE.Vector2(0.35, 0.35),     // Subtle depth (adjust 0.2-0.5 for taste)
                        roughness: 0.75,                                 // Matte finish like real canvas
                        metalness: 0.0,                                  // Non-reflective cloth surface
                    });

                    // Scale canvas grain based on artwork physical size
                    // This prevents the texture from stretching on large paintings
                    if (artworkMat.normalMap) {
                        // Calculate appropriate tiling:
                        // Larger artworks need more repetitions to keep grain size consistent
                        const tilingFactor = 2.5; // Adjust this to make grain finer (higher) or coarser (lower)
                        artworkMat.normalMap.repeat.set(
                            width * tilingFactor, 
                            height * tilingFactor
                        );
                        
                        console.log(`🎨 Canvas texture applied to artwork ${index + 1} (${width.toFixed(2)}m × ${height.toFixed(2)}m)`);
                    }
                    
                    const artwork = new THREE.Mesh(artworkGeo, artworkMat);
                    artwork.position.z = 0.05;
                    
                    const group = new THREE.Group();
                    group.add(frame);
                    group.add(artwork);
                    
                    const offset = positionOnWall * spacing;
                    group.position.set(
                        wall.start[0] + wall.direction[0] * offset,
                        wall.start[1],
                        wall.start[2] + wall.direction[2] * offset
                    );
                    
                    group.lookAt(
                        group.position.x + wall.normal[0],
                        group.position.y + wall.normal[1],
                        group.position.z + wall.normal[2]
                    );
                    
                    group.userData = {
                        type: 'artwork',
                        id: img.id,
                        title: img.title,
                        description: img.description
                    };
                    
                    this.scene.add(group);
                    this.artworks.push(group);
                    
                    // SECTION 8: Update placeArtworks() - Light for EVERY artwork
                    this.addArtworkLight(group, data.lighting_preset);
                    
                    positionOnWall++;
                    if (positionOnWall >= imagesPerWall) {
                        positionOnWall = 0;
                        wallIndex++;
                    }
                });
                
                console.log(`🖼️ Placed ${this.artworkImages.length} artworks using proximity lighting`);
            }

            // SECTION 5: Update Frame Material (Optional but Recommended)
            createFrame(width, height, style) {
                const frameDepth = 0.08;
                const frameWidth = 0.1;
                
                const colors = {
                    modern: 0x2c2c2c,
                    classic: 0x8b7355,
                    minimal: 0xffffff
                };

                // Get preset intensity
                const lightingConfig = this.lightingConfig || CONFIG.lighting.bright;

                const frameMat = new THREE.MeshStandardMaterial({
                    color: colors[style] || colors.modern,
                    roughness: 0.3,
                    metalness: 0.8,
                    envMapIntensity: 1.5 * (lightingConfig.envIntensity || 1.0) // ✨ Frames gleam based on preset
                });

                const frame = new THREE.Group();
                
                const pieces = [
                    new THREE.BoxGeometry(width + frameWidth * 2, frameWidth, frameDepth), 
                    new THREE.BoxGeometry(width + frameWidth * 2, frameWidth, frameDepth), 
                    new THREE.BoxGeometry(frameWidth, height, frameDepth), 
                    new THREE.BoxGeometry(frameWidth, height, frameDepth)  
                ];

                const positions = [
                    [0, height/2 + frameWidth/2, 0],
                    [0, -height/2 - frameWidth/2, 0],
                    [-width/2 - frameWidth/2, 0, 0],
                    [width/2 + frameWidth/2, 0, 0]
                ];

                pieces.forEach((geo, i) => {
                    const mesh = new THREE.Mesh(geo, frameMat);
                    mesh.position.set(...positions[i]);
                    mesh.castShadow = true;
                    frame.add(mesh);
                });

                return frame;
            }

            // ==========================================
            // FIX 1: Make Proximity Lights Visible Again (UPDATED)
            // ==========================================
            addArtworkLight(artworkGroup, preset) {
                const config = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                
                // Create PointLight for each artwork (initially OFF)
                // ✨ DRAMATICALLY INCREASED: Much stronger intensity for visibility
                const artworkLight = new THREE.PointLight(
                    0xfff5e6,
                    config.spot * 3.5,  // ✨ INCREASED multiplier to 3.5 (5x stronger than original 0.7!)
                    10                  // ✨ INCREASED range from 8 to 10
                );
                
                // Position light in front of artwork
                const normal = new THREE.Vector3(0, 0, 1);
                normal.applyQuaternion(artworkGroup.quaternion);
                
                artworkLight.position.copy(artworkGroup.position);
                artworkLight.position.y += 0.3;  // ✨ REDUCED from 0.5 (closer to artwork center)
                artworkLight.position.add(normal.multiplyScalar(0.8));  // ✨ REDUCED from 1.2 (closer to artwork)
                
                artworkLight.castShadow = false;
                artworkLight.visible = false; // Start OFF
                
                this.scene.add(artworkLight);
                
                // Store reference for proximity detection
                artworkGroup.userData.light = artworkLight;
            }

            // SECTION 5 (Continued): Update Proximity Logic WITH DEBUG
            updateProximityLighting() {
                if (!this.artworks || this.artworks.length === 0) return;
                
                const playerPos = this.camera.position;
                
                // ✨ FIX: Get proximityDistance from the correct preset
                const lightingConfig = this.lightingConfig || CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright;
                const proximityDist = lightingConfig.proximityDistance || 5; // Fallback to 5
                const sqrProximityDist = proximityDist * proximityDist;
                
                let closestArtwork = null;
                let closestDistSqr = Infinity;
                
                // Find closest artwork
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
                
                // ✨ DEBUG: Log detection status
                if (closestArtwork) {
                    const dist = Math.sqrt(closestDistSqr).toFixed(2);
                    console.log(`🎯 Closest artwork at ${dist}m | Threshold: ${proximityDist}m`);
                } else {
                    console.log(`❌ No artwork within ${proximityDist}m range`);
                }
                
                // Update lights (only one active at a time)
                for (const artwork of this.artworks) {
                    const light = artwork.userData.light;
                    
                    // ✨ DEBUG: Check if light exists
                    if (!light) {
                        console.warn('⚠️ Artwork has no light attached!', artwork.userData);
                        continue;
                    }
                    
                    if (artwork === closestArtwork) {
                        if (!light.visible) {
                            light.visible = true;
                            light.intensity = 0;
                            console.log('💡 Light turning ON for:', artwork.userData.title);
                        }
                        // Smooth fade in
                        // ✨ UPDATED: Match the 3.5 multiplier for visibility
                        const targetIntensity = (CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright).spot * 3.5;
                        light.intensity = Math.min(light.intensity + 0.2, targetIntensity);
                        
                        // ✨ DEBUG: Log intensity changes
                        if (Math.random() < 0.1) { // Log only 10% of frames to avoid spam
                            console.log(`💡 Light intensity: ${light.intensity.toFixed(2)} / ${targetIntensity.toFixed(2)}`);
                        }
                    } else {
                        // Smooth fade out
                        if (light.intensity > 0) {
                            light.intensity = Math.max(0, light.intensity - 0.1);
                        } else {
                            if (light.visible) {
                                console.log('💡 Light turning OFF');
                            }
                            light.visible = false;
                        }
                    }
                }
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
                if (this.roomBounds) {
                    const margin = 0.5;
                    const prevX = this.camera.position.x;
                    const prevZ = this.camera.position.z;
                    
                    if (this.camera.position.x < this.roomBounds.minX + margin) {
                        this.camera.position.x = this.roomBounds.minX + margin;
                    }
                    if (this.camera.position.x > this.roomBounds.maxX - margin) {
                        this.camera.position.x = this.roomBounds.maxX - margin;
                    }
                    if (this.camera.position.z < this.roomBounds.minZ + margin) {
                        this.camera.position.z = this.roomBounds.minZ + margin;
                    }
                    if (this.camera.position.z > this.roomBounds.maxZ - margin) {
                        this.camera.position.z = this.roomBounds.maxZ - margin;
                    }
                    
                    // If we hit a wall, zero out velocity in that direction
                    if (this.camera.position.x !== prevX) {
                        this.velocity.x = 0;
                    }
                    if (this.camera.position.z !== prevZ) {
                        this.velocity.z = 0;
                    }
                }

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

                this.raycaster.setFromCamera(new THREE.Vector2(0, 0), this.camera);
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

            // SECTION 7: Update animate() method
            animate() {
                requestAnimationFrame(() => this.animate());
                
                // ✨ CRITICAL FIX: Prevent gimbal lock / camera flip
                
                // Get the camera's current rotation as Euler angles
                const euler = new THREE.Euler(0, 0, 0, 'YXZ');
                euler.setFromQuaternion(this.camera.quaternion);
                
                // Store original values for debugging
                const originalPitch = euler.x;
                const originalRoll = euler.z;
                
                // CRITICAL: Aggressively clamp pitch to prevent gimbal lock
                // The closer to ±90° we get, the more likely gimbal lock occurs
                const maxPitch = 1.4; // ~80 degrees (conservative to avoid gimbal lock entirely)
                const wasClampedPitch = euler.x < -maxPitch || euler.x > maxPitch;
                euler.x = Math.max(-maxPitch, Math.min(maxPitch, euler.x));
                
                // Force roll to ONLY our cinematic lean (remove any drift)
                const currentLean = this.currentLean || 0;
                const wasClampedRoll = Math.abs(euler.z - currentLean) > 0.01;
                euler.z = currentLean;
                
                // Debug logging when clamping occurs
                if (wasClampedPitch || wasClampedRoll) {
                    console.log('🔒 Clamping:', {
                        pitch: `${(originalPitch * 180 / Math.PI).toFixed(1)}° → ${(euler.x * 180 / Math.PI).toFixed(1)}°`,
                        roll: `${(originalRoll * 180 / Math.PI).toFixed(1)}° → ${(euler.z * 180 / Math.PI).toFixed(1)}°`
                    });
                }
                
                // Apply the corrected rotation back to camera
                this.camera.quaternion.setFromEuler(euler);
                
                // Mobile uses modified movement system
                if (this.isMobile) {
                    this.updateMovementMobile();
                } else {
                    this.updateMovement();
                }

                this.updateProximityLighting(); // NEW: Dynamic lighting
                this.checkArtworkFocus();
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
                this.initSprintButton();
                this.initSpeedDial();
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
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.look.touchId);
                    if (!touch) return;
                    
                    e.preventDefault();
                    
                    // Calculate delta
                    const deltaX = touch.clientX - this.mobileState.look.lastX;
                    const deltaY = touch.clientY - this.mobileState.look.lastY;
                    
                    this.mobileState.look.lastX = touch.clientX;
                    this.mobileState.look.lastY = touch.clientY;
                    
                    // Apply rotation directly to camera Euler angles
                    const euler = new THREE.Euler(0, 0, 0, 'YXZ');
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
                    
                    const touch = this.findTouch(e.changedTouches, this.mobileState.look.touchId);
                    if (!touch) return;
                    
                    this.mobileState.look.active = false;
                    this.mobileState.look.touchId = null;
                };
                
                zone.addEventListener('touchend', endLook, { passive: false });
                zone.addEventListener('touchcancel', endLook, { passive: false });
            }
            
            initSprintButton() {
                const btn = document.getElementById('sprint-btn');
                if (!btn) return;
                
                const startSprint = (e) => {
                    e.preventDefault();
                    this.mobileState.sprint = true;
                    this.moveState.sprint = true;
                    btn.classList.add('active');
                };
                
                const endSprint = (e) => {
                    e.preventDefault();
                    this.mobileState.sprint = false;
                    this.moveState.sprint = false;
                    btn.classList.remove('active');
                };
                
                btn.addEventListener('touchstart', startSprint, { passive: false });
                btn.addEventListener('touchend', endSprint, { passive: false });
                btn.addEventListener('touchcancel', endSprint, { passive: false });
            }
            
            initSpeedDial() {
                const buttons = document.querySelectorAll('.speed-btn');
                
                buttons.forEach(btn => {
                    btn.addEventListener('touchstart', (e) => {
                        e.preventDefault();
                        
                        // Update UI
                        buttons.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        
                        // Set speed
                        const speedIndex = parseInt(btn.dataset.speed);
                        this.setSpeedMultiplier(speedIndex);
                        
                    }, { passive: false });
                });
            }
            
            initDoubleTap() {
                const zone = document.getElementById('look-zone');
                if (!zone) return;
                
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
            
            handleDoubleTap(x, y) {
                console.log('👆 Double-tap detected at:', x, y);
                
                // Visual feedback
                const feedback = document.getElementById('tap-feedback');
                if (feedback) {
                    feedback.style.left = `${x}px`;
                    feedback.style.top = `${y}px`;
                    feedback.classList.remove('animate');
                    void feedback.offsetWidth; // Trigger reflow
                    feedback.classList.add('animate');
                }
                
                // Raycast from center of screen (not tap point - more reliable)
                // Or use tap point converted to normalized device coordinates
                const mouse = new THREE.Vector2(
                    (x / window.innerWidth) * 2 - 1,
                    -(y / window.innerHeight) * 2 + 1
                );
                
                this.raycaster.setFromCamera(mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.artworks, true);
                
                if (intersects.length > 0) {
                    const artwork = intersects[0].object.parent;
                    if (artwork.userData.type === 'artwork') {
                        console.log('🎯 Double-tap hit artwork:', artwork.userData.title);
                        this.focusedArtwork = artwork;
                        
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
                if (this.roomBounds) {
                    const margin = 0.5;
                    this.camera.position.x = Math.max(
                        this.roomBounds.minX + margin,
                        Math.min(this.roomBounds.maxX - margin, this.camera.position.x)
                    );
                    this.camera.position.z = Math.max(
                        this.roomBounds.minZ + margin,
                        Math.min(this.roomBounds.maxZ - margin, this.camera.position.z)
                    );
                }
                
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
    </script>
</body>
</html>