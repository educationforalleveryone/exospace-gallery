// ─────────────────────────────────────────────────────────────────────────────
// Controls — keyboard + pointer-lock + speed multiplier
//
// Mobile touch controls are wired in Mobile.js (conditionally, when a touch
// device is detected). This module handles desktop only.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
import { CONFIG } from './config.js';
import { setupMobileControls } from './Mobile.js';

export function setupControls() {
    this.controls = new PointerLockControls(this.camera, document.body);

    // Movement state — set by keyboard handlers, read by Movement.updateMovement
    // (PERF-B10: preserved across context-restore rebuilds)
    this.moveState = this.moveState || { forward: false, backward: false, left: false, right: false, sprint: false };

    // Initial speed multiplier
    this.currentSpeedMultiplier = this.currentSpeedMultiplier || CONFIG.movement.speedMultipliers[CONFIG.movement.currentSpeedIndex];

    // PERF-B10 (3D audit F10): document/window/container-level listeners
    // survive a context-restore rebuild — register them exactly once. The
    // handlers are late-bound to `this`, so they keep working with whatever
    // camera/renderer objects the rebuild creates.
    if (!this._controlsBound) {
        this._controlsBound = true;

        // ── Keydown ────────────────────────────────────────────────────────
        // (Task H38 / audit C4) — filter keydown when the user is typing in
        // an input/textarea (e.g. the newsletter signup on the curtain).
        // Previously, pressing WASD inside the email field would both type
        // the letter AND start moving the invisible camera.
        document.addEventListener('keydown', (e) => {
            const tag = e.target?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable) return;

            switch (e.code) {
                case 'KeyW': case 'ArrowUp':    this.moveState.forward  = true; break;
                case 'KeyS': case 'ArrowDown':  this.moveState.backward = true; break;
                case 'KeyA': case 'ArrowLeft':  this.moveState.left     = true; break;
                case 'KeyD': case 'ArrowRight': this.moveState.right    = true; break;
                case 'ShiftLeft': this.moveState.sprint = true; break;
                case 'KeyE': this.toggleArtworkInfo(); break;
                // (Task H38) Enter focuses the nearest artwork (accessible
                // alternative to click-to-focus for keyboard-only users)
                case 'Enter': this.focusNearestArtwork(); break;
                // Speed multipliers
                case 'Digit1': this.setSpeedMultiplier(0); break; // 1x
                case 'Digit2': this.setSpeedMultiplier(1); break; // 2x
                case 'Digit3': this.setSpeedMultiplier(2); break; // 4x
                case 'Digit4': this.setSpeedMultiplier(3); break; // 8x
            }
        });

        // ── Keyup ──────────────────────────────────────────────────────────
        document.addEventListener('keyup', (e) => {
            switch (e.code) {
                case 'KeyW': case 'ArrowUp':    this.moveState.forward  = false; break;
                case 'KeyS': case 'ArrowDown':  this.moveState.backward = false; break;
                case 'KeyA': case 'ArrowLeft':  this.moveState.left     = false; break;
                case 'KeyD': case 'ArrowRight': this.moveState.right    = false; break;
                case 'ShiftLeft': this.moveState.sprint = false; break;
            }
        });

        // ── Click canvas to lock pointer ──────────────────────────────────
        this.container.addEventListener('click', () => {
            if (!this.isMobile) this.controls.lock();
        });

        // ── Window resize ──────────────────────────────────────────────────
        window.addEventListener('resize', () => {
            this.camera.aspect = window.innerWidth / window.innerHeight;
            this.camera.updateProjectionMatrix();
            this.renderer.setSize(window.innerWidth, window.innerHeight);
            this._postFx?.resize();
        });
    }

    // ── Pointer lock events (bound to the CONTROLS instance — die with it) ──
    this.controls.addEventListener('lock', () => {
        document.getElementById('crosshair')?.classList.add('active');
    });
    this.controls.addEventListener('unlock', () => {
        document.getElementById('crosshair')?.classList.remove('active');
    });

    // ── Initialise mobile controls if a touch device is detected ───────────
    setupMobileControls.call(this);
}

export function setSpeedMultiplier(index) {
    CONFIG.movement.currentSpeedIndex = index;
    this.currentSpeedMultiplier = CONFIG.movement.speedMultipliers[index];

    const speedDisplay = document.getElementById('speed-value');
    if (speedDisplay) {
        speedDisplay.textContent = `${this.currentSpeedMultiplier}x`;
        const wrapper = speedDisplay.parentElement?.parentElement;
        if (wrapper) {
            wrapper.style.transform = 'scale(1.1)';
            setTimeout(() => { wrapper.style.transform = 'scale(1)'; }, 200);
        }
    }
}
