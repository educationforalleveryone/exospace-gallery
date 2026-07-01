// ─────────────────────────────────────────────────────────────────────────────
// Mobile — touch joystick + look pad + sprint toggle
//
// Detects touch devices on init. If detected:
//   - Disables PointerLockControls
//   - Shows the mobile-overlay (defined in view.blade.php)
//   - Wires joystick (left half of screen) + look pad (right half)
//   - Exposes _mobileUpdateMovement() so Movement.js can call it
// ─────────────────────────────────────────────────────────────────────────────

import { CONFIG } from './config.js';

export function setupMobileControls() {
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        || (navigator.maxTouchPoints > 0 && 'ontouchstart' in window);

    if (!isMobile) {
        this.isMobile = false;
        return;
    }

    this.isMobile = true;
    this.mobileState = {
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

    // Init touch handlers
    this._initJoystick?.();
    this._initLookPad?.();
    this._initSprintButton?.();
    this._initSpeedDial?.();
    this._initDoubleTap?.();

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

    // Wire the movement function — Movement.js calls this._mobileUpdateMovement()
    this._mobileUpdateMovement = _mobileUpdateMovement.bind(this);

    // ── Joystick (left half) ────────────────────────────────────────────────
    this._initJoystick = () => {
        const zone  = document.getElementById('joystick-zone');
        const base  = document.getElementById('joystick-base');
        const thumb = document.getElementById('joystick-thumb');
        if (!zone || !base || !thumb) return;

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
    };
    this._initJoystick();

    // ── Look pad (right half) ───────────────────────────────────────────────
    this._initLookPad = () => {
        const zone = document.getElementById('look-zone');
        if (!zone) return;

        zone.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (this.mobileState.look.active) return;
            const touch = e.changedTouches[0];
            this.mobileState.look.active  = true;
            this.mobileState.look.touchId = touch.identifier;
            this.mobileState.look.lastX   = touch.clientX;
            this.mobileState.look.lastY   = touch.clientY;
        }, { passive: false });

        zone.addEventListener('touchmove', (e) => {
            e.preventDefault();
            for (const touch of e.changedTouches) {
                if (touch.identifier !== this.mobileState.look.touchId) continue;
                this.mobileState.look.deltaX = (touch.clientX - this.mobileState.look.lastX) * 0.003;
                this.mobileState.look.deltaY = (touch.clientY - this.mobileState.look.lastY) * 0.003;
                this.mobileState.look.lastX = touch.clientX;
                this.mobileState.look.lastY = touch.clientY;
            }
        }, { passive: false });

        zone.addEventListener('touchend', (e) => {
            for (const touch of e.changedTouches) {
                if (touch.identifier !== this.mobileState.look.touchId) continue;
                this.mobileState.look.active  = false;
                this.mobileState.look.touchId = null;
                this.mobileState.look.deltaX  = 0;
                this.mobileState.look.deltaY  = 0;
            }
        });
    };
    this._initLookPad();

    // ── Sprint toggle button ────────────────────────────────────────────────
    this._initSprintButton = () => {
        const btn = document.getElementById('sprint-btn');
        if (!btn) return;
        btn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            this.mobileState.sprint = !this.mobileState.sprint;
            btn.classList.toggle('active', this.mobileState.sprint);
        }, { passive: false });
    };
    this._initSprintButton();

    // ── Speed dial (cycles 1x → 2x → 4x → 8x) ───────────────────────────────
    this._initSpeedDial = () => {
        const btn = document.getElementById('speed-dial');
        if (!btn) return;
        btn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            const next = (CONFIG.movement.currentSpeedIndex + 1) % CONFIG.movement.speedMultipliers.length;
            this.setSpeedMultiplier(next);
            btn.textContent = `${this.currentSpeedMultiplier}x`;
        }, { passive: false });
    };
    this._initSpeedDial();

    // ── Double-tap to focus artwork ─────────────────────────────────────────
    this._initDoubleTap = () => {
        const zone = document.getElementById('look-zone');
        if (!zone) return;
        zone.addEventListener('touchend', () => {
            const now = Date.now();
            if (now - this.mobileState.lastTap < 300) {
                this.toggleArtworkInfo();
            }
            this.mobileState.lastTap = now;
        });
    };
    this._initDoubleTap();
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
