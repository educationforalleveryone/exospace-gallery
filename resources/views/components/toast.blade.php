@php
@endphp

<div id="toast-container" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none" aria-live="polite"></div>

<script nonce="@nonce">
window.toast = function(message, type = 'success') {
    window.__exospaceLastToast = window.__exospaceLastToast || {};
    const key = type + '::' + message;
    const now = Date.now();
    if (window.__exospaceLastToast[key] && now - window.__exospaceLastToast[key] < 900) return;
    window.__exospaceLastToast[key] = now;

    const colors = {
        success: 'bg-gray-900 border-emerald-500/40',
        error:   'bg-gray-900 border-red-500/40',
        warning: 'bg-gray-900 border-amber-500/40',
        info:    'bg-gray-900 border-gray-600',
    };
    const icons = {
        success: '<svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>',
        error:   '<svg class="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>',
        warning: '<svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>',
        info:    '<svg class="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    };
    const container = document.getElementById('toast-container');
    if (!container) {
        // Should never happen — the container is rendered above — but be defensive.
        console.warn('[toast] #toast-container not found');
        return;
    }
    const assertive = type === 'error' || type === 'warning';
    const el = document.createElement('div');
    el.setAttribute('role', assertive ? 'alert' : 'status');
    el.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border text-sm font-medium text-gray-100 shadow-menu backdrop-blur-sm ${colors[type] || colors.info} transition-all duration-300 translate-y-2 opacity-0 min-w-[260px] max-w-[calc(100vw-2rem)] sm:max-w-sm`;
    el.innerHTML = `${icons[type] || icons.info}<span class="flex-1"></span>`;
    el.querySelector('span').textContent = message; // XSS-safe (textContent, not innerHTML)
    container.appendChild(el);
    requestAnimationFrame(() => { el.classList.remove('translate-y-2', 'opacity-0'); });
    const TTL = (type === 'error' || type === 'warning') ? 7000 : 3500;
    setTimeout(() => {
        el.classList.add('translate-y-2', 'opacity-0');
        setTimeout(() => el.remove(), 300);
    }, TTL);
};

(function () {
    const flashLabels = {
        'profile-updated': 'Profile updated',
        'password-updated': 'Password updated',
        'verification-link-sent': 'Verification link sent!',
        'email-verified': 'Email verified — welcome to Exospace!',
    };
    const humanize = (v) => flashLabels[v] ?? v;

    @if(session('success')) toast(humanize(@json(session('success'))), 'success'); @endif
    @if(session('error'))   toast(humanize(@json(session('error'))), 'error'); @endif
    @if(session('info'))    toast(humanize(@json(session('info'))), 'info'); @endif
    @if(session('status'))  toast(humanize(@json(session('status'))), 'success'); @endif
    @if(session('warning')) toast(humanize(@json(session('warning'))), 'warning'); @endif
})();
</script>