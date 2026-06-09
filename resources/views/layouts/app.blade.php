<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Exospace') }} — {{ isset($pageTitle) ? $pageTitle : 'Dashboard' }}</title>

        <!-- Fonts: Inter for body, display weight for headings -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

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
            /* Skeleton shimmer for perceived performance */
            @keyframes shimmer {
                0% { background-position: -200% 0; }
                100% { background-position: 200% 0; }
            }
            .skeleton {
                background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%);
                background-size: 200% 100%;
                animation: shimmer 1.5s infinite;
                border-radius: 0.375rem;
            }
            /* Smooth page-level fade-in */
            @keyframes pageIn {
                from { opacity: 0; transform: translateY(6px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            .page-content { animation: pageIn 0.25s ease-out both; }
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
    <body class="font-sans antialiased bg-gray-900 text-gray-100">
        <!-- Skip to main content (accessibility) -->
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-purple-600 focus:text-white focus:rounded-lg focus:font-semibold">
            Skip to main content
        </a>

        <div class="min-h-screen bg-gray-900">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-gray-800/80 backdrop-blur-sm shadow-lg border-b border-gray-700/60 sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

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
            const colors = { success: 'bg-green-800 border-green-600', error: 'bg-red-900 border-red-700', info: 'bg-gray-800 border-gray-600' };
            const icons  = { success: '✓', error: '✕', info: 'ℹ' };
            const el = document.createElement('div');
            el.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-lg border text-sm font-medium text-white shadow-xl ${colors[type]} transition-all duration-300 translate-y-2 opacity-0`;
            el.innerHTML = `<span class="text-base">${icons[type]}</span><span>${message}</span>`;
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