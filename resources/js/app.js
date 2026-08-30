import './bootstrap';

// ITERATION-12 (AUDIT-P2-12.1 — REVERTED): The audit recommended switching
// to `alpinejs/dist/cdn.min.js` to remove 'unsafe-eval' from the CSP.
// Investigation found this is NOT possible with Alpine 3.x:
//
//   1. `cdn.min.js` is a UMD/IIFE bundle for direct <script> tag use —
//      it has no ES module `export default`, so `import Alpine from
//      'alpinejs/dist/cdn.min.js'` fails with "default is not exported"
//      (Vite/Rollup build error).
//   2. The ES module build (`module.esm.js`) that DOES have `export default`
//      still uses `new Function` (line 660) to evaluate x-data expressions.
//      There is no Alpine 3.x build that removes `new Function` entirely.
//
// Alpine 3.x's CSP-compatible mode requires NOT using expression strings
// at all (e.g. `x-data="{ open: false }"`) and instead registering every
// component via `Alpine.data('name', () => ({...}))` + `x-data="name"`.
// Exospace uses expression strings across ~20 Blade components — migrating
// all of them is a large refactor (out of scope for this iteration).
//
// DECISION: Keep 'unsafe-eval' in the CSP. The original audit note in
// SecurityHeaders.php (lines 84-85) documented this as a "known tradeoff"
// — that assessment was correct. 'unsafe-eval' is required for Alpine 3.x
// expression evaluation.
//
// Mitigations in place:
//   - 'unsafe-inline' REMOVED (Iter-004): inline scripts need nonces.
//   - 'strict-dynamic' (Iter-004): only nonce'd scripts can load children.
//   - 'unsafe-eval' KEPT: required for Alpine x-data expressions.
//
// The only way to remove 'unsafe-eval' is to either:
//   (a) Migrate all Alpine x-data expressions to Alpine.data() registrations
//       (large refactor — 20+ components, ~50+ expression strings).
//   (b) Replace Alpine with a different frontend framework that doesn't
//       use eval (e.g. Stimulus, Livewire) — even larger refactor.
// Both are deferred to a future iteration.
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
// ITERATION-3 hardening (interaction-reliability pass):
//   1. Enter no longer force-submits the form. Previously ANY Enter press in
//      the dialog called form.submit() — even when focus was on Cancel, so
//      "confirming a cancel" destroyed data. Native button activation already
//      handles Enter/Space on the focused button; the global handler now only
//      listens for Escape.
//   2. The message is inserted with textContent (was innerHTML with raw
//      interpolation — a crafted display name could inject markup, e.g.
//      <img onerror>).
//   3. Focus is restored to the invoking element after close (was dropped to
//      <body>), and Tab is trapped inside the dialog.
//   4. On confirm, the form's submit buttons are disabled + given a spinner
//      (exospaceGuardForm) before submit — no double submissions.
//   5. Stacking: uses the documented z-[60] modal tier (was z-[200] ad-hoc).
//
// Usage (CSP-safe, via the layout delegates):
//   <form data-confirm="Delete this artist?">…</form>
//   <button data-confirm-click="Remove member?">…</button>
//   <form data-submit="exospaceConfirmWrapper" data-confirm-message="…">…</form>
//
// The function returns a Promise<boolean> and — when the triggering element
// lives inside a form — submits that form programmatically on confirm.
// ─────────────────────────────────────────────────────────────────────────────
window.exospaceConfirm = function(event, message) {
    // If event is a real Event, prevent the default submission/navigation.
    if (event && event.preventDefault) {
        event.preventDefault();
    }

    // Find the form to submit (submit event target IS the form; for clicks
    // it is the nearest ancestor form).
    const target = event?.target;
    const form = target?.closest ? target.closest('form') : null;
    // Remember where focus came from so it can be restored on close.
    const invoker = document.activeElement;

    return new Promise((resolve) => {
        const finish = (result) => {
            document.removeEventListener('keydown', onKeydown, true);
            overlay.remove();
            if (result && form) {
                window.exospaceGuardForm(form);
                form.submit();
            }
            if (invoker && document.contains(invoker)) {
                try { invoker.focus(); } catch (e) { /* detached — ignore */ }
            }
            resolve(result);
        };

        const onKeydown = (e) => {
            if (e.key === 'Escape') {
                e.stopPropagation();
                finish(false);
            } else if (e.key === 'Tab') {
                // Focus trap — keep Tab cycling between the two buttons.
                const focusables = [cancelBtn, okBtn];
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && (document.activeElement === last || !overlay.contains(document.activeElement))) {
                    e.preventDefault(); first.focus();
                }
            }
            // NOTE: no Enter branch on purpose — native activation of the
            // focused button already submits/cancels correctly.
        };

        // Build the dialog with DOM APIs (no innerHTML for user-controlled text).
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 bg-black/75 backdrop-blur-sm z-[60] flex items-center justify-center p-4';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'exospace-confirm-title');
        overlay.setAttribute('aria-describedby', 'exospace-confirm-message');

        const panel = document.createElement('div');
        panel.className = 'bg-gray-800 border border-gray-600/50 rounded-xl max-w-sm w-full shadow-modal p-6 text-center';

        const iconWrap = document.createElement('div');
        iconWrap.className = 'w-12 h-12 bg-amber-500/10 rounded-full flex items-center justify-center mx-auto mb-4';
        iconWrap.innerHTML = '<svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>';

        const title = document.createElement('h3');
        title.id = 'exospace-confirm-title';
        title.className = 'text-base font-semibold text-white mb-2';
        title.textContent = 'Confirm Action';

        const body = document.createElement('p');
        body.id = 'exospace-confirm-message';
        body.className = 'text-sm text-gray-400 mb-6 break-words';
        body.textContent = message ?? 'Are you sure?'; // textContent — XSS-safe

        const btnRow = document.createElement('div');
        btnRow.className = 'flex gap-3';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.id = 'exospace-confirm-cancel';
        cancelBtn.className = 'btn btn-secondary flex-1';
        cancelBtn.textContent = 'Cancel';
        const okBtn = document.createElement('button');
        okBtn.type = 'button';
        okBtn.id = 'exospace-confirm-ok';
        okBtn.className = 'btn btn-danger flex-1';
        okBtn.textContent = 'Confirm';

        btnRow.append(cancelBtn, okBtn);
        panel.append(iconWrap, title, body, btnRow);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        // Focus the Cancel button by default (safer — user must actively choose Confirm)
        cancelBtn.focus();

        cancelBtn.addEventListener('click', () => finish(false));
        okBtn.addEventListener('click', () => finish(true));

        // Backdrop click = cancel
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) finish(false);
        });

        document.addEventListener('keydown', onKeydown, true);
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Canonical cross-page submit helpers (ITERATION-3).
//
// These were previously defined per-page (and referenced cross-page), so they
// resolved to `undefined` on every page except the one that defined them —
// e.g. `data-submit="disableSubmitButton"` on Master Control → Billing Review
// did nothing. They now live here, on `window`, so every layout that loads
// app.js has them.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Guard a form against double submission: disables every submit button,
 * marks the form aria-busy, and swaps button labels for a spinner when a
 * data-busy-label is provided. Auto-restores after 60s as a safety net and
 * immediately on bfcache restores (pageshow).
 */
window.exospaceGuardForm = function(form) {
    if (!form || form.__exospaceBusy) return;
    form.__exospaceBusy = true;
    form.setAttribute('aria-busy', 'true');
    const buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
    buttons.forEach((btn) => {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;
        const label = form.getAttribute('data-busy-label') || btn.getAttribute('data-busy-label');
        if (label) {
            btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span class="ms-2">' + label + '</span>';
        }
    });
    // Safety net: if navigation never happens (e.g. server stalls then the
    // user stays), restore interactivity after 60s.
    clearTimeout(form.__exospaceBusyTimer);
    form.__exospaceBusyTimer = setTimeout(() => window.exospaceUnguardForm(form), 60000);
};

window.exospaceUnguardForm = function(form) {
    if (!form) return;
    form.__exospaceBusy = false;
    form.removeAttribute('aria-busy');
    clearTimeout(form.__exospaceBusyTimer);
    form.querySelectorAll('button[aria-busy]').forEach((btn) => {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
            delete btn.dataset.originalHtml;
        }
    });
};

// Restore guarded forms after bfcache back-navigation.
window.addEventListener('pageshow', (e) => {
    if (e.persisted) {
        document.querySelectorAll('form[aria-busy]').forEach((f) => window.exospaceUnguardForm(f));
    }
});

/**
 * Opt-in double-submit guard. Add `data-busy` (and optionally
 * `data-busy-label="Publishing…"` / per-button `data-busy-label`) to any
 * standard POST form that must not be submitted twice:
 *   <form method="POST" action="…" data-busy data-busy-label="Publishing…">
 * Forms that confirm first via data-confirm / exospaceConfirm are guarded
 * inside exospaceConfirm itself, so they do NOT need data-busy.
 */
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute('data-busy')) return;
    if (form.hasAttribute('data-confirm')) return; // confirmed path guards itself
    if (form.__exospaceBusy) { e.preventDefault(); return; }
    window.exospaceGuardForm(form);
}, true); // capture — runs before form-level listeners

/**
 * data-submit="exospaceConfirmWrapper" — canonical confirm wrapper.
 * Handles BOTH historical call shapes:
 *   <button data-submit="exospaceConfirmWrapper" data-arg="Message…">  (pending-upgrades)
 *   <form  data-submit="exospaceConfirmWrapper" data-confirm-message="…"> (webhooks/billing)
 * The layout delegate calls fn.call(el, argOrEl, event).
 */
window.exospaceConfirmWrapper = function(arg, e) {
    const el = this;
    const msg = (typeof arg === 'string' && arg && arg !== '[object HTMLFormElement]')
        ? arg
        : (el.getAttribute?.('data-confirm-message') || el.getAttribute?.('data-confirm') || 'Are you sure?');
    return window.exospaceConfirm(e, msg);
};

/**
 * data-submit="disableSubmitButton" — canonical submit-button disabler.
 * The layout delegate calls fn.call(form, form, event).
 */
window.disableSubmitButton = function(form) {
    window.exospaceGuardForm(form);
};

/**
 * data-change="submitForm" — canonical auto-submit-on-change helper for
 * filter selects (replaces inline onchange="this.form.submit()").
 */
window.submitForm = function(el) {
    const form = el.closest ? el.closest('form') : null;
    if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
};

// ─────────────────────────────────────────────────────────────────────────────
// Shared imperative modal helpers (ITERATION-3).
//
// Previously layouts/app.blade.php and layouts/public.blade.php each defined
// their own openModal/closeModal — the public one had a focus trap, the admin
// one didn't, neither had a body scroll lock, and both were re-defined per
// layout. They now live here once, with the full behavior set:
//
//   - body scroll lock while any modal is open (stack-aware)
//   - focus moves to [data-autofocus] or the first focusable element
//   - Tab is trapped inside the top-most open modal
//   - focus is restored to the element that opened the modal
//   - Escape closes the top-most modal (delegated listeners below)
//   - click on the backdrop closes (delegated listeners below)
//
// Any element with role="dialog" + an id can use this system:
//   openModal('share-modal')  /  closeModal('share-modal')
// Hand-rolled modals only need role="dialog", aria-modal="true", an id and
// (ideally) aria-labelledby — no per-page JS plumbing.
// ─────────────────────────────────────────────────────────────────────────────
window.__exospaceModalStack = window.__exospaceModalStack || [];

window.openModal = function(id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m) return;
    m.style.display = 'flex';
    m.classList.add('flex');
    m.classList.remove('hidden');
    if (!window.__exospaceModalStack.includes(m)) {
        m.__exospaceReturnFocus = document.activeElement;
        window.__exospaceModalStack.push(m);
    }
    document.body.classList.add('overflow-y-hidden');
    // Move focus in — prefer an explicit [data-autofocus] target.
    const focusables = m.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    const target = m.querySelector('[data-autofocus]') || focusables[0];
    if (target) setTimeout(() => target.focus(), 50);
};

window.closeModal = function(id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m) return;
    m.style.display = 'none';
    m.classList.remove('flex');
    window.__exospaceModalStack = window.__exospaceModalStack.filter((x) => x !== m);
    if (window.__exospaceModalStack.length === 0) {
        document.body.classList.remove('overflow-y-hidden');
    }
    const back = m.__exospaceReturnFocus;
    if (back && document.contains(back)) {
        try { back.focus(); } catch (e) { /* detached — ignore */ }
    }
    m.__exospaceReturnFocus = null;
};

// Canonical opener for elements whose default behavior must be suppressed
// first — historically <a href="#"> triggers, now real buttons with
// data-click="openModalAnchor" data-arg="modal-id" (ITERATION-4: moved out
// of page-local scripts — pricing was the last copy).
window.openModalAnchor = function(id, e) {
    if (e && e.preventDefault) e.preventDefault();
    if (window.openModal) window.openModal(id);
};

// One-time global modal behavior: backdrop click + Escape + Tab trap.
// Bound to `document` (which survives Turbo Drive body swaps), guarded so
// Turbo re-executing bundle code never stacks duplicates.
if (!window.__exospaceModalSystemInit) {
    window.__exospaceModalSystemInit = true;

    // Backdrop click closes (only when the click lands on the dialog root).
    // Alpine-managed dialogs (root carries x-data) are skipped — their own
    // @click.self owns visibility; writing style.display from outside would
    // permanently break their reopen.
    document.addEventListener('click', (e) => {
        const m = e.target.closest('[role="dialog"]');
        if (!m || e.target !== m || !m.id) return;
        if (m.closest('[x-data]')) return;
        if (m.style.display !== 'none') closeModal(m);
    });

    // Escape closes the top-most open modal (stack-managed ones only).
    // IMPORTANT: no `[role=dialog]` fallback sweep here on purpose —
    // Alpine-driven dialogs (e.g. Master Control's type-to-confirm modals)
    // own their visibility via :class bindings; writing style.display from
    // outside would permanently break their reopen. Every openModal()-based
    // dialog is already in the stack, so it gets closed above; Alpine
    // dialogs close themselves via their own @keydown.escape.window.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const stack = window.__exospaceModalStack;
        if (stack.length > 0) closeModal(stack[stack.length - 1]);
    });

    // Tab trap for the top-most open modal.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const stack = window.__exospaceModalStack;
        if (stack.length === 0) return;
        const m = stack[stack.length - 1];
        const focusables = m.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && (document.activeElement === first || !m.contains(document.activeElement))) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && (document.activeElement === last || !m.contains(document.activeElement))) {
            e.preventDefault(); first.focus();
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Universal focus trap for Alpine-managed dialogs (ITERATION-4).
//
// The stack-managed trap above only covers openModal()-driven dialogs. Alpine
// dialogs (x-data/x-show panels) own their visibility, and writing into their
// state or style.display from outside permanently breaks their reopen — the
// kernel deliberately never touches them. What they've been missing is Tab
// containment: focus could tab out of an open Alpine dialog into the page
// behind the overlay.
//
// Fix without any Alpine coupling: a *delegated* trap. Any element carrying
// `data-focus-trap` gets Tab cycling whenever the focused element lives
// inside it. Zero writes to Alpine state — a pure keydown interceptor, so
// open/close/reopen behavior is untouched. Optional `data-focus-initial` on a
// descendant marks the preferred first Tab stop (informational for tests and
// future open-focus plumbing; components that focus themselves keep doing so).
//
// Markup contract (documented in docs/DESIGN-SYSTEM.md §9):
//   <div x-data="…" data-focus-trap> … overlay + panel … </div>
// ─────────────────────────────────────────────────────────────────────────────
if (!window.__exospaceTrapInit) {
    window.__exospaceTrapInit = true;

    const FOCUSABLE_SEL = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        // A stack-managed modal on top wins — its own trap handled this.
        if (window.__exospaceModalStack.length > 0) return;
        const active = document.activeElement;
        if (!active || active === document.body) return;
        const trap = active.closest('[data-focus-trap]');
        if (!trap) return;
        // x-show hides by display:none; hidden traps must not capture Tab.
        // (offsetParent is always null on position:fixed overlays, so a
        // computed-style check is the only correct visibility test here.)
        if (getComputedStyle(trap).display === 'none') return;
        const focusables = Array.from(trap.querySelectorAll(FOCUSABLE_SEL))
            .filter((el) => el.offsetParent !== null || getComputedStyle(el).position === 'fixed');
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && (active === first || !trap.contains(active))) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && (active === last || !trap.contains(active))) {
            e.preventDefault(); first.focus();
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// CSP-safe image fallback (moved here from the layouts — ITERATION-3).
// Replaces inline `onerror="this.style.display='none'"` handlers that CSP
// blocks. Any <img> tagged with class `venue-thumb-img` (or any img carrying
// `data-fallback-hide`) that fails to load is hidden so the CSS gradient /
// placeholder sibling shows through. 'error' doesn't bubble, so we listen on
// `document` with capture=true: bound once, survives every Turbo navigation,
// and covers images added at any point in the future.
// ─────────────────────────────────────────────────────────────────────────────
if (!window.__exospaceImgFallbackInit) {
    window.__exospaceImgFallbackInit = true;
    const hideImg = (img) => {
        img.style.visibility = 'hidden';
        img.setAttribute('aria-hidden', 'true');
    };
    const scanForCached404s = () => {
        document.querySelectorAll('img.venue-thumb-img, img[data-fallback-hide]').forEach(img => {
            // If the browser already tried and failed before our listener
            // attached (cached 404), check complete/naturalWidth.
            if (img.complete && img.naturalWidth === 0) hideImg(img);
        });
    };
    document.addEventListener('error', (e) => {
        const img = e.target;
        if (img.tagName === 'IMG' && img.matches('.venue-thumb-img, [data-fallback-hide]')) hideImg(img);
    }, true);
    document.addEventListener('turbo:load', scanForCached404s);
    scanForCached404s();
}
