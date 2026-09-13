<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="robots" content="noindex,nofollow">

        <title>{{ config('app.name', 'Exospace') }} — {{ isset($pageTitle) ? $pageTitle : 'Dashboard' }}</title>

        <!-- Fonts: Inter for body, display weight for headings -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

    </head>
    <body class="font-sans antialiased bg-ink-900 text-gray-100">
        <!-- Skip to main content (accessibility) -->
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:font-semibold">
            Skip to main content
        </a>

        @php
            $impersonationService = app(\App\Services\ImpersonationService::class);
            $isImpersonating = $impersonationService->isImpersonating();
        @endphp
        @if($isImpersonating)
            @php $impersonatedUser = auth()->user(); @endphp
            <div class="bg-amber-600 text-black px-4 py-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 sticky top-0 z-40">
                <div class="flex items-center gap-2 text-sm font-medium min-w-0">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span class="min-w-0">You are viewing the site as <strong class="break-words">{{ $impersonatedUser?->name }}</strong> <span class="break-all">({{ $impersonatedUser?->email }})</span>.
                    All actions are logged.</span>
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

        <div class="min-h-screen bg-ink-900">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-ink-800/60 border-b border-gray-800">
                    <div class="max-w-page mx-auto py-5 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

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

        {{-- Unified toast component. --}}
        <x-toast />

        <script nonce="@nonce">
        if (!window.__exospaceShortcutsInit) {
            window.__exospaceShortcutsInit = true;
            (function() {
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
            })();
        }
        </script>
        <script nonce="@nonce">
        function showUpgradeModal(){ openModal('upgrade-modal'); }
        </script>
        <script nonce="@nonce">
        if (!window.__exospaceDelegatesInit) {
            window.__exospaceDelegatesInit = true;

            document.addEventListener('click', (e) => {
                const el = e.target.closest('[data-logout-link]');
                if (!el) return;
                e.preventDefault();
                const form = el.closest('form');
                if (!form) return;
                if (form.__exospaceBusy) return;
                window.exospaceGuardForm(form);
                form.submit();
            });

            document.addEventListener('submit', (e) => {
                const form = e.target.closest?.('form[data-confirm]');
                if (!form || form.__exospaceConfirming) return;
                e.preventDefault();
                form.__exospaceConfirming = true;
                window.exospaceConfirm(e, form.getAttribute('data-confirm')).finally(() => {
                    form.__exospaceConfirming = false;
                });
            });

            document.addEventListener('click', (e) => {
                const el = e.target.closest('[data-confirm-click]');
                if (!el || el.__exospaceConfirming) return;
                e.preventDefault();
                el.__exospaceConfirming = true;
                window.exospaceConfirm(e, el.getAttribute('data-confirm-click')).then((ok) => {
                    el.__exospaceConfirming = false;
                    if (!ok) return;
                    const form = el.closest('form');
                    if (form) { window.exospaceGuardForm(form); form.submit(); }
                    else if (el.matches('a[href]')) window.location.href = el.getAttribute('href');
                });
            });

            const delegate = (eventName, attr) => {
                document.addEventListener(eventName, (e) => {
                    const el = e.target.closest(`[${attr}]`);
                    if (!el) return;
                    const fn = window[el.getAttribute(attr)];
                    if (typeof fn !== 'function') return;
                    if (el.dataset.args) {
                        try {
                            const args = JSON.parse(el.dataset.args);
                            fn.call(el, ...args, e);
                        } catch (err) {
                            console.warn('[data-action] invalid JSON args:', el.dataset.args, err);
                        }
                    } else if (el.dataset.arg !== undefined) {
                        fn.call(el, el.dataset.arg, e);
                    } else {
                        fn.call(el, el, e);
                    }
                });
            };
            delegate('click', 'data-click');
            delegate('change', 'data-change');
            delegate('input', 'data-input');
            delegate('submit', 'data-submit');

            document.addEventListener('error', (e) => {
                const el = e.target;
                if (el?.matches?.('[data-onerror-hide]')) el.style.display = 'none';
            }, true);
        }
        </script>
        {{-- In-app feedback widget (floating button on all admin pages) --}}
        @include('components.feedback-widget')

        {{-- ⌘K command palette. --}}
        @if(\App\Services\FeatureFlag::isEnabled('command_palette'))
            <x-command-palette />
        @endif
    </body>
</html>