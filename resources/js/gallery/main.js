import { GalleryScene } from './GalleryScene.js';
import { GuidedTour }   from './Tour.js';
import { Analytics }    from './Analytics.js';
import { playArrival }  from './Arrival.js';
import { initTryOn }    from './TryOn.js';
import { showLoadError } from './AssetLoader.js';
import { initMonitoring } from '../monitoring.js';

initMonitoring();

let galleryScene = null;
let guidedTour   = null;

window.startGuidedTour = function startGuidedTour() {
    if (!galleryScene) return;
    if (!guidedTour) guidedTour = new GuidedTour(galleryScene);

    galleryScene.loadAudioAssets?.();

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

// Audio mute/unmute toggle — wired to GalleryScene.toggleMute()
window.toggleAudioMute = function toggleAudioMute() {
    if (!galleryScene) return;
    galleryScene.toggleMute?.();
};

window.submitNewsletterSignup = async function submitNewsletterSignup(form) {
    const msg = form.querySelector('.newsletter-msg');
    const data = new FormData(form);

    if (!window.GALLERY_DATA?.newsletterUrl) {
        if (msg) {
            msg.textContent = 'Signup is unavailable right now.';
            msg.style.color = '#fca5a5';
        }
        return false;
    }

    try {
        const res  = await fetch(window.GALLERY_DATA.newsletterUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: data,
        });
        const json = await res.json().catch(() => ({}));
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

document.addEventListener('DOMContentLoaded', () => {
    // Renderer or audio hardware can refuse to start (blocked GPU, context
    // limits, missing AudioContext) — surface the error curtain instead of
    // leaving the visitor on a never-loading entrance.
    try {
        galleryScene = new GalleryScene();
    } catch (error) {
        console.error('Exhibition viewer failed to initialize:', error);
        showLoadError(error);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const enterBtn = document.getElementById('enter-btn');
    if (!enterBtn) return;

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
        galleryScene?.loadAudioAssets?.();

        galleryScene?.startPerfSampling?.(Math.round(performance.now()));

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

        if (window.GALLERY_DATA?.tryOnEnabled) {
            initTryOn(galleryScene);
        }

        playArrival(galleryScene);

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

window.addEventListener('pagehide', () => {
    if (guidedTour?.active) guidedTour.stop();
    if (galleryScene) {
        galleryScene.dispose();
        galleryScene = null;
    }
}, { once: true });

// Also dispose on beforeunload (fallback for older browsers)
window.addEventListener('beforeunload', () => {
    if (guidedTour?.active) guidedTour.stop();
    if (galleryScene) {
        galleryScene.dispose();
        galleryScene = null;
    }
}, { once: true });

window.addEventListener('pageshow', (e) => {
    if (e.persisted && galleryScene === null) {
        window.location.reload();
    }
});
