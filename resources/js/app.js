import './bootstrap';

import Alpine from 'alpinejs';
import { initMonitoring } from './monitoring';

initMonitoring();

// localStorage/sessionStorage can throw SecurityError when storage is
// blocked (e.g. "block all cookies"); UI state must survive that.
window.exospaceStorage = {
    get(key) {
        try { return window.localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch { /* storage unavailable */ }
    },
};

window.Alpine = Alpine;

Alpine.start();

import * as Turbo from '@hotwired/turbo';

// Make Turbo available globally for debugging + opt-out in views.
window.Turbo = Turbo;

window.exospaceConfirm = function(event, message) {
    // If event is a real Event, prevent the default submission/navigation.
    if (event && event.preventDefault) {
        event.preventDefault();
    }

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

        const trigger = event?.target;
        const verbHost = trigger?.closest ? trigger.closest('[data-confirm-button]') : null;
        if (verbHost) {
            okBtn.textContent = verbHost.getAttribute('data-confirm-button');
            if (verbHost.getAttribute('data-confirm-danger') === 'false') {
                okBtn.className = 'btn btn-primary flex-1';
                iconWrap.className = 'w-12 h-12 bg-brand-500/10 rounded-full flex items-center justify-center mx-auto mb-4';
                iconWrap.innerHTML = '<svg class="w-6 h-6 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                title.textContent = 'Please Confirm';
            }
        }

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
            btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>' + label + '</span>';
        }
    });
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

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute('data-busy')) return;
    if (form.hasAttribute('data-confirm')) return; // confirmed path guards itself
    if (form.__exospaceBusy) { e.preventDefault(); return; }
    window.exospaceGuardForm(form);
}, true); // capture — runs before form-level listeners

window.exospaceConfirmWrapper = function(arg, e) {
    const el = this;
    const msg = (typeof arg === 'string' && arg && arg !== '[object HTMLFormElement]')
        ? arg
        : (el.getAttribute?.('data-confirm-message') || el.getAttribute?.('data-confirm') || 'Are you sure?');
    return window.exospaceConfirm(e, msg);
};

window.disableSubmitButton = function(form) {
    window.exospaceGuardForm(form);
};

window.submitForm = function(el) {
    const form = el.closest ? el.closest('form') : null;
    if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
};

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

window.openModalAnchor = function(id, e) {
    if (e && e.preventDefault) e.preventDefault();
    if (window.openModal) window.openModal(id);
};

if (!window.__exospaceModalSystemInit) {
    window.__exospaceModalSystemInit = true;

    document.addEventListener('click', (e) => {
        const m = e.target.closest('[role="dialog"]');
        if (!m || e.target !== m || !m.id) return;
        if (m.closest('[x-data]')) return;
        if (m.style.display !== 'none') closeModal(m);
    });

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

if (!window.__exospaceImgFallbackInit) {
    window.__exospaceImgFallbackInit = true;
    const hideImg = (img) => {
        img.style.visibility = 'hidden';
        img.setAttribute('aria-hidden', 'true');
    };
    const scanForCached404s = () => {
        document.querySelectorAll('img.venue-thumb-img, img[data-fallback-hide]').forEach(img => {
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
