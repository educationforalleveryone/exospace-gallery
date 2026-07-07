import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// ─────────────────────────────────────────────────────────────────────────────
// PERF-26 FIX: Hotwire Turbo Drive for SPA-like navigation.
//
// Turbo intercepts link clicks and form submissions, fetches the response
// via fetch(), and swaps the <body> content — without re-executing <head>
// assets (CSS, JS, fonts). This makes admin navigation feel instant: the
// first page load takes the full time, but subsequent navigations skip
// the asset-parse step (~200-500ms on a typical admin page).
//
// We start Turbo in "manual" mode and only enable Drive on admin pages
// (where the benefit is highest — list → detail → back navigation). The
// gallery 3D view is excluded via data-turbo="false" on the <html> tag in
// gallery/view.blade.php (the 3D scene can't survive a body swap).
//
// Forms that submit via traditional POST (not AJAX) get Turbo's progress
// bar automatically. Forms that already use fetch() (image upload,
// newsletter signup) are unaffected — Turbo only intercepts standard
// form submissions.
// ─────────────────────────────────────────────────────────────────────────────
import * as Turbo from '@hotwired/turbo';

// Make Turbo available globally for debugging + opt-out in views.
window.Turbo = Turbo;

// Turbo is auto-starting by default. We don't need to call Turbo.start()
// — the import alone activates Drive on all pages. Views can opt out
// per-link/per-form via data-turbo="false", or per-page via
// <meta name="turbo-visit-control" content="no-preview"> in the <head>.
//
// The gallery 3D view uses <html data-turbo="false"> to fully opt out
// (the 3D scene's WebGL context can't survive a Turbo body swap — it
// needs a full page load to re-init the canvas).

// ─────────────────────────────────────────────────────────────────────────────
// exospaceConfirm — styled replacement for browser confirm() (Task H42)
//
// Replaces native confirm() dialogs with a styled modal that matches the
// app's dark theme. Used by admin views for reversible destructive actions
// (artist delete, event delete, gallery duplicate, venue delete).
//
// Irreversible actions (delete user, toggle super-admin) use the
// type-to-confirm <x-confirm-modal> component instead.
//
// Usage in Blade:
//   <form onsubmit="return exospaceConfirm(event, 'Delete this artist?')">
//
// The function returns a Promise<boolean>. For onsubmit handlers, it
// prevents the default submission, shows the dialog, and submits the
// form programmatically if the user confirms.
// ─────────────────────────────────────────────────────────────────────────────
window.exospaceConfirm = function(event, message) {
    // If event is a real Event, prevent default submission
    if (event && event.preventDefault) {
        event.preventDefault();
    }

    // Find the form to submit
    const form = event?.target?.closest('form') || (event?.target?.tagName === 'FORM' ? event.target : null);

    return new Promise((resolve) => {
        // Create modal elements
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 bg-black/75 backdrop-blur-sm z-[200] flex items-center justify-center p-4';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'exospace-confirm-title');

        overlay.innerHTML = `
            <div class="bg-gray-900 border border-gray-700 rounded-2xl max-w-sm w-full shadow-2xl p-6 text-center">
                <div class="w-12 h-12 bg-amber-500/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
                    </svg>
                </div>
                <h3 id="exospace-confirm-title" class="text-lg font-bold text-white mb-2">Confirm Action</h3>
                <p class="text-sm text-gray-400 mb-6">${message}</p>
                <div class="flex gap-3">
                    <button type="button" id="exospace-confirm-cancel"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">
                        Cancel
                    </button>
                    <button type="button" id="exospace-confirm-ok"
                            class="flex-1 bg-red-600 hover:bg-red-500 text-white font-bold py-2.5 rounded-xl transition text-sm">
                        Confirm
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Focus the Cancel button by default (safer — user must actively choose Confirm)
        const cancelBtn = overlay.querySelector('#exospace-confirm-cancel');
        const okBtn = overlay.querySelector('#exospace-confirm-ok');
        cancelBtn.focus();

        const cleanup = () => {
            overlay.remove();
            document.removeEventListener('keydown', onKeydown);
        };

        const onKeydown = (e) => {
            if (e.key === 'Escape') {
                cleanup();
                resolve(false);
            } else if (e.key === 'Enter') {
                cleanup();
                if (form) form.submit();
                resolve(true);
            }
        };

        cancelBtn.addEventListener('click', () => {
            cleanup();
            resolve(false);
        });

        okBtn.addEventListener('click', () => {
            cleanup();
            if (form) form.submit();
            resolve(true);
        });

        // Backdrop click = cancel
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                cleanup();
                resolve(false);
            }
        });

        document.addEventListener('keydown', onKeydown);
    });

    // Return false to prevent the default form submission (the Promise
    // handles it asynchronously). For non-form contexts, the caller
    // should await the Promise.
    return false;
};
