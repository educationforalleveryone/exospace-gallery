import * as THREE from 'three';
import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
import { CONFIG } from './config.js';
import { setupMobileControls } from './Mobile.js';

export function setupControls() {
    this.controls = new PointerLockControls(this.camera, document.body);

    this.moveState = this.moveState || { forward: false, backward: false, left: false, right: false, sprint: false };

    // Initial speed multiplier
    this.currentSpeedMultiplier = this.currentSpeedMultiplier || CONFIG.movement.speedMultipliers[CONFIG.movement.currentSpeedIndex];

    if (!this._controlsBound) {
        this._controlsBound = true;

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
                case 'Escape':
                    if (this.isInspecting) { this.toggleArtworkInfo(); }
                    break;
                case 'Enter': this.focusNearestArtwork(); break;
                // Speed multipliers
                case 'Digit1': this.setSpeedMultiplier(0); break; // 1x
                case 'Digit2': this.setSpeedMultiplier(1); break; // 2x
                case 'Digit3': this.setSpeedMultiplier(2); break; // 4x
                case 'Digit4': this.setSpeedMultiplier(3); break; // 8x
            }
        });

        document.addEventListener('keyup', (e) => {
            switch (e.code) {
                case 'KeyW': case 'ArrowUp':    this.moveState.forward  = false; break;
                case 'KeyS': case 'ArrowDown':  this.moveState.backward = false; break;
                case 'KeyA': case 'ArrowLeft':  this.moveState.left     = false; break;
                case 'KeyD': case 'ArrowRight': this.moveState.right    = false; break;
                case 'ShiftLeft': this.moveState.sprint = false; break;
            }
        });

        this.container.addEventListener('click', () => {
            if (!this.isMobile) this.controls.lock();
        });

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
