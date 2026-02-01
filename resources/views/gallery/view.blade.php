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
        
        /* Loading Screen */
        #loader {
            position: fixed; inset: 0; z-index: 100;
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            transition: opacity 0.8s ease;
        }
        .loader-logo { 
            font-size: 3rem; 
            font-weight: 800; 
            letter-spacing: 0.3em;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 2rem;
        }
        .loader-bar { 
            width: 300px; height: 4px; 
            background: #2d2d2d; 
            margin-top: 1rem; 
            border-radius: 2px; 
            overflow: hidden;
        }
        .loader-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            width: 0%; 
            transition: width 0.3s ease;
        }
        
        /* UI Overlay */
        #ui-layer { position: absolute; inset: 0; pointer-events: none; z-index: 10; }
        .ui-interactive { pointer-events: auto; }
        
        /* Info Panel */
        #info-panel {
            position: absolute;
            bottom: 100px;
            right: 30px;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            border-radius: 12px;
            width: 350px; /* Fixed width */
            word-wrap: break-word; /* Prevent overflow */
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.1);
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }
        #info-panel.show {
            opacity: 1;
            transform: translateY(0);
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
    </style>

    <script type="importmap">
    {
        "imports": {
            "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
            "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
        }
    }
    </script>
</head>
<body>

    <!-- Loading Screen -->
    <div id="loader">
        <div class="loader-logo">EXOSPACE</div>
        <div style="text-align: center;">
            <p class="text-gray-400 text-lg mb-2">{{ $gallery->title }}</p>
            <p class="text-gray-600 text-sm" id="loading-text">Initializing gallery...</p>
        </div>
        <div class="loader-bar">
            <div class="loader-fill" id="progress-bar"></div>
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

        <!-- Watermark: Free plan only -->
        @if (!$gallery->user->isPro())
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

        // ===================================
        // CONFIGURATION
        // ===================================
        const CONFIG = {
            camera: {
                fov: 75,
                near: 0.1,
                far: 100,
                height: 1.6
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
                this.raycaster = new THREE.Raycaster();
                this.mouse = new THREE.Vector2();
                this.focusedArtwork = null;
                
                this.init();
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
                    const artworkMat = new THREE.MeshStandardMaterial({ 
                        map: texture,
                        roughness: 0.5,
                        metalness: 0.1
                    });
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
                if (!this.controls.isLocked) return;

                const baseSpeed = CONFIG.movement.baseSpeed;
                const speedMultiplier = this.currentSpeedMultiplier || 1;
                const sprintMultiplier = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
                
                const speed = baseSpeed * speedMultiplier * sprintMultiplier;

                const direction = new THREE.Vector3();
                const right = new THREE.Vector3();

                this.camera.getWorldDirection(direction);
                right.crossVectors(this.camera.up, direction).normalize();

                direction.y = 0;
                direction.normalize();

                if (this.moveState.forward) this.camera.position.add(direction.multiplyScalar(speed));
                if (this.moveState.backward) this.camera.position.add(direction.multiplyScalar(-speed));
                if (this.moveState.left) this.camera.position.add(right.multiplyScalar(speed));
                if (this.moveState.right) this.camera.position.add(right.multiplyScalar(-speed));

                // Collision detection
                if (this.roomBounds) {
                    const margin = 0.5;
                    
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
                }

                this.camera.position.y = CONFIG.camera.height;
            }

            checkArtworkFocus() {
                if (!this.controls.isLocked) return;

                this.raycaster.setFromCamera(new THREE.Vector2(0, 0), this.camera);
                const intersects = this.raycaster.intersectObjects(this.artworks, true);

                if (intersects.length > 0) {
                    const artwork = intersects[0].object.parent;
                    if (artwork.userData.type === 'artwork' && artwork !== this.focusedArtwork) {
                        this.focusedArtwork = artwork;
                    }
                } else {
                    this.focusedArtwork = null;
                }
            }

            toggleArtworkInfo() {
                const panel = document.getElementById('info-panel');
                
                if (panel.classList.contains('show')) {
                    panel.classList.remove('show');
                } else if (this.focusedArtwork) {
                    const data = this.focusedArtwork.userData;
                    
                    let displayTitle = data.title || 'Untitled';
                    
                    if (displayTitle.includes('.')) {
                        displayTitle = displayTitle.split('.').slice(0, -1).join('.');
                        displayTitle = displayTitle.replace(/[_-]/g, ' ');
                    }
                    
                    document.getElementById('artwork-title').textContent = displayTitle;
                    document.getElementById('artwork-description').textContent = data.description || 'No description available.';
                    panel.classList.add('show');
                }
            }

            // SECTION 7: Update animate() method
            animate() {
                requestAnimationFrame(() => this.animate());
                
                this.updateMovement();
                this.updateProximityLighting(); // NEW: Dynamic lighting
                this.checkArtworkFocus();
                this.renderer.render(this.scene, this.camera);
            }

            updateProgress(percent, text) {
                document.getElementById('progress-bar').style.width = `${percent}%`;
                document.getElementById('loading-text').textContent = text;
            }

            hideLoader() {
                const loader = document.getElementById('loader');
                loader.style.opacity = '0';
                setTimeout(() => loader.remove(), 800);
            }
        }

        // Initialize
        new GalleryScene();
    </script>
</body>
</html>