// ─────────────────────────────────────────────────────────────────────────────
// Movement — physics-based WASD + cinematic lean + footstep SFX
//
// All calls into THREE.PointerLockControls helpers (moveForward, moveRight)
// stay here so the rest of the codebase doesn't depend on the controls lib.
// ─────────────────────────────────────────────────────────────────────────────

import { CONFIG } from './config.js';

// Desktop movement (pointer-lock controls + WASD)
export function updateMovement() {
    if (!this.controls.isLocked || this.isInspecting) return;

    const delta = Math.min(this.clock.getDelta(), 0.1); // cap at 100ms

    // ── Step 1: Damping (friction) ──────────────────────────────────────────
    const dampingFactor = Math.pow(1 / CONFIG.camera.damping, delta);
    this.velocity.multiplyScalar(dampingFactor);
    if (this.velocity.length() < 0.001) this.velocity.set(0, 0, 0);

    // ── Step 2: Input + acceleration ────────────────────────────────────────
    this.direction.set(0, 0, 0);
    let isMoving = false;
    if (this.moveState.forward)  { this.direction.z -= 1; isMoving = true; }
    if (this.moveState.backward) { this.direction.z += 1; isMoving = true; }
    if (this.moveState.left)     { this.direction.x -= 1; isMoving = true; }
    if (this.moveState.right)    { this.direction.x += 1; isMoving = true; }

    if (this.direction.length() > 0) {
        this.direction.normalize();
        const speedMultiplier  = this.currentSpeedMultiplier || 1;
        const sprintMultiplier = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
        const totalMultiplier  = speedMultiplier * sprintMultiplier;

        this.velocity.x += this.direction.x * CONFIG.camera.acceleration * delta * totalMultiplier;
        this.velocity.z += this.direction.z * CONFIG.camera.acceleration * delta * totalMultiplier;
    }

    // ── Step 3: Clamp to max speed ──────────────────────────────────────────
    const speedMultiplier  = this.currentSpeedMultiplier || 1;
    const sprintMultiplier = this.moveState.sprint ? CONFIG.movement.sprintMultiplier : 1;
    const maxSpeed = CONFIG.camera.maxSpeed * speedMultiplier * sprintMultiplier;

    const currentSpeed = Math.sqrt(this.velocity.x ** 2 + this.velocity.z ** 2);
    if (currentSpeed > maxSpeed) {
        const scale = maxSpeed / currentSpeed;
        this.velocity.x *= scale;
        this.velocity.z *= scale;
    }

    // ── Step 4: Apply velocity via PointerLockControls helpers ──────────────
    this.controls.moveRight(this.velocity.x * delta);
    this.controls.moveForward(-this.velocity.z * delta);

    // ── Step 5: Collisions ──────────────────────────────────────────────────
    this.enforceRoomBounds();
    this.camera.position.y = CONFIG.camera.height;

    // ── Step 6: Cinematic lean (camera rolls slightly into turns) ───────────
    // (Task H43 / audit C4) — skip camera lean for reduced-motion users.
    // The rolling sensation can trigger vestibular discomfort.
    if (this.reducedMotion) {
        this.currentLean = 0;
    } else {
        const targetLean = -this.velocity.x * CONFIG.camera.maxLean;
        this.currentLean += (targetLean - this.currentLean) * CONFIG.camera.leanSpeed;
    }

    // ── Step 7: Footstep SFX (only while input is held + actually moving) ───
    if (!this.isInspecting && this.sfxEnabled && this.sfx.footstep) {
        const hasInput = this.moveState.forward || this.moveState.backward ||
                         this.moveState.left    || this.moveState.right;
        const speed    = this.velocity.length();
        const movingNow = hasInput && speed > 0.05;

        if (movingNow) {
            this.isSprinting     = this.moveState.sprint || false;
            this.speedMultiplier = this.currentSpeedMultiplier || 1.0;

            const baseInterval = 0.5;
            const sprintMult   = this.isSprinting ? 0.6 : 1.0;
            const speedMult    = this.speedMultiplier || 1.0;
            let stepInterval = (baseInterval * sprintMult) / Math.sqrt(speedMult);
            stepInterval = Math.max(0.2, Math.min(0.6, stepInterval));

            this.footstepTimer += delta;
            if (this.footstepTimer >= stepInterval) {
                if (this.sfx.footstep.isPlaying) this.sfx.footstep.stop();
                this.sfx.footstep.setPlaybackRate(0.95 + Math.random() * 0.1);
                this.sfx.footstep.play();
                this.footstepTimer = 0;
                this.lastStepTime  = Date.now();
            }
        } else {
            this.footstepTimer = 0;
            if (this.sfx.footstep.isPlaying) this.sfx.footstep.stop();
        }
    }
}

// Mobile movement — virtual joystick drives the same velocity model.
// Defined in Mobile.js but kept here for parity with the desktop version.
export function updateMovementMobile() {
    // Implementation lives in Mobile.js (this method is bound from there)
    if (typeof this._mobileUpdateMovement === 'function') {
        this._mobileUpdateMovement();
    }
}
