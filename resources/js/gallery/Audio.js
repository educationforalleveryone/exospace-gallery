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

// Fetch SFX buffers + set up streaming background music. Idempotent — safe
// to call from every gesture handler (Enter button, tour start).
//
// PERF-E26 (3D audit): background music used to download the ENTIRE track
// into an AudioBuffer before a single note could play — a multi-MB blocking
// fetch competing with artwork textures, for a feature most visitors hear
// for a few seconds before focusing on the art. Music now streams through
// an HTMLAudioElement: playback starts as soon as the first seconds arrive,
// the browser manages buffering, and nothing is held in memory as a decoded
// PCM buffer. SFX (footstep/click, a few KB) stay as buffers — they need
// low-latency replay.
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

    // Background music (per-gallery, optional) — STREAMING element
    if (!this._audioUrl) return;

    const el = new Audio(this._audioUrl);
    el.loop   = true;
    el.volume = 0.5;
    // Fetching begins at the first play() — which is always inside a user
    // gesture (Enter click / tour start), so autoplay policy is satisfied
    // and zero music bytes compete with the pre-Enter texture downloads.
    el.preload = 'none';
    this._musicEl = el;
}

export function playAudio() {
    // PERF-E26: streaming music — element.play() starts buffering + playback
    // immediately; no full-track download, no audioReady gate.
    if (this._musicEl) {
        this._musicEl.play().catch((e) => {
            // Autoplay rejections (rare — always gesture-driven here) and
            // network stalls are non-fatal: the gallery stays silent.
            if (e?.name !== 'AbortError') console.warn('Music playback failed:', e?.name || e);
        });
        return;
    }
    // Legacy path (music loaded as buffer before this iteration) — kept for
    // admin preview iframes that never re-fetch after a hot rebuild.
    if (!this.audioReady || !this.sound) {
        if (this.sound) this._autoplayWhenReady = true;
        return;
    }
    if (this.sound.isPlaying) return;
    try { this.sound.play(); } catch (e) { console.error('Audio play error:', e); }
}

// P2-16: Audio mute/unmute toggle. Exposed so view.blade.php can wire a button.
export function toggleMute() {
    if (!this._musicEl && !this.sound && !this.sfx.footstep && !this.sfx.click) return;

    this._muted = !this._muted;

    // PERF-E26: streaming music mutes via element volume
    if (this._musicEl) {
        this._musicEl.volume = this._muted ? 0 : 0.5;
    }
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
