// ─────────────────────────────────────────────────────────────────────────────
// FocusMode — click an artwork to tween the camera up close + show info panel
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import gsap from 'gsap';
import { CONFIG } from './config.js';
import { Analytics } from './Analytics.js';
import { upgradeFocusedArtworkTexture } from './AssetLoader.js';

// Module-level reusable temporaries (one gallery per page — safe as module
// state; avoids per-call Vector3 allocations).
const _fwd = new THREE.Vector3();
const _to  = new THREE.Vector3();

// PERF-B11 (3D audit F11): two-stage focus detection.
//
// Stage 1 — an allocation-free direction/distance prefilter keeps only the
// artwork best aligned with the camera forward vector inside a 40° cone and
// 14 m range. The old implementation raycast recursively against EVERY
// artwork group (5 meshes each) on every call — every 3rd frame — which is
// O(5N) ray-mesh tests plus per-call result arrays.
//
// Stage 2 — a single raycast against ONLY the winning candidate preserves
// the precise crosshair-on-canvas + occlusion behaviour of the old system
// on capable devices. Low-end devices accept the cone winner directly.
//
// ALSO FIXED: this used to early-return unless controls.isLocked — which is
// never true on mobile (PointerLockControls is disabled there), so
// double-tap-to-focus silently never worked. Focus detection now also runs
// in mobile mode.
export function checkArtworkFocus() {
    if (!this.controls.isLocked && !this.isMobile) return;
    if (!this.artworks || this.artworks.length === 0) return;

    this.camera.getWorldDirection(_fwd);

    const maxDist = 14;
    let best = null;
    let bestDot = Math.cos(THREE.MathUtils.degToRad(40)); // ≈ 0.766

    for (let i = 0; i < this.artworks.length; i++) {
        const art = this.artworks[i];
        _to.copy(art.position).sub(this.camera.position);
        const dist = _to.length();
        if (dist > maxDist || dist < 0.4) continue;
        _to.divideScalar(dist);
        const dot = _to.dot(_fwd);
        if (dot > bestDot) {
            bestDot = dot;
            best = art;
        }
    }

    let focused = null;
    if (best) {
        if (this.isLowEnd) {
            focused = best;
        } else {
            this._reusableVector.set(0, 0);
            this.raycaster.setFromCamera(this._reusableVector, this.camera);
            const hits = this.raycaster.intersectObjects([best], true);
            focused = hits.length > 0 ? best : null;
        }
    }

    const crosshair = document.getElementById('crosshair');
    const prev = this.focusedArtwork;

    if (focused && focused !== prev) {
        this.focusedArtwork = focused;
        _setFrameHighlight.call(this, prev, focused);
        if (crosshair && !this.isInspecting) crosshair.classList.add('focused');
    } else if (!focused && prev) {
        this.focusedArtwork = null;
        _setFrameHighlight.call(this, prev, null);
        if (crosshair && !this.isInspecting) crosshair.classList.remove('focused');
    }
}

// ── Frame highlight (PERF-D23 / 3D audit F23) ─────────────────────────────
// Premium interaction feedback: the artwork under the crosshair takes on a
// soft emissive glow in its own frame colour (gold frames glow gold, modern
// black frames glow faintly). The frame materials already carry an emissive
// channel locked at intensity 0 (Materials.createFrame), so this costs one
// uniform write per transition — zero per-frame cost while idle, and none of
// the shader-recompile risk that a material-swap approach would carry.
// Reduced-motion users get an instant set instead of a tween.
function _setFrameHighlight(fromArt, toArt) {
    if (this.isLowEnd) return; // Lambert path stays cheap

    const apply = (art, intensity) => {
        const mat = art?.userData?._frameMesh?.material;
        if (!mat || mat.emissiveIntensity === undefined) return;
        if (intensity > 0) this._highlightMat = mat;
        if (this.reducedMotion) {
            mat.emissiveIntensity = intensity;
        } else {
            gsap.to(mat, { emissiveIntensity: intensity, duration: 0.35, ease: 'power2.out', overwrite: true });
        }
    };

    if (fromArt && fromArt !== toArt) apply(fromArt, 0);
    if (toArt) apply(toArt, 0.4);
}

// Toggle: if focused, tween camera to artwork. If inspecting, tween back.
export function toggleArtworkInfo() {
    const panel           = document.getElementById('info-panel');
    const crosshair       = document.getElementById('crosshair');
    const focusIndicator  = document.getElementById('focus-indicator');

    // ── EXIT FOCUS MODE ─────────────────────────────────────────────────────
    if (this.isInspecting) {
        if (this.focusTween) { this.focusTween.kill(); this.focusTween = null; }
        panel?.classList.remove('show');

        if (this.sfxEnabled && this.sfx.click && !this.sfx.click.isPlaying) {
            this.sfx.click.play();
        }

        if (focusIndicator) focusIndicator.style.opacity = '0';
        if (crosshair)      crosshair.classList.remove('focused');

        gsap.to(this.camera.position, {
            x: this.originalCameraPos.x,
            y: this.originalCameraPos.y,
            z: this.originalCameraPos.z,
            duration: 1.2,
            ease: 'power2.inOut',
            onUpdate: () => {
                this.camera.quaternion.slerp(this.originalCameraQuat, 0.1);
            },
            onComplete: () => {
                this.isInspecting = false;
                this.velocity.set(0, 0, 0);
                this.currentLean = 0;
                this.camera.rotation.z = 0;
                if (!this.isMobile && !this.controls.isLocked) {
                    this.controls.lock();
                }
            }
        });
        return;
    }

    // ── ENTER FOCUS MODE ────────────────────────────────────────────────────
    if (!this.focusedArtwork) return;

    this.originalCameraPos.copy(this.camera.position);
    this.originalCameraQuat.copy(this.camera.quaternion);
    this.isInspecting = true;

    Analytics.trackFocus(this.focusedArtwork.userData.id);

    if (this.sfxEnabled && this.sfx.click && !this.sfx.click.isPlaying) {
        this.sfx.click.play();
    }

    this.velocity.set(0, 0, 0);
    this.currentLean = 0;
    this.camera.rotation.z = 0;

    if (this.controls.isLocked) this.controls.unlock();

    if (focusIndicator) focusIndicator.style.opacity = '1';
    if (crosshair)      crosshair.classList.add('focused');

    const artwork = this.focusedArtwork;

    // PERF-E27 (3D audit): on the mobile tier the focused artwork gets its
    // 2048px variant fetched + swapped in during the 1.5 s camera tween —
    // close inspection is pixel-sharp without making every wall texture pay
    // the large-variant cost. No-op on desktop (already large) and low-end
    // (stays small by design).
    upgradeFocusedArtworkTexture.call(this, artwork);

    const artworkWorldPos = new THREE.Vector3();
    artwork.getWorldPosition(artworkWorldPos);

    const artworkDirection = new THREE.Vector3(0, 0, 1);
    artwork.getWorldDirection(artworkDirection);

    const focusDistance = 1.8;
    const targetPos = artworkWorldPos.clone().add(artworkDirection.multiplyScalar(focusDistance));
    targetPos.y = CONFIG.camera.height;

    this.focusTween = gsap.to(this.camera.position, {
        x: targetPos.x, y: targetPos.y, z: targetPos.z,
        duration: 1.5,
        ease: 'power2.inOut',
        onUpdate: () => { this.camera.lookAt(artworkWorldPos); },
        onComplete: () => {
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
                if (typeof window.updateArtworkMeta === 'function') {
                    window.updateArtworkMeta(data);
                }
                panel?.classList.add('show');
            }, 400);
        }
    });
}


// (Task H38 / audit C4) — focus the nearest artwork to the camera.
// Called when the user presses Enter (keyboard navigation alternative
// to click-to-focus). Finds the closest artwork by distance to camera
// and calls toggleArtworkInfo on it.
export function focusNearestArtwork() {
    if (this.isInspecting) {
        // Already inspecting — Exit focus mode (same as pressing E)
        toggleArtworkInfo.call(this);
        return;
    }

    if (!this.artworks || this.artworks.length === 0) return;

    const cameraPos = this.camera.position;
    let nearest = null;
    let nearestDist = Infinity;

    for (const artwork of this.artworks) {
        const pos = new THREE.Vector3();
        artwork.getWorldPosition(pos);
        const dist = cameraPos.distanceTo(pos);
        if (dist < nearestDist) {
            nearestDist = dist;
            nearest = artwork;
        }
    }

    if (nearest) {
        this.focusedArtwork = nearest;
        toggleArtworkInfo.call(this);
    }
}
