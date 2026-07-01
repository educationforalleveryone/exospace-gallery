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
