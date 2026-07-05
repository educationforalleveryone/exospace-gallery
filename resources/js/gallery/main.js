// ─────────────────────────────────────────────────────────────────────────────
// Exospace gallery — entry point
//
// This file is the Vite input declared in vite.config.js. The slim Blade
// template loads this via @vite('resources/js/gallery/main.js').
//
// Boot sequence:
//   1. main.js is imported by the browser.
//   2. On DOMContentLoaded, we create a GalleryScene instance.
//   3. The scene starts the silent preload (textures, HDRIs, artworks).
//   4. The entrance curtain's "Enter" button is wired here.
//   5. Pressing T starts the GuidedTour.
// ─────────────────────────────────────────────────────────────────────────────

import { GalleryScene } from './GalleryScene.js';
import { GuidedTour }   from './Tour.js';
import { Analytics }    from './Analytics.js';

// Global singleton — GuidedTour needs a reference, and the Enter button
// handler needs to reach into the scene to resume the AudioContext.
let galleryScene = null;
let guidedTour   = null;

window.startGuidedTour = function startGuidedTour() {
    if (!galleryScene) return;
    if (!guidedTour) guidedTour = new GuidedTour(galleryScene);

    // Resume audio context on first user gesture (browser autoplay policy)
    if (galleryScene.listener?.context?.state === 'suspended') {
        galleryScene.listener.context.resume().then(() => {
            galleryScene.playAudio?.();
        });
    }

    // Fade out the curtain if the user skipped Enter
    const curtain = document.getElementById('entrance-curtain');
    if (curtain) {
        curtain.style.opacity = '0';
        curtain.style.transition = 'opacity 0.8s ease';
        setTimeout(() => curtain.remove(), 800);
    }

    guidedTour.start(0);
};

// P2-16: Audio mute/unmute toggle — wired to GalleryScene.toggleMute()
window.toggleAudioMute = function toggleAudioMute() {
    if (!galleryScene) return;
    galleryScene.toggleMute?.();
};

window.submitNewsletterSignup = async function submitNewsletterSignup(form) {
    const msg = form.querySelector('.newsletter-msg');
    const data = new FormData(form);
    try {
        const res  = await fetch(window.GALLERY_DATA.newsletterUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: data,
        });
        const json = await res.json();
        if (msg) {
            msg.textContent = json.message || (json.success ? 'Subscribed — thank you.' : 'Could not subscribe.');
            msg.style.color = json.success ? '#86efac' : '#fca5a5';
        }
        if (json.success) form.reset();
    } catch (e) {
        if (msg) {
            msg.textContent = 'Network error — try again.';
            msg.style.color = '#fca5a5';
        }
    }
    return false;
};

// ── Silent preload ──────────────────────────────────────────────────────────
// Start loading the moment the page is ready. The entrance curtain shows a
// progress bar that the scene updates via updateProgress().
document.addEventListener('DOMContentLoaded', () => {
    galleryScene = new GalleryScene();
});

// ── Enter button ────────────────────────────────────────────────────────────
// Wired after DOMContentLoaded so the button is guaranteed to exist.
document.addEventListener('DOMContentLoaded', () => {
    const enterBtn = document.getElementById('enter-btn');
    if (!enterBtn) return;

    // (Task H48 / audit MX6) — "Skip Intro" link. Fires the same enter
    // handler as the Enter button, but doesn't wait for 100% load progress.
    // The enter handler already enables the button if it's still disabled.
    const skipLink = document.getElementById('skip-intro-link');
    if (skipLink) {
        skipLink.addEventListener('click', (e) => {
            e.preventDefault();
            // Force-enable the enter button and trigger its click
            if (enterBtn) {
                enterBtn.style.opacity = '1';
                enterBtn.style.pointerEvents = 'auto';
                enterBtn.click();
            }
        });
    }

    enterBtn.addEventListener('click', () => {
        // Resume audio context — browsers block autoplay until a gesture
        if (galleryScene?.listener?.context) {
            if (galleryScene.listener.context.state === 'suspended') {
                galleryScene.listener.context.resume().then(() => {
                    galleryScene.playAudio?.();
                });
            } else {
                galleryScene.playAudio?.();
            }
        }

        // Mobile: ensure controls are visible
        if (galleryScene?.isMobile) {
            const overlay = document.getElementById('mobile-overlay');
            if (overlay) overlay.classList.add('active');
        }

        // Fade curtain
        const curtain = document.getElementById('entrance-curtain');
        if (curtain) {
            curtain.style.opacity = '0';
            curtain.style.transition = 'opacity 1s ease';
            setTimeout(() => curtain.remove(), 1000);
        }

        // Fire analytics view event (once)
        Analytics.trackView();

        // (Task H41, H44 / audit MX8) — deep-link to a specific artwork.
        // If the URL has ?artwork=<id>, auto-focus that artwork after
        // the scene is ready. Uses the sceneReady event instead of a
        // fixed timer (Task H44) — more reliable on slow connections.
        const deepLinkArtworkId = window.GALLERY_DATA?.deepLinkArtworkId;
        if (deepLinkArtworkId) {
            const focusArtwork = () => {
                if (!galleryScene?.artworks) return;
                const target = galleryScene.artworks.find(
                    a => a.userData?.id === deepLinkArtworkId
                );
                if (target) {
                    galleryScene.focusedArtwork = target;
                    galleryScene.toggleArtworkInfo();
                }
            };

            // If the scene is already ready (artworks loaded), focus now.
            // Otherwise, poll until artworks are available (max 10s).
            if (galleryScene?.artworks?.length > 0) {
                setTimeout(focusArtwork, 500); // small delay for camera settle
            } else {
                let attempts = 0;
                const poll = setInterval(() => {
                    attempts++;
                    if (galleryScene?.artworks?.length > 0) {
                        clearInterval(poll);
                        setTimeout(focusArtwork, 500);
                    } else if (attempts > 100) { // 10s timeout
                        clearInterval(poll);
                    }
                }, 100);
            }
        }
    }, { once: true });

    // ── Tour keyboard shortcut ──────────────────────────────────────────────
    document.addEventListener('keydown', (e) => {
        if (e.code === 'KeyT') {
            if (guidedTour?.active) {
                guidedTour.stop();
            } else {
                window.startGuidedTour();
            }
            return;
        }
        if (guidedTour?.active) {
            if (e.code === 'ArrowRight' || e.code === 'ArrowDown') {
                e.preventDefault();
                guidedTour.next();
            } else if (e.code === 'ArrowLeft' || e.code === 'ArrowUp') {
                e.preventDefault();
                guidedTour.prev();
            } else if (e.code === 'Space') {
                e.preventDefault();
                guidedTour.togglePause();
            }
        }
    });
});

// Expose for debugging in the browser console
window.__exospace = { get scene() { return galleryScene; }, get tour() { return guidedTour; } };

// S-6: Dispose GPU resources when the page is hidden or unloaded.
// Prevents memory leaks in the admin Live Preview iframe (which reloads
// on structural changes) and in the public gallery viewer when the
// visitor navigates away.
window.addEventListener('pagehide', () => {
    if (galleryScene) {
        galleryScene.dispose();
        galleryScene = null;
    }
}, { once: true });

// Also dispose on beforeunload (fallback for older browsers)
window.addEventListener('beforeunload', () => {
    if (galleryScene) {
        galleryScene.dispose();
        galleryScene = null;
    }
}, { once: true });
