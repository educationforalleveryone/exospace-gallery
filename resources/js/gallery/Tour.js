import * as THREE from 'three';
import gsap from 'gsap';
import { CONFIG } from './config.js';
import { Analytics } from './Analytics.js';

export class GuidedTour {
    constructor(scene) {
        this.scene       = scene;
        this.artworks    = [];
        this.index       = 0;
        this.active      = false;
        this.paused      = false;
        this._dwellMs    = 5000;
        this._dwellTimer = null;
        this._countdownRaf = null;
        this._visited    = new Set();
        this._circumference = 2 * Math.PI * 15.9;

        this._reducedMotion = window.EXOSPACE_REDUCED_MOTION === true;
        this._tweenDuration = this._reducedMotion ? 0.1 : 2.0;
        if (this._reducedMotion) {
            this._dwellMs = 3000; // shorter dwell for reduced-motion users
        }
    }

    start(atIndex = 0) {
        this.artworks = this.scene.artworks.slice();
        if (this.artworks.length === 0) {
            // Page toast instead of native alert()
            if (window.toast) window.toast('No artworks to tour in this gallery.', 'info');
            return;
        }

        if (atIndex === 0 && this.scene.arrivalHeroId != null) {
            const heroIdx = this.artworks.findIndex(
                a => a.userData?.id === this.scene.arrivalHeroId
            );
            if (heroIdx >= 0) atIndex = heroIdx;
        }

        this.active = true;
        this.paused = false;
        this.index  = atIndex;
        this._visited = new Set();

        Analytics.trackTourStart();

        // Hide in-gallery tour button while active
        const btn = document.getElementById('in-gallery-tour-btn');
        if (btn) btn.style.display = 'none';

        // Show tour overlay
        const overlay = document.getElementById('tour-overlay');
        if (overlay) overlay.style.display = 'block';

        // Release pointer lock so we can tween the camera freely
        if (this.scene.controls?.isLocked) this.scene.controls.unlock();

        this.scene._cameraScripted = true;

        this._focusCurrent();
    }

    stop() {
        this.active = false;
        this._clearDwell();
        this._clearCountdown();

        if (this.scene.focusTween) {
            this.scene.focusTween.kill();
            this.scene.focusTween = null;
        }
        this.scene._cameraScripted = false;
        if (typeof this.scene._settleCamera === 'function') {
            try { this.scene._settleCamera(); } catch (e) { /* never block stop */ }
        }

        const btn = document.getElementById('in-gallery-tour-btn');
        if (btn) btn.style.display = 'flex';

        const overlay = document.getElementById('tour-overlay');
        if (overlay) overlay.style.display = 'none';

        // Re-lock pointer (desktop only)
        if (!this.scene.isMobile) {
            this.scene.controls?.lock?.();
        }

        // The tour loops, so "complete" means the visitor has seen every
        // artwork; stopping earlier is not a completion.
        if (this.artworks.length > 0 && this._visited.size >= this.artworks.length) {
            Analytics.trackTourComplete();
        }
    }

    next() {
        if (!this.active) return;
        this.index = (this.index + 1) % this.artworks.length;
        this._focusCurrent();
    }

    prev() {
        if (!this.active) return;
        this.index = (this.index - 1 + this.artworks.length) % this.artworks.length;
        this._focusCurrent();
    }

    togglePause() {
        this.paused = !this.paused;
        const pauseIcon = document.getElementById('tour-pause-icon');
        const playIcon  = document.getElementById('tour-play-icon');
        if (pauseIcon) pauseIcon.style.display = this.paused ? 'none' : 'block';
        if (playIcon)  playIcon.style.display  = this.paused ? 'block' : 'none';

        if (this.paused) {
            this._clearDwell();
            this._clearCountdown();
        } else {
            this._startDwell();
        }
    }

    _focusCurrent() {
        if (!this.active || this.index >= this.artworks.length) return;
        const artwork = this.artworks[this.index];
        this._visited.add(this.index);
        const title   = artwork.userData.title || 'Untitled';

        // Update tour HUD
        const counter = document.getElementById('tour-counter');
        const titleEl = document.getElementById('tour-title-display');
        if (counter) counter.textContent = `${this.index + 1} / ${this.artworks.length}`;
        if (titleEl) titleEl.textContent = title.includes('.') ? title.split('.').slice(0,-1).join('.') : title;

        // Compute target camera position (in front of artwork)
        const artworkPos = new THREE.Vector3();
        artwork.getWorldPosition(artworkPos);
        const dir = new THREE.Vector3(0, 0, 1).applyQuaternion(artwork.quaternion);
        const targetPos = artworkPos.clone().add(dir.multiplyScalar(1.8));
        targetPos.y = CONFIG.camera.height;

        // Kill any existing tween
        if (this.scene.focusTween) {
            this.scene.focusTween.kill();
            this.scene.focusTween = null;
        }

        this.scene.focusTween = gsap.to(this.scene.camera.position, {
            x: targetPos.x, y: targetPos.y, z: targetPos.z,
            duration: this._tweenDuration,  // reduced-motion aware
            ease: 'power2.inOut',
            onUpdate: () => { this.scene.camera.lookAt(artworkPos); },
            onComplete: () => {
                this.scene.camera.lookAt(artworkPos);

                // Update info panel
                setTimeout(() => {
                    if (!this.active) return;
                    const data = artwork.userData;
                    let displayTitle = data.title || 'Untitled';
                    if (displayTitle.includes('.')) {
                        displayTitle = displayTitle.split('.').slice(0, -1).join('.');
                    }
                    const titleEl = document.getElementById('artwork-title');
                    const descEl  = document.getElementById('artwork-description');
                    if (titleEl) titleEl.textContent = displayTitle;
                    if (descEl)  descEl.textContent = data.description || 'No description available.';
                    if (typeof window.updateArtworkMeta === 'function') {
                        window.updateArtworkMeta(data);
                    }
                    const panel = document.getElementById('info-panel');
                    if (panel) panel.classList.add('show');

                    Analytics.trackFocus(data.id);

                    // Click SFX
                    if (this.scene.sfxEnabled && this.scene.sfx.click && !this.scene.sfx.click.isPlaying) {
                        this.scene.sfx.click.play();
                    }

                    // Start auto-advance dwell timer
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
        const arc = document.getElementById('tour-ring-arc');
        if (!arc) return;

        const tick = (now) => {
            if (!this.active || this.paused) return;
            const elapsed = now - start;
            const fraction = Math.min(elapsed / duration, 1);
            arc.style.strokeDashoffset = circumference * (1 - fraction);
            if (fraction < 1) this._countdownRaf = requestAnimationFrame(tick);
        };
        this._countdownRaf = requestAnimationFrame(tick);
    }

    _clearCountdown() {
        if (this._countdownRaf) { cancelAnimationFrame(this._countdownRaf); this._countdownRaf = null; }
        const arc = document.getElementById('tour-ring-arc');
        if (arc) arc.style.strokeDashoffset = this._circumference;
    }
}
