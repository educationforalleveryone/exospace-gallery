<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Exospace') }} — {{ isset($pageTitle) ? $pageTitle : 'Dashboard' }}</title>

        <!-- Fonts: Inter for body, display weight for headings -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            /* Global polish: smooth scrolling, better focus rings, reduced-motion respect */
            html { scroll-behavior: smooth; }
            @media (prefers-reduced-motion: reduce) {
                *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
            }
            /* Focus-visible ring consistent across interactive elements */
            *:focus-visible {
                outline: 2px solid rgb(139 92 246);
                outline-offset: 2px;
                border-radius: 4px;
            }
            /* P2-15: Removed unused .skeleton CSS class (defined but never used) */
            /* Smooth page-level fade-in */
            @keyframes pageIn {
                from { opacity: 0; }
                to   { opacity: 1; }
            }
            /* transform removed from pageIn: transform on <main> creates a stacking
               context that buries nav dropdown panels even when nav has z-40. */
            .page-content { animation: pageIn 0.25s ease-out; }
            /* Card hover lift */
            .card-lift { transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
            .card-lift:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(139,92,246,0.12); }
            /* Tooltip */
            [data-tooltip] { position: relative; }
            [data-tooltip]::after {
                content: attr(data-tooltip);
                position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
                background: #111827; color: #f3f4f6; font-size: 0.75rem;
                padding: 4px 8px; border-radius: 6px; white-space: nowrap;
                opacity: 0; pointer-events: none; transition: opacity 0.15s;
                border: 1px solid #374151;
            }
            [data-tooltip]:hover::after { opacity: 1; }
            /* Progress bar animations */
            .progress-fill { transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
            /* Mobile nav slide */
            @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
            .mobile-menu-open { animation: slideDown 0.2s ease-out; }
        </style>
    </head>
    <body class="font-sans antialiased bg-[#0f1117] text-gray-100">
        <!-- Skip to main content (accessibility) -->
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-purple-600 focus:text-white focus:rounded-lg focus:font-semibold">
            Skip to main content
        </a>

        {{-- M-13: Impersonation banner — shown when a super-admin is logged in as another user --}}
        @php
            $impersonationService = app(\App\Services\ImpersonationService::class);
            $isImpersonating = $impersonationService->isImpersonating();
        @endphp
        @if($isImpersonating)
            @php $impersonatedUser = auth()->user(); @endphp
            <div class="bg-amber-600 text-black px-4 py-2 flex items-center justify-between gap-4 sticky top-0 z-50">
                <div class="flex items-center gap-2 text-sm font-medium">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    You are viewing the site as <strong>{{ $impersonatedUser?->name }}</strong> ({{ $impersonatedUser?->email }}).
                    All actions are logged.
                </div>
                <form method="POST" action="{{ route('super.stop-impersonating') }}">
                    @csrf
                    <button type="submit"
                            class="bg-black/20 hover:bg-black/30 px-3 py-1 rounded-lg text-xs font-semibold transition">
                        ← Return to admin
                    </button>
                </form>
            </div>
        @endif

        <div class="min-h-screen bg-[#0f1117]">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-gray-800/40 border-b border-gray-700/40">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            {{-- (Task H10 / audit H40) — every admin page needs an <h1> for
                 screen-reader navigation. Pages that don't provide a $header
                 slot get a visually-hidden h1 with the page title. --}}
            @empty($header)
                <h1 class="sr-only">{{ $pageTitle ?? 'Dashboard' }}</h1>
            @endempty

            <!-- Page Content -->
            <main id="main-content" class="page-content">
                {{ $slot }}
            </main>
        </div>

        <!-- Cookie Banner -->
        @include('layouts.partials.cookie-banner')

        <!-- Toast notification system -->
        <div id="toast-container" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none" aria-live="polite"></div>

        <script>
        // Global toast utility
        window.toast = function(message, type = 'success') {
            const colors = { success: 'bg-gray-900 border-green-500/40', error: 'bg-gray-900 border-red-500/40', info: 'bg-gray-900 border-gray-600' };
            const icons  = {
                success: '<svg class="w-4 h-4 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>',
                error:   '<svg class="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>',
                info:    '<svg class="w-4 h-4 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            };
            const el = document.createElement('div');
            // A11Y-5: Error toasts use role=alert (assertive), others use role=status (polite)
            el.setAttribute('role', type === 'error' ? 'alert' : 'status');
            el.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border text-sm font-medium text-gray-100 shadow-2xl backdrop-blur-sm ${colors[type]} transition-all duration-300 translate-y-2 opacity-0 min-w-[260px] max-w-sm`;
            el.innerHTML = `${icons[type]}<span class="flex-1">${message}</span>`;
            document.getElementById('toast-container').appendChild(el);
            requestAnimationFrame(() => { el.classList.remove('translate-y-2','opacity-0'); });
            setTimeout(() => {
                el.classList.add('translate-y-2','opacity-0');
                setTimeout(() => el.remove(), 300);
            }, 3500);
        };
        // Auto-toast Laravel flash messages
        @if(session('success')) toast("{{ session('success') }}", 'success'); @endif
        @if(session('error'))   toast("{{ session('error') }}", 'error'); @endif
        @if(session('info'))    toast("{{ session('info') }}", 'info'); @endif
        @if(session('status'))  toast("{{ session('status') }}", 'success'); @endif
        @if(session('warning')) toast("{{ session('warning') }}", 'error'); @endif

        // Keyboard shortcut: G+D = dashboard, G+G = galleries
        let lastKey = null, lastTime = 0;
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            const now = Date.now();
            if (e.key === 'g' && lastKey === 'g' && now - lastTime < 600) {
                window.location.href = '{{ route('admin.dashboard') }}';
                return;
            }
            if (lastKey === 'g' && now - lastTime < 600) {
                if (e.key === 'd') { window.location.href = '{{ route('admin.dashboard') }}'; return; }
                if (e.key === 'l') { window.location.href = '{{ route('admin.galleries.index') }}'; return; }
                if (e.key === 'n') { window.location.href = '{{ route('admin.galleries.create') }}'; return; }
            }
            lastKey = e.key; lastTime = now;
        });
        </script>
        <script>
        function openModal(id)  { const m=document.getElementById(id); m.style.display='flex'; m.classList.add('flex'); }
        function closeModal(id) { const m=document.getElementById(id); m.style.display='none'; m.classList.remove('flex'); }
        // close on backdrop click
        document.addEventListener('DOMContentLoaded',()=>{
            document.querySelectorAll('[role="dialog"]').forEach(m=>{
                m.addEventListener('click', e=>{ if(e.target===m) closeModal(m.id); });
            });
            document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('[role="dialog"]').forEach(m=>closeModal(m.id)); });
        });
        // alias for existing calls
        function showUpgradeModal(){ openModal('upgrade-modal'); }
        </script>
    </body>
</html>