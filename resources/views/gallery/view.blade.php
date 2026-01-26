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

        <!-- Controls Info -->
        <div class="absolute bottom-6 left-6">
            <div class="bg-black/70 backdrop-blur-md px-4 py-3 rounded-lg border border-white/10">
                <!-- FIX 4A: Updated UI with Sprint Info -->
                <div class="text-white/90 text-sm space-y-1 hidden md:block" id="desktop-controls">
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">WASD</span> Move</p>
                    <p><span class="font-mono bg-white/10 px-2 py-0.5 rounded">SHIFT</span> Sprint</p>
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
                description: "A demo 3D gallery running in standalone mode. Sprinting and optimized lighting enabled.",
                wall_texture: "white",
                floor_material: "wood",
                lighting_preset: "bright",
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

        // ===================================
        // CONFIGURATION
        // ===================================
        const CONFIG = {
            camera: {
                fov: 75,
                near: 0.1,
                far: 100,
                height: 1.6 // Eye level
            },
            // FIX 4B: Updated Movement Config (Faster base + Sprint)
            movement: {
                speed: 0.1,        // Increased base speed
                sprintMultiplier: 2.5  // Increased sprint multiplier
            },
            room: {
                wallHeight: 4,
                artworkSpacing: 3.5,
                minWallLength: 8,
                wallDepth: 0.3
            },
            // Enhanced Lighting Configuration
            lighting: {
                bright: { 
                    ambient: 0.7,      
                    spot: 1.2,
                    ceiling: 0xffffff, 
                    fillLight: 0.5     
                },
                moody: { 
                    ambient: 0.4,      
                    spot: 0.8,
                    ceiling: 0xe8e8e8, 
                    fillLight: 0.3
                },
                dramatic: { 
                    ambient: 0.25,     
                    spot: 1.5,
                    ceiling: 0x2a2a2a, 
                    fillLight: 0.15
                }
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
                this.scene.fog = new THREE.Fog(0x0a0a0a, 0, 50);

                // Camera
                this.camera = new THREE.PerspectiveCamera(
                    CONFIG.camera.fov,
                    window.innerWidth / window.innerHeight,
                    CONFIG.camera.near,
                    CONFIG.camera.far
                );
                
                // ============================================
                // FIX 1A: FIX STARTING POSITION
                // ============================================
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
                this.container.appendChild(this.renderer.domElement);

                // Controls
                this.setupControls();
                
                // Load assets then build
                this.loadAssets();
            }

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

                // Keyboard events
                document.addEventListener('keydown', (e) => {
                    switch(e.code) {
                        case 'KeyW': this.moveState.forward = true; break;
                        case 'KeyS': this.moveState.backward = true; break;
                        case 'KeyA': this.moveState.left = true; break;
                        case 'KeyD': this.moveState.right = true; break;
                        case 'ShiftLeft': this.moveState.sprint = true; break;
                        case 'KeyE': this.toggleArtworkInfo(); break;
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

            async loadAssets() {
                const textureLoader = new THREE.TextureLoader();
                const data = window.GALLERY_DATA;
                
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
            // FIX 3: Smart Room Sizing Implementation
            // ============================================
            createRoom(data) {
                const imageCount = data.imageCount;
                
                // ============================================
                // SMART ROOM SIZING (NO EMPTY SPACES)
                // ============================================
                const spacing = CONFIG.room.artworkSpacing;
                const minWallLength = CONFIG.room.minWallLength;
                
                // Calculate how many images per wall we need
                const imagesPerWall = Math.ceil(imageCount / 4);
                
                // Calculate minimum wall length to fit those images
                // Formula: (imagesPerWall * spacing) + spacing (for padding on ends)
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
                const roomLightingConfig = CONFIG.lighting[data.lighting_preset] || CONFIG.lighting.bright;
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

                // ============================================
                // FIX 1C: STORE ROOM BOUNDARIES FOR COLLISION
                // ============================================
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

            getFloorMaterial(type) {
                const fallbackColors = {
                    wood: 0x5c4033,
                    marble: 0xe8e8e8,
                    concrete: 0x6b6b6b
                };

                if (!this.textures.floor) {
                    return new THREE.MeshStandardMaterial({ 
                        color: fallbackColors[type] || fallbackColors.wood,
                        roughness: 0.6,
                        metalness: 0.1
                    });
                }

                const texture = this.textures.floor.clone();
                texture.needsUpdate = true;
                texture.wrapS = THREE.RepeatWrapping;
                texture.wrapT = THREE.RepeatWrapping;

                const properties = {
                    wood: { roughness: 0.6, metalness: 0.1 },
                    marble: { roughness: 0.2, metalness: 0.4 }, 
                    concrete: { roughness: 0.8, metalness: 0.0 }
                };

                const props = properties[type] || properties.wood;

                return new THREE.MeshStandardMaterial({ 
                    map: texture,
                    roughness: props.roughness,
                    metalness: props.metalness
                });
            }

            setupLighting(preset) {
                this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                const config = this.lightingConfig;

                const ambient = new THREE.AmbientLight(0xffffff, config.ambient);
                this.scene.add(ambient);

                const hemi = new THREE.HemisphereLight(
                    0xffffff,  
                    0x888888,  
                    0.4
                );
                hemi.position.set(0, CONFIG.room.wallHeight, 0);
                this.scene.add(hemi);
                
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

                // Use calculated wallLength from this instance if needed, or recalculate logic here
                // To be safe, we recalculate based on data to match createRoom logic exactly
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
                    
                    // Add shared artwork light (1 per 3 artworks for performance)
                    if (index % 3 === 0) {
                        this.addArtworkLight(group, data.lighting_preset);
                    }
                    
                    positionOnWall++;
                    if (positionOnWall >= imagesPerWall) {
                        positionOnWall = 0;
                        wallIndex++;
                    }
                });
                
                console.log(`🖼️ Placed ${this.artworkImages.length} artworks using optimized lighting`);
            }

            createFrame(width, height, style) {
                const frameDepth = 0.08;
                const frameWidth = 0.1;
                
                const colors = {
                    modern: 0x2c2c2c,
                    classic: 0x8b7355,
                    minimal: 0xffffff
                };

                const frameMat = new THREE.MeshStandardMaterial({
                    color: colors[style] || colors.modern,
                    roughness: 0.4,
                    metalness: 0.6
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

            // ============================================
            // FIX 1: Optimized Lighting Method
            // ============================================
            addArtworkLight(artworkGroup, preset) {
                const config = CONFIG.lighting[preset] || CONFIG.lighting.bright;
                
                // ============================================
                // PERFORMANCE: Use simple PointLight instead of SpotLight
                // ============================================
                const artworkLight = new THREE.PointLight(
                    0xfff5e6, // Warm white
                    config.spot * 0.7, // Slightly reduced intensity
                    4 // Range: 4 meters (enough to light the artwork)
                );
                
                // Position light in front of artwork
                const normal = new THREE.Vector3(0, 0, 1);
                normal.applyQuaternion(artworkGroup.quaternion);
                
                artworkLight.position.copy(artworkGroup.position);
                artworkLight.position.y += 0.5; // Slightly above artwork center
                artworkLight.position.add(normal.multiplyScalar(0.8)); // In front
                
                artworkLight.castShadow = false; // Critical for performance
                
                this.scene.add(artworkLight);
            }

            // ============================================
            // FIX 1B: ADD BOUNDARY COLLISION
            // ============================================
            updateMovement() {
                if (!this.controls.isLocked) return;

                const speed = this.moveState.sprint 
                    ? CONFIG.movement.speed * CONFIG.movement.sprintMultiplier 
                    : CONFIG.movement.speed;

                const direction = new THREE.Vector3();
                const right = new THREE.Vector3();

                this.camera.getWorldDirection(direction);
                right.crossVectors(this.camera.up, direction).normalize();

                direction.y = 0;
                direction.normalize();

                // Store old position for collision detection
                const oldPosition = this.camera.position.clone();

                if (this.moveState.forward) this.camera.position.add(direction.multiplyScalar(speed));
                if (this.moveState.backward) this.camera.position.add(direction.multiplyScalar(-speed));
                if (this.moveState.left) this.camera.position.add(right.multiplyScalar(speed));
                if (this.moveState.right) this.camera.position.add(right.multiplyScalar(-speed));

                // ============================================
                // COLLISION DETECTION: Keep player inside room
                // ============================================
                if (this.roomBounds) {
                    const margin = 0.5; // 0.5m away from walls
                    
                    // Clamp X position (left/right walls)
                    if (this.camera.position.x < this.roomBounds.minX + margin) {
                        this.camera.position.x = this.roomBounds.minX + margin;
                    }
                    if (this.camera.position.x > this.roomBounds.maxX - margin) {
                        this.camera.position.x = this.roomBounds.maxX - margin;
                    }
                    
                    // Clamp Z position (front/back walls)
                    if (this.camera.position.z < this.roomBounds.minZ + margin) {
                        this.camera.position.z = this.roomBounds.minZ + margin;
                    }
                    if (this.camera.position.z > this.roomBounds.maxZ - margin) {
                        this.camera.position.z = this.roomBounds.maxZ - margin;
                    }
                }

                // Keep camera at eye level
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

            animate() {
                requestAnimationFrame(() => this.animate());
                
                this.updateMovement();
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