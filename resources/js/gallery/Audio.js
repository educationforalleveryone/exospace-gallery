import * as THREE from 'three';

export function initAudio() {
    const galleryData = window.GALLERY_DATA;

    // Audio listener attached to camera (positional audio source)
    this.listener = new THREE.AudioListener();
    this.camera.add(this.listener);

    this._audioLoadStarted = false;
    this._audioUrl = galleryData?.audioUrl ?? null;
}

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
    el.preload = 'none';
    this._musicEl = el;
}

export function playAudio() {
    if (this._musicEl) {
        this._musicEl.play().catch((e) => {
            if (e?.name !== 'AbortError') console.warn('Music playback failed:', e?.name || e);
        });
        return;
    }
    if (!this.audioReady || !this.sound) {
        if (this.sound) this._autoplayWhenReady = true;
        return;
    }
    if (this.sound.isPlaying) return;
    try { this.sound.play(); } catch (e) { console.error('Audio play error:', e); }
}

// Audio mute/unmute toggle. Exposed so view.blade.php can wire a button.
export function toggleMute() {
    if (!this._musicEl && !this.sound && !this.sfx.footstep && !this.sfx.click) return;

    this._muted = !this._muted;

    // Streaming music mutes via element volume
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
