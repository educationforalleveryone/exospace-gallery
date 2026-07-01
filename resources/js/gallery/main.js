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
