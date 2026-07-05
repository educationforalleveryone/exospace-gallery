// ─────────────────────────────────────────────────────────────────────────────
// GalleryScene — top-level orchestrator
//
// This is the slimmed-down version of the old 4,560-line GalleryScene class.
// It owns the THREE.Scene, camera, renderer, and delegates to:
//   - Renderer.js        for renderer init + low-end detection
//   - Controls.js        for keyboard / pointer-lock / mobile touch
//   - AssetLoader.js     for textures, HDRIs, GLBs, audio
//   - VenueDecorator.js  for venue-specific overrides (fog, decorations, lights)
//   - RoomBuilder.js     for the room shell (walls, floor, ceiling)
//   - ArtworkPlacer.js   for placing artworks on walls/easels
//   - Lighting.js        for ambient + proximity lighting
//   - Materials.js       for wall / floor / frame materials
//   - Collisions.js      for player collision against bounds + obstacles
//   - Movement.js        for WASD physics + cinematic lean
//   - FocusMode.js       for click-to-focus camera tween
//   - Tour.js            for GuidedTour
//   - PostProcessing.js  for bloom / SSAO / vignette
//   - Audio.js           for ambient music + SFX
//   - Mobile.js          for touch joystick + look pad
//   - Analytics.js       for view / focus / dwell tracking
//
// The class itself only holds state and dispatches.
//
// NEW (Live Preview): applyLiveOverride(patch) — accepts a partial config
// patch and applies it to the running scene without rebuilding the room.
// Used by the admin preview iframe to reflect slider tweaks in real time.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

import { CONFIG } from './config.js';
import { initRenderer, detectLowEnd, applyLowEndSettings, earlyLowEndCheck } from './Renderer.js';
import { setupControls, setSpeedMultiplier } from './Controls.js';
import { loadAssets, loadEnvironmentMap } from './AssetLoader.js';
import { initAudio, playAudio, toggleMute } from './Audio.js';
import { applyVenueOverrides, applyVenueConfig, applyVisualPatch, loadDecorations, addCustomLights, addVenueStructure } from './VenueDecorator.js';
import { buildGallery, createRoom, createRoomCorridor, createRoomLShape, createRoomRotunda, createRoomCircular, addVenueCeiling } from './RoomBuilder.js';
import { placeArtworks, makeArtworkGroup, placeAndRegister } from './ArtworkPlacer.js';
import { setupLighting, addArtworkLight, updateProximityLighting } from './Lighting.js';
import { getWallMaterial, getFloorMaterial, createFrame } from './Materials.js';
import { enforceRoomBounds, registerObstacle, clearObstacles } from './Collisions.js';
import { updateMovement, updateMovementMobile } from './Movement.js';
import { toggleArtworkInfo, checkArtworkFocus, focusNearestArtwork } from './FocusMode.js';
import { setupMobileControls } from './Mobile.js';
import { PostProcessing } from './PostProcessing.js';
import { PerformanceControls } from './PerformanceControls.js';

export class GalleryScene {
    constructor() {
        this.container = document.getElementById('canvas-container');
        if (!this.container) {
            console.error('GalleryScene: #canvas-container not found in DOM');
            return;
        }

        // ── State ──────────────────────────────────────────────────────────
        this.loadingProgress = 0;
        this.textures        = {};
        this.artworks        = [];
        this.artworkImages   = [];

        // Focus mode state
        this.isInspecting       = false;
        this.originalCameraPos  = new THREE.Vector3();
        this.originalCameraQuat = new THREE.Quaternion();
        this.focusTween         = null;
        this.raycaster          = new THREE.Raycaster();
        this.mouse              = new THREE.Vector2();
        this.focusedArtwork     = null;

        // Physics-based movement
        this.velocity       = new THREE.Vector3();
        this.direction      = new THREE.Vector3();
        this.lookDirection  = new THREE.Vector3();
        this.clock          = new THREE.Clock();
        this.currentLean    = 0;

        // Pre-allocated reusable objects (avoid GC pressure per frame)
        this._reusableEuler  = new THREE.Euler(0, 0, 0, 'YXZ');
        this._reusableVector = new THREE.Vector2(0, 0);
        this._lightingFrameCount = 0;

        // Low-end flags (populated by detectLowEnd)
        this.isLowEnd         = false;
        this._skipHdri        = false;
        this._maxAnisotropy   = undefined;
        this._lowEndFrameSkip = false;

        // Layout state
        this._lShapeBounds  = null;
        this._rotundaRadius = null;
        this.roomBounds     = null;
        this._layoutMeta    = null;
        this._obstacles     = []; // registered collision boxes (walls, dividers, props)

        // Venue state (set by VenueDecorator)
        this._venueSlug           = 'white-cube';
        this._venueAmbientColor   = null;
        this._venueAmbientIntensity = null;
        this._venueSpotIntensity  = null;
        this._venueFillIntensity  = null;
        this._venueFrameOverride  = null;
        this._venueCeilingType    = null;
        this._venueMaterialConfig = null;
        this._customHdriUrl       = null;

        // SFX state
        this.sfx           = {};
        this.footstepTimer = 0;
        this.lastStepTime  = 0;
        this.sfxEnabled    = true;
        this.isSprinting   = false;
        this.speedMultiplier = 1;

        // Visibility (pause render loop when tab hidden)
        this._isVisible = true;

        // Mobile flag (set by setupMobileControls)
        this.isMobile = false;

        // ── Boot ───────────────────────────────────────────────────────────
        this.init();
        initAudio.call(this);
    }

    init() {
        // Scene
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(0x0a0a0a);

        const lowEndEarly = earlyLowEndCheck();
        this.scene.fog = new THREE.Fog(0x0a0a0a, lowEndEarly ? 5 : 10, lowEndEarly ? 14 : 30);

        // Camera
        this.camera = new THREE.PerspectiveCamera(
            CONFIG.camera.fov,
            window.innerWidth / window.innerHeight,
            CONFIG.camera.near,
            lowEndEarly ? 20 : CONFIG.camera.far
        );
        this.camera.position.set(0, CONFIG.camera.height, 0);

        // Renderer + low-end detection
        initRenderer.call(this);
        detectLowEnd.call(this);

        // Visibility tracking (saves battery when tab hidden)
        document.addEventListener('visibilitychange', () => {
            this._isVisible = !document.hidden;
        });

        // Performance controls (FPS counter + quality toggle)
        // Initialized after detectLowEnd so it knows the auto-detected tier
        this._perfControls = new PerformanceControls(this);

        // Controls (keyboard + pointer lock + mobile)
        setupControls.call(this);

        // Start silent preload
        loadAssets.call(this);
    }

    // ── Delegated methods (kept thin so the orchestrator is readable) ──────

    setSpeedMultiplier(index)                       { return setSpeedMultiplier.call(this, index); }
    applyVenueOverrides(slug)                       { return applyVenueOverrides.call(this, slug); }
    applyVenueConfig(cfg)                           { return applyVenueConfig.call(this, cfg); }
    applyVisualPatch(patch)                         { return applyVisualPatch.call(this, patch); }
    loadDecorations(decorations)                    { return loadDecorations.call(this, decorations); }
    addCustomLights(fixtures)                       { return addCustomLights.call(this, fixtures); }
    addVenueStructure(data)                         { return addVenueStructure.call(this, data); }
    buildGallery()                                  { return buildGallery.call(this); }
    createRoom(data)                                { return createRoom.call(this, data); }
    createRoomCorridor(data)                        { return createRoomCorridor.call(this, data); }
    createRoomLShape(data)                          { return createRoomLShape.call(this, data); }
    createRoomRotunda(data)                         { return createRoomRotunda.call(this, data); }
    createRoomCircular(data)                        { return createRoomCircular.call(this, data); }
    addVenueCeiling(roomWidth, roomDepth, wallH)    { return addVenueCeiling.call(this, roomWidth, roomDepth, wallH); }
    placeArtworks(data)                             { return placeArtworks.call(this, data); }
    makeArtworkGroup(img, data)                     { return makeArtworkGroup.call(this, img, data); }
    placeAndRegister(group, data)                   { return placeAndRegister.call(this, group, data); }
    setupLighting(preset)                           { return setupLighting.call(this, preset); }
    addArtworkLight(group, preset)                  { return addArtworkLight.call(this, group, preset); }
    updateProximityLighting()                       { return updateProximityLighting.call(this); }
    getWallMaterial(type)                           { return getWallMaterial.call(this, type); }
    getFloorMaterial(type)                          { return getFloorMaterial.call(this, type); }
    createFrame(width, height, style)               { return createFrame.call(this, width, height, style); }
    enforceRoomBounds()                             { return enforceRoomBounds.call(this); }
    registerObstacle(mesh)                          { return registerObstacle.call(this, mesh); }
    clearObstacles()                                { return clearObstacles.call(this); }
    updateMovement()                                { return updateMovement.call(this); }
    updateMovementMobile()                          { return updateMovementMobile.call(this); }
    toggleArtworkInfo()                             { return toggleArtworkInfo.call(this); }
    focusNearestArtwork()                           { return focusNearestArtwork.call(this); }
    checkArtworkFocus()                             { return checkArtworkFocus.call(this); }
    setupMobileControls()                           { return setupMobileControls.call(this); }
    loadEnvironmentMap()                            { return loadEnvironmentMap.call(this); }
    playAudio()                                     { return playAudio.call(this); }
    toggleMute()                                    { return toggleMute.call(this); }

    // S-6: Dispose all GPU resources to prevent memory leaks.
    // Traverses the scene and disposes every geometry, material, and texture.
    // Called on pagehide/beforeunload and before scene rebuilds.
    dispose() {
        if (! this.scene) return;

        this.scene.traverse((obj) => {
            // Dispose geometry
            if (obj.geometry) {
                obj.geometry.dispose();
            }

            // Dispose material(s) — may be an array or single
            const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
            materials.forEach((mat) => {
                if (! mat) return;

                // Dispose all texture maps
                const textureKeys = ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap', 'emissiveMap', 'bumpMap', 'alphaMap'];
                textureKeys.forEach((key) => {
                    if (mat[key] && typeof mat[key].dispose === 'function') {
                        mat[key].dispose();
                    }
                });

                // Dispose the material itself
                if (typeof mat.dispose === 'function') {
                    mat.dispose();
                }
            });
        });

        // Dispose the renderer
        if (this.renderer) {
            this.renderer.dispose();
        }

        // Dispose controls
        if (this.controls && typeof this.controls.dispose === 'function') {
            this.controls.dispose();
        }

        // Dispose PostProcessing composer
        if (this.composer) {
            this.composer.renderTargets?.forEach(rt => rt?.dispose?.());
        }

        // Clear the scene
        this.scene.clear();
        this.scene = null;

        console.log('GalleryScene: disposed all GPU resources');
    }

    // ── Main animation loop ─────────────────────────────────────────────────
    animate() {
        requestAnimationFrame(() => this.animate());

        // S-7: Skip rendering when context is lost
        if (this._contextLost || !this._isVisible) return;

        // Cap to ~30fps on low-end (skip every other frame)
        if (this.isLowEnd) {
            this._lowEndFrameSkip = !this._lowEndFrameSkip;
            if (this._lowEndFrameSkip) return;
        }

        this._lightingFrameCount++;

        // Reuse pre-allocated Euler — clamp pitch to prevent gimbal lock
        const euler = this._reusableEuler;
        euler.setFromQuaternion(this.camera.quaternion);
        const maxPitch = 1.4; // ~80 degrees
        euler.x = Math.max(-maxPitch, Math.min(maxPitch, euler.x));
        euler.z = this.currentLean || 0; // lock roll to our cinematic lean only
        this.camera.quaternion.setFromEuler(euler);

        // Mobile vs desktop movement
        if (this.isMobile) this.updateMovementMobile();
        else                this.updateMovement();

        // Throttle expensive per-frame work
        const lightThrottle = this.isLowEnd ? 4 : 2;
        const focusThrottle = this.isLowEnd ? 6 : 3;
        if (this._lightingFrameCount % lightThrottle === 0) this.updateProximityLighting();
        if (this._lightingFrameCount % focusThrottle === 0) this.checkArtworkFocus();

        // Render — with or without post-processing
        if (this._postFx && !this.isLowEnd) {
            this._postFx.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }
    }

    // ── Progress bar (called by AssetLoader) ────────────────────────────────
    updateProgress(percent, text) {
        this.loadingProgress = percent;

        const bar        = document.getElementById('curtain-progress-bar');
        const percentTxt = document.getElementById('curtain-progress-percent');
        const statusTxt  = document.getElementById('curtain-progress-text');
        if (bar)        bar.style.width = `${percent}%`;
        if (percentTxt) percentTxt.textContent = `${Math.round(percent)}%`;
        if (statusTxt)  statusTxt.textContent  = text;

        if (percent >= 100) {
            const enterBtn = document.getElementById('enter-btn');
            if (enterBtn) {
                enterBtn.style.opacity      = '1';
                enterBtn.style.pointerEvents = 'auto';
                enterBtn.style.transition   = 'all 0.3s ease';
                enterBtn.style.animation    = 'pulse 2s ease-in-out infinite';
            }
            if (statusTxt) statusTxt.textContent = 'Ready to enter';
            if (window.EXOSPACE_EMBED_MODE) {
                setTimeout(() => enterBtn?.click(), 400);
            }
        }
    }

    hideLoader() {
        // No separate loader — the curtain is the loader
        console.log('✅ Loading complete — gallery ready');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Live Preview — apply a partial config patch to the running scene
    // ─────────────────────────────────────────────────────────────────────────
    //
    //  Used by the admin preview iframe (resources/views/admin/galleries/
    //  preview.blade.php). The parent window sends a postMessage with a
    //  patch shape like:
    //
    //      {
    //        visual_config:   { ambient_intensity: 0.32, fog_color: '0x1a1a1a' },
    //        material_config: { wall_roughness: 0.4 },
    //        post_fx:         { bloom_strength: 0.8 }
    //      }
    //
    //  Each key is OPTIONAL — only the keys present in the patch are applied;
    //  others stay at their current value. A null value reverts to the venue
    //  default (read from window.GALLERY_DATA.venueConfig).
    //
    //  IMPORTANT: structural changes (wall_height, room_layout) are NOT
    //  supported here — they require a full room rebuild. The parent window
    //  handles those by reloading the iframe with a `?override=` query param
    //  so the scene is built fresh with the new structural config.
    //
    //  The patch is also forwarded to applyVisualPatch() in VenueDecorator
    //  so venue-specific state (ambient color, spot intensity, etc.) stays
    //  in sync with what the Lighting.js module reads next frame.
    applyLiveOverride(patch) {
        if (!patch || typeof patch !== 'object') return;

        // ── Auto-tag floors + fill lights on first call ──────────────────
        // The Live Preview's material/fill-intensity patchers need to find
        // these meshes cheaply. Rather than modify RoomBuilder.js (which
        // would mean delivering a 450-line file just for 5 one-line tags),
        // we scan the scene once and tag everything that looks like a floor
        // (horizontal plane/circle at y≈0) or a fill light (warm-coloured
        // PointLight placed high up). Subsequent calls skip the scan.
        if (!this._lpTagged) {
            this.scene.traverse(obj => {
                if (obj.isMesh && obj.geometry) {
                    // Floor: rotation.x ≈ -PI/2, position.y ≈ 0
                    const rotX = obj.rotation.x;
                    const isHorizontal = Math.abs(rotX + Math.PI / 2) < 0.01 || Math.abs(rotX - Math.PI / 2) < 0.01;
                    if (isHorizontal && Math.abs(obj.position.y) < 0.1) {
                        obj.userData._lpIsFloor = true;
                    }
                }
                if (obj.isPointLight && obj.position.y > 1) {
                    // Fill lights created by RoomBuilder use 0xfff8e8 (warm)
                    // or 0xffffff (white) and sit near the ceiling.
                    const c = obj.color;
                    if (c && ((c.r === 1 && c.g > 0.95 && c.b > 0.85) || (c.r === 1 && c.g === 1 && c.b === 1))) {
                        obj.userData._lpFillLight = true;
                        // Store the original multiplier so fill_intensity
                        // patches can rescale correctly.
                        obj.userData._lpFillMult = obj.intensity / Math.max(0.001, (this.lightingConfig?.fillLight ?? 0.12));
                    }
                }
            });
            this._lpTagged = true;
        }

        const v = patch.visual_config   || {};
        const m = patch.material_config || {};
        const p = patch.post_fx         || {};

        // ── Visual config (atmosphere — all live) ────────────────────────
        if (Object.keys(v).length > 0) {
            // Forward to VenueDecorator so internal state (_venueAmbientIntensity,
            // _venueSpotIntensity, etc.) stays in sync.
            this.applyVisualPatch(v);

            // Background color
            if ('background_color' in v) {
                if (v.background_color === null) {
                    this.scene.background = new THREE.Color(0x000000);
                } else {
                    const c = _parseColor(v.background_color);
                    if (c) this.scene.background = c;
                }
            }

            // Fog — any of color/near/far can be patched; missing keys fall
            // back to the current fog's values.
            if ('fog_color' in v || 'fog_near' in v || 'fog_far' in v) {
                const venueCfg = window.GALLERY_DATA?.venueConfig?.visual_config || {};
                const fogColor = ('fog_color' in v ? v.fog_color : (this.scene.fog?.color ? '#' + this.scene.fog.color.getHexString() : venueCfg.fog_color)) ?? null;
                const fogNear  = ('fog_near'  in v ? v.fog_near  : (this.scene.fog?.near ?? venueCfg.fog_near  ?? 10));
                const fogFar   = ('fog_far'   in v ? v.fog_far   : (this.scene.fog?.far  ?? venueCfg.fog_far   ?? 30));

                if (fogColor === null) {
                    this.scene.fog = null;
                } else {
                    const c = _parseColor(fogColor);
                    this.scene.fog = new THREE.Fog(c ?? new THREE.Color(0x0a0a0a), fogNear, fogFar);
                }
            }

            // Ambient intensity — update the existing AmbientLight objects.
            // The first one is the white key ambient; the second (if present)
            // is the venue-tinted ambient. We scale both by the same factor
            // so the curator's intent ("brighter overall ambient") holds
            // regardless of venue tinting.
            if ('ambient_intensity' in v) {
                const newI = v.ambient_intensity;
                const baseAmbientBoost = this.isLowEnd ? 3.5 : 1;
                this.scene.children.forEach(c => {
                    if (c.isAmbientLight && !c.userData._lpTinted) {
                        c.intensity = newI * baseAmbientBoost;
                    }
                });
            }

            // Spot intensity — update the per-artwork lightMax so the next
            // proximity-lighting frame boosts them by the new factor.
            if ('spot_intensity' in v) {
                const newMax = (v.spot_intensity ?? 0) * 3.5;
                this.artworks.forEach(a => {
                    if (a.userData.lightMax !== undefined) {
                        const base = a.userData.lightBase;
                        a.userData.lightMax = newMax;
                        // If currently boosted, snap to the new max so the
                        // change is visible immediately rather than waiting
                        // for the next proximity update.
                        if (a.userData.lightCurrent > base) {
                            a.userData.lightCurrent = newMax;
                            if (a.userData.light) a.userData.light.intensity = newMax;
                        }
                    }
                });
            }

            // Fill intensity — update the ceiling PointLights (created by
            // RoomBuilder as fill-light grid). We tag them at creation time
            // so we can find them cheaply here.
            if ('fill_intensity' in v) {
                this.scene.children.forEach(c => {
                    if (c.isPointLight && c.userData._lpFillLight) {
                        c.intensity = (v.fill_intensity ?? 0) * (c.userData._lpFillMult || 2.5);
                    }
                });
            }

            // Tone mapping exposure — renderer-level, instant.
            if ('tone_mapping_exposure' in v) {
                this.renderer.toneMappingExposure = v.tone_mapping_exposure ?? 0.5;
            }
        }

        // ── Material config (PBR — live on existing meshes) ──────────────
        if (Object.keys(m).length > 0) {
            this.scene.traverse(obj => {
                if (!obj.isMesh || !obj.material) return;
                const isWall   = obj.name && obj.name.startsWith('wall_');
                const isFloor  = obj.userData._lpIsFloor === true;
                if (!isWall && !isFloor) return;

                const mat = obj.material;
                if (isWall) {
                    if ('wall_roughness'  in m && mat.roughness !== undefined) mat.roughness  = m.wall_roughness;
                    if ('wall_metalness'  in m && mat.metalness !== undefined) mat.metalness  = m.wall_metalness;
                    if ('wall_color'      in m) {
                        const c = _parseColor(m.wall_color);
                        if (c) mat.color = c;
                    }
                    if ('wall_normal_strength' in m && mat.normalScale) {
                        mat.normalScale.set(m.wall_normal_strength, m.wall_normal_strength);
                    }
                } else if (isFloor) {
                    if ('floor_roughness' in m && mat.roughness !== undefined) mat.roughness = m.floor_roughness;
                    if ('floor_metalness' in m && mat.metalness !== undefined) mat.metalness = m.floor_metalness;
                    if ('floor_color'     in m) {
                        const c = _parseColor(m.floor_color);
                        if (c) mat.color = c;
                    }
                }
                mat.needsUpdate = true;
            });
        }

        // ── Post-FX (bloom / vignette — live) ────────────────────────────
        if (Object.keys(p).length > 0 && this._postFx) {
            if (typeof this._postFx.applyPatch === 'function') {
                this._postFx.applyPatch(p);
            } else {
                // Fallback: directly poke the bloom + vignette passes if present.
                if (p.bloom_strength !== undefined && this._postFx.bloomPass) {
                    this._postFx.bloomPass.strength = p.bloom_strength;
                }
                if (p.bloom_threshold !== undefined && this._postFx.bloomPass) {
                    this._postFx.bloomPass.threshold = p.bloom_threshold;
                }
                if (p.vignette_darkness !== undefined && this._postFx.vignettePass) {
                    this._postFx.vignettePass.darkness = p.vignette_darkness;
                }
                if (p.vignette_offset !== undefined && this._postFx.vignettePass) {
                    this._postFx.vignettePass.offset = p.vignette_offset;
                }
            }
        }
    }
}

// ── Local color parser (mirrors config.parseColor but doesn't need the import)
// ─────────────────────────────────────────────────────────────────────────────
function _parseColor(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return new THREE.Color(value);
    if (typeof value === 'string') {
        if (value.startsWith('0x') || value.startsWith('0X')) {
            return new THREE.Color(parseInt(value, 16));
        }
        try { return new THREE.Color(value); } catch { return null; }
    }
    return null;
}
