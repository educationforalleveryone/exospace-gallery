// ─────────────────────────────────────────────────────────────────────────────
// Audio — ambient music + footstep/click SFX
//
// Audio is bundled via Three.js's Audio/AudioLoader. No CDN involved.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

export function initAudio() {
    const galleryData = window.GALLERY_DATA;

    // Audio listener attached to camera (positional audio source)
    this.listener = new THREE.AudioListener();
    this.camera.add(this.listener);

    // PERF-A6 (3D audit F6): SFX + music buffers are NOT fetched here anymore.
    // They used to download at page load — competing for bandwidth with the
    // artwork textures that gate the Enter button, even though autoplay
    // policies mean nothing can play before the first user gesture anyway.
    // loadAudioAssets() is now called from main.js on the Enter click /
    // tour start — the first moment audio is actually reachable.
    // Consumers already guard on `this.sfx.footstep` / `this.audioReady`
    // being undefined, so the gap between gesture and buffer-ready is safe.
    this._audioLoadStarted = false;
    this._audioUrl = galleryData.audioUrl || null;
}

// Fetch SFX (+ optional gallery music) buffers. Idempotent — safe to call
// from every gesture handler (Enter button, tour start).
export function loadAudioAssets() {
    if (this._audioLoadStarted) return;
    this._audioLoadStarted = true;

    const sfxLoader = new THREE.AudioLoader();

    // Footstep SFX
    sfxLoader.load('/assets/audio/sfx/footstep.mp3', (buffer) => {
        this.sfx.footstep = new THREE.Audio(this.listener);
        this.sfx.footstep.setBuffer(buffer);
        this.sfx.footstep.setVolume(0.25);
        this.sfx.footstep.setPlaybackRate(1.0);
    }, undefined, () => console.warn('⚠️ footstep.mp3 failed'));

    // Interaction click SFX
    sfxLoader.load('/assets/audio/sfx/interaction_click.mp3', (buffer) => {
        this.sfx.click = new THREE.Audio(this.listener);
        this.sfx.click.setBuffer(buffer);
        this.sfx.click.setVolume(0.4);
    }, undefined, () => console.warn('⚠️ interaction_click.mp3 failed'));

    // Background music (per-gallery, optional)
    if (!this._audioUrl) return;

    this.sound = new THREE.Audio(this.listener);
    const audioLoader = new THREE.AudioLoader();
    audioLoader.load(
        this._audioUrl,
        (buffer) => {
            this.sound.setBuffer(buffer);
            this.sound.setLoop(true);
            this.sound.setVolume(0.5);
            this.audioReady = true;
            // If the user already pressed Enter (playAudio was a no-op before
            // the buffer arrived), start now that we're ready.
            if (this._autoplayWhenReady) this.playAudio();
        },
        (progress) => {
            if (progress.total) {
                console.log('🎵 Music:', Math.round((progress.loaded / progress.total) * 100) + '%');
            }
        },
        (err) => console.error('❌ Background music load failed:', err)
    );
}

export function playAudio() {
    if (!this.audioReady || !this.sound) {
        // Buffer not decoded yet (audio loading is deferred to first gesture —
        // PERF-A6). Remember the intent so music fades in as soon as it's
        // ready instead of staying silent for the whole visit.
        if (this.sound) this._autoplayWhenReady = true;
        return;
    }
    if (this.sound.isPlaying) return;
    try { this.sound.play(); } catch (e) { console.error('Audio play error:', e); }
}

// P2-16: Audio mute/unmute toggle. Exposed so view.blade.php can wire a button.
export function toggleMute() {
    if (!this.sound && !this.sfx.footstep && !this.sfx.click) return;

    this._muted = !this._muted;

    if (this.sound) {
        this.sound.setVolume(this._muted ? 0 : 0.5);
    }
    if (this.sfx.footstep) {
        this.sfx.footstep.setVolume(this._muted ? 0 : 0.25);
    }
    if (this.sfx.click) {
        this.sfx.click.setVolume(this._muted ? 0 : 0.4);
    }

    // Update the button UI
    const btn = document.getElementById('audio-toggle');
    if (btn) {
        btn.textContent = this._muted ? '🔇' : '🔊';
        btn.setAttribute('aria-label', this._muted ? 'Unmute audio' : 'Mute audio');
        btn.setAttribute('aria-pressed', String(this._muted));
    }
}
