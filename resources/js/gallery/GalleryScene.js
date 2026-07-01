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
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

import { CONFIG } from './config.js';
import { initRenderer, detectLowEnd, applyLowEndSettings, earlyLowEndCheck } from './Renderer.js';
import { setupControls, setSpeedMultiplier } from './Controls.js';
import { loadAssets, loadEnvironmentMap } from './AssetLoader.js';
import { initAudio, playAudio } from './Audio.js';
import { applyVenueOverrides, applyVenueConfig, loadDecorations, addCustomLights, addVenueStructure } from './VenueDecorator.js';
import { buildGallery, createRoom, createRoomCorridor, createRoomLShape, createRoomRotunda, createRoomCircular, addVenueCeiling } from './RoomBuilder.js';
import { placeArtworks, makeArtworkGroup, placeAndRegister } from './ArtworkPlacer.js';
import { setupLighting, addArtworkLight, updateProximityLighting } from './Lighting.js';
import { getWallMaterial, getFloorMaterial, createFrame } from './Materials.js';
import { enforceRoomBounds, registerObstacle, clearObstacles } from './Collisions.js';
import { updateMovement, updateMovementMobile } from './Movement.js';
import { toggleArtworkInfo, checkArtworkFocus } from './FocusMode.js';
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
    checkArtworkFocus()                             { return checkArtworkFocus.call(this); }
    setupMobileControls()                           { return setupMobileControls.call(this); }
    loadEnvironmentMap()                            { return loadEnvironmentMap.call(this); }
    playAudio()                                     { return playAudio.call(this); }

    // ── Main animation loop ─────────────────────────────────────────────────
    animate() {
        requestAnimationFrame(() => this.animate());

        if (!this._isVisible) return;

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
}
