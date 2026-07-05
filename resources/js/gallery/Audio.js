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
    if (!galleryData.audioUrl) return;

    this.sound = new THREE.Audio(this.listener);
    const audioLoader = new THREE.AudioLoader();
    audioLoader.load(
        galleryData.audioUrl,
        (buffer) => {
            this.sound.setBuffer(buffer);
            this.sound.setLoop(true);
            this.sound.setVolume(0.5);
            this.audioReady = true;
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
    if (!this.audioReady || !this.sound) return;
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
