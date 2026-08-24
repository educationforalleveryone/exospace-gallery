// ─────────────────────────────────────────────────────────────────────────────
// Mobile — touch joystick + look pad + sprint toggle
//
// Detects touch devices on init. If detected:
//   - Disables PointerLockControls
//   - Shows the mobile-overlay (defined in view.blade.php)
//   - Wires joystick (left half of screen) + look pad (right half)
//   - Exposes _mobileUpdateMovement() so Movement.js can call it
//
// PERF-B10 (3D audit F10): all document/zone listeners are now registered
// exactly once behind `this._mobileBound` — a WebGL context-restore rebuild
// re-runs setupMobileControls, and the old code re-attached every touch
// listener (double-fired joystick/look events). The dead `this._initJoystick?.()`
// calls that ran BEFORE the functions were defined are also gone.
// ─────────────────────────────────────────────────────────────────────────────

import { CONFIG } from './config.js';

export function setupMobileControls() {
    // P2-17 FIX: Use pointer:coarse media query instead of UA-sniffing.
    // UA-sniffing is fragile — iPad on iOS 13+ reports as Mac desktop.
    // pointer:coarse reliably detects touch-primary devices.
    // Fallback to maxTouchPoints for older browsers that don't support
    // matchMedia('pointer:coarse').
    const isMobile = window.matchMedia('(pointer: coarse)').matches
        || (navigator.maxTouchPoints > 0 && 'ontouchstart' in window);

    if (!isMobile) {
        this.isMobile = false;
        return;
    }

    this.isMobile = true;
    this.mobileState = this.mobileState || {
        joystick: { active: false, touchId: null, originX: 0, originY: 0, deltaX: 0, deltaY: 0 },
        look:     { active: false, touchId: null, lastX: 0, lastY: 0, deltaX: 0, deltaY: 0 },
        sprint:   false,
        lastTap:  0,
        tapTimeout: null,
    };

    // Show overlay + disable pointer lock
    const overlay = document.getElementById('mobile-overlay');
    if (overlay) overlay.classList.add('active');
    this.controls.enabled = false;

    // Hide crosshair (mobile uses tap-to-focus instead)
    const crosshair = document.getElementById('crosshair');
    if (crosshair) crosshair.style.display = 'none';

    if (!this._mobileBound) {
        this._mobileBound = true;

        // Brief hint
        setTimeout(() => {
            const hint = document.getElementById('mobile-hint');
            if (hint) {
                hint.classList.add('show');
                setTimeout(() => hint.classList.remove('show'), 4000);
            }
        }, 2000);

        // Prevent default touch behaviours inside the overlay
        document.addEventListener('touchmove', (e) => {
            if (e.target.closest('#mobile-overlay')) e.preventDefault();
        }, { passive: false });

        // Prevent zoom on double tap
        let lastTouchEnd = 0;
        document.addEventListener('touchend', (e) => {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) e.preventDefault();
            lastTouchEnd = now;
        }, { passive: false });

        // ── Joystick (left half) ────────────────────────────────────────
        const zone  = document.getElementById('joystick-zone');
        const base  = document.getElementById('joystick-base');
        const thumb = document.getElementById('joystick-thumb');
        if (zone && base && thumb) {
            const maxDistance = 35;

            zone.addEventListener('touchstart', (e) => {
                e.preventDefault();
                if (this.mobileState.joystick.active) return;
                const touch = e.changedTouches[0];
                this.mobileState.joystick.active  = true;
                this.mobileState.joystick.touchId = touch.identifier;
                this.mobileState.joystick.originX = touch.clientX;
                this.mobileState.joystick.originY = touch.clientY;
                base.style.left  = `${touch.clientX - 50}px`;
                base.style.top   = `${touch.clientY - 50}px`;
                base.style.display = 'block';
                thumb.style.left = '50px';
                thumb.style.top  = '50px';
            }, { passive: false });

            zone.addEventListener('touchmove', (e) => {
                e.preventDefault();
                for (const touch of e.changedTouches) {
                    if (touch.identifier !== this.mobileState.joystick.touchId) continue;
                    let dx = touch.clientX - this.mobileState.joystick.originX;
                    let dy = touch.clientY - this.mobileState.joystick.originY;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist > maxDistance) {
                        dx = (dx / dist) * maxDistance;
                        dy = (dy / dist) * maxDistance;
                    }
                    this.mobileState.joystick.deltaX = dx / maxDistance;
                    this.mobileState.joystick.deltaY = dy / maxDistance;
                    thumb.style.left = `${50 + dx}px`;
                    thumb.style.top  = `${50 + dy}px`;
                }
            }, { passive: false });

            zone.addEventListener('touchend', (e) => {
                for (const touch of e.changedTouches) {
                    if (touch.identifier !== this.mobileState.joystick.touchId) continue;
                    this.mobileState.joystick.active  = false;
                    this.mobileState.joystick.touchId = null;
                    this.mobileState.joystick.deltaX  = 0;
                    this.mobileState.joystick.deltaY  = 0;
                    base.style.display = 'none';
                }
            });
        }

        // ── Look pad (right half) ───────────────────────────────────────
        const lookZone = document.getElementById('look-zone');
        if (lookZone) {
            lookZone.addEventListener('touchstart', (e) => {
                e.preventDefault();
                if (this.mobileState.look.active) return;
                const touch = e.changedTouches[0];
                this.mobileState.look.active  = true;
                this.mobileState.look.touchId = touch.identifier;
                this.mobileState.look.lastX   = touch.clientX;
                this.mobileState.look.lastY   = touch.clientY;
            }, { passive: false });

            lookZone.addEventListener('touchmove', (e) => {
                e.preventDefault();
                for (const touch of e.changedTouches) {
                    if (touch.identifier !== this.mobileState.look.touchId) continue;
                    this.mobileState.look.deltaX = (touch.clientX - this.mobileState.look.lastX) * 0.003;
                    this.mobileState.look.deltaY = (touch.clientY - this.mobileState.look.lastY) * 0.003;
                    this.mobileState.look.lastX = touch.clientX;
                    this.mobileState.look.lastY = touch.clientY;
                }
            }, { passive: false });

            lookZone.addEventListener('touchend', (e) => {
                for (const touch of e.changedTouches) {
                    if (touch.identifier !== this.mobileState.look.touchId) continue;
                    this.mobileState.look.active  = false;
                    this.mobileState.look.touchId = null;
                    this.mobileState.look.deltaX  = 0;
                    this.mobileState.look.deltaY  = 0;
                }
            });

            // ── Double-tap to focus artwork ─────────────────────────────
            lookZone.addEventListener('touchend', () => {
                const now = Date.now();
                if (now - this.mobileState.lastTap < 300) {
                    this.toggleArtworkInfo();
                }
                this.mobileState.lastTap = now;
            });
        }

        // ── Sprint toggle button ────────────────────────────────────────
        const sprintBtn = document.getElementById('sprint-btn');
        if (sprintBtn) {
            sprintBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                this.mobileState.sprint = !this.mobileState.sprint;
                sprintBtn.classList.toggle('active', this.mobileState.sprint);
            }, { passive: false });
        }

        // ── Speed dial (cycles 1x → 2x → 4x → 8x) ─────────────────────
        const speedBtn = document.getElementById('speed-dial');
        if (speedBtn) {
            speedBtn.addEventListener('touchstart', (e) => {
                e.preventDefault();
                const next = (CONFIG.movement.currentSpeedIndex + 1) % CONFIG.movement.speedMultipliers.length;
                this.setSpeedMultiplier(next);
                speedBtn.textContent = `${this.currentSpeedMultiplier}x`;
            }, { passive: false });
        }
    }

    // Wire the movement function — Movement.js calls this._mobileUpdateMovement()
    // (re-bound on every setup so a context-restore rebuild stays wired)
    this._mobileUpdateMovement = _mobileUpdateMovement.bind(this);
}

// ── Mobile movement — same physics as desktop, fed by joystick ───────────────
function _mobileUpdateMovement() {
    if (this.isInspecting) return;

    const delta = Math.min(this.clock.getDelta(), 0.1);

    // Apply look-delta to camera rotation
    if (this.mobileState.look.deltaX !== 0 || this.mobileState.look.deltaY !== 0) {
        const euler = this._reusableEuler;
        euler.setFromQuaternion(this.camera.quaternion);
        euler.y -= this.mobileState.look.deltaX;
        euler.x -= this.mobileState.look.deltaY;
        const maxPitch = 1.4;
        euler.x = Math.max(-maxPitch, Math.min(maxPitch, euler.x));
        this.camera.quaternion.setFromEuler(euler);
        this.mobileState.look.deltaX = 0;
        this.mobileState.look.deltaY = 0;
    }

    // Damping
    const dampingFactor = Math.pow(1 / CONFIG.camera.damping, delta);
    this.velocity.multiplyScalar(dampingFactor);
    if (this.velocity.length() < 0.001) this.velocity.set(0, 0, 0);

    // Joystick → input direction
    const jx = this.mobileState.joystick.deltaX;
    const jy = this.mobileState.joystick.deltaY;
    if (jx !== 0 || jy !== 0) {
        const speedMult  = this.currentSpeedMultiplier || 1;
        const sprintMult = this.mobileState.sprint ? CONFIG.movement.sprintMultiplier : 1;
        const total = speedMult * sprintMult;
        this.velocity.x += jx * CONFIG.camera.acceleration * delta * total * 0.5;
        this.velocity.z += jy * CONFIG.camera.acceleration * delta * total * 0.5;
    }

    // Clamp
    const speedMult  = this.currentSpeedMultiplier || 1;
    const sprintMult = this.mobileState.sprint ? CONFIG.movement.sprintMultiplier : 1;
    const maxSpeed = CONFIG.camera.maxSpeed * speedMult * sprintMult;
    const curSpeed = Math.sqrt(this.velocity.x ** 2 + this.velocity.z ** 2);
    if (curSpeed > maxSpeed) {
        const scale = maxSpeed / curSpeed;
        this.velocity.x *= scale;
        this.velocity.z *= scale;
    }

    this.controls.moveRight(this.velocity.x * delta);
    this.controls.moveForward(-this.velocity.z * delta);

    this.enforceRoomBounds();
    this.camera.position.y = CONFIG.camera.height;
}
