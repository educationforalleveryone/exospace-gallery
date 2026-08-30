@php
/**
 * ITERATION-2 (AUDIT-P1-2.5): Unified toast component.
 *
 * Previously the toast HTML container + the `window.toast()` function were
 * inlined in BOTH `layouts/app.blade.php` and `layouts/public.blade.php`,
 * and the two copies had drifted:
 *   - app.blade.php    had Turbo-safe `__exospaceImgFallbackInit` guard + keyboard shortcut wiring
 *   - public.blade.php had simpler `DOMContentLoaded`-bound handlers (older pattern)
 *
 * This component is the single source of truth. Both layouts include it via:
 *   <x-toast />
 *
 * The component renders:
 *   1. The empty `#toast-container` div (positioned bottom-right, ARIA live region).
 *   2. The `window.toast(message, type)` function (CSP-safe via `@nonce`).
 *   3. Auto-toasts for Laravel flash session keys (success/error/info/status/warning).
 *
 * Accessibility:
 *   - `aria-live="polite"` on the container so screen readers announce toasts
 *     without interrupting the user.
 *   - Error toasts use `role="alert"` (assertive) instead of `role="status"`,
 *     per WCAG ARIA-11. Other toasts use `role="status"` (polite).
 *   - Each toast auto-dismisses after 3.5s with a 300ms exit animation.
 *
 * Turbo Drive compatibility:
 *   - The `window.toast` function is defined on `window` (not inside an IIFE)
 *     so it survives Turbo Drive body-swaps. The function is idempotent —
 *     re-defining it on every page load is safe (overwrites the previous).
 *   - The auto-toast calls read Laravel flash session data at render time,
 *     so they fire on the initial page load AND on Turbo Drive navigations
 *     (because Turbo Drive replaces the <body> including this script).
 *
 * CSP safety:
 *   - The <script> tag carries `nonce="@nonce"` — required by the CSP policy
 *     `script-src 'self' 'nonce-<random>' 'strict-dynamic'`.
 */
@endphp

<div id="toast-container" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none" aria-live="polite"></div>

<script nonce="@nonce">
// Global toast utility — defined on `window` so it survives Turbo Drive
// body-swaps. Re-defining it is safe (idempotent overwrite).
window.toast = function(message, type = 'success') {
    const colors = {
        success: 'bg-gray-900 border-emerald-500/40',
        error:   'bg-gray-900 border-red-500/40',
        info:    'bg-gray-900 border-gray-600',
    };
    const icons = {
        success: '<svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>',
        error:   '<svg class="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>',
        info:    '<svg class="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    };
    const container = document.getElementById('toast-container');
    if (!container) {
        // Should never happen — the container is rendered above — but be defensive.
        console.warn('[toast] #toast-container not found');
        return;
    }
    const el = document.createElement('div');
    // A11Y-5: Error toasts use role=alert (assertive), others use role=status (polite)
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border text-sm font-medium text-gray-100 shadow-2xl backdrop-blur-sm ${colors[type] || colors.info} transition-all duration-300 translate-y-2 opacity-0 min-w-[260px] max-w-sm`;
    el.innerHTML = `${icons[type] || icons.info}<span class="flex-1"></span>`;
    el.querySelector('span').textContent = message; // XSS-safe (textContent, not innerHTML)
    container.appendChild(el);
    requestAnimationFrame(() => { el.classList.remove('translate-y-2', 'opacity-0'); });
    setTimeout(() => {
        el.classList.add('translate-y-2', 'opacity-0');
        setTimeout(() => el.remove(), 300);
    }, 3500);
};

// Auto-toast Laravel flash messages — read at render time so they fire on
// both initial page load AND Turbo Drive navigations (Turbo re-executes
// this <script> when the <body> is swapped).
@if(session('success')) toast(@json(session('success')), 'success'); @endif
@if(session('error'))   toast(@json(session('error')), 'error'); @endif
@if(session('info'))    toast(@json(session('info')), 'info'); @endif
@if(session('status'))  toast(@json(session('status')), 'success'); @endif
@if(session('warning')) toast(@json(session('warning')), 'error'); @endif
</script>
