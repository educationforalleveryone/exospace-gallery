// ─────────────────────────────────────────────────────────────────────────────
// FocusMode — click an artwork to tween the camera up close + show info panel
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import gsap from 'gsap';
import { CONFIG } from './config.js';
import { Analytics } from './Analytics.js';

// Raycast from screen centre each frame; toggle crosshair colour when an
// artwork is in the reticle.
export function checkArtworkFocus() {
    if (!this.controls.isLocked) return;

    this._reusableVector.set(0, 0);
    this.raycaster.setFromCamera(this._reusableVector, this.camera);
    const intersects = this.raycaster.intersectObjects(this.artworks, true);

    const crosshair = document.getElementById('crosshair');

    if (intersects.length > 0) {
        const artwork = intersects[0].object.parent;
        if (artwork.userData.type === 'artwork' && artwork !== this.focusedArtwork) {
            this.focusedArtwork = artwork;
            if (crosshair && !this.isInspecting) crosshair.classList.add('focused');
        }
    } else {
        this.focusedArtwork = null;
        if (crosshair && !this.isInspecting) crosshair.classList.remove('focused');
    }
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
