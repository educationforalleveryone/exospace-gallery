<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- I-1 FIX (Iter-013): noindex,nofollow on all admin/auth pages.
            Covers ~80 admin views (dashboard, galleries, billing, profile,
            teams, super-admin) with one line. Prevents Google from indexing
            authenticated user content (gallery lists, billing history, etc.)
            that may leak through weak auth or session-token-in-URL bugs. --}}
        <meta name="robots" content="noindex,nofollow">

        <title>{{ config('app.name', 'Exospace') }} — {{ isset($pageTitle) ? $pageTitle : 'Dashboard' }}</title>

        <!-- Fonts: Inter for body, display weight for headings -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- ITERATION-1: all global CSS (focus ring, page fade, card-lift, tooltip,
             progress, reduced-motion) moved into resources/css/app.css so every
             layout — guest, public, ops, control-center — inherits the same
             behavior. Nothing layout-specific remains inline here. --}}
    </head>
    <body class="font-sans antialiased bg-ink-900 text-gray-100">
        <!-- Skip to main content (accessibility) -->
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:font-semibold">
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

        {{-- ITERATION-2 (AUDIT-P1-2.5): Unified toast component. --}}
        {{-- Previously the toast container + window.toast() function were
             inlined in BOTH app.blade.php and public.blade.php — the two
             copies had drifted. Now this is the single source of truth. --}}
        <x-toast />

        <script nonce="@nonce">
        // Keyboard shortcut: G+D = dashboard, G+G = galleries
        // FIX (Iter-002): Turbo Drive re-inserts/re-executes this <script>
        // block's contents on every navigation. A top-level `let lastKey` is
        // fine on a real full page load, but on the SECOND Turbo navigation
        // the browser throws "Identifier 'lastKey' has already been declared"
        // because the previous `let` is still alive in this same JS realm —
        // Turbo never reloads the document. That uncaught SyntaxError was
        // aborting Turbo's body-swap mid-flight, which is also why dropdowns,
        // modals, and other page elements appeared to "randomly pop"/freeze
        // after navigating. Wrapping in an IIFE + a one-time guard fixes the
        // redeclaration and stops us from stacking a fresh keydown listener
        // on `document` (which persists across Turbo navigations) every visit.
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
        function openModal(id)  { const m=document.getElementById(id); m.style.display='flex'; m.classList.add('flex'); }
        function closeModal(id) { const m=document.getElementById(id); m.style.display='none'; m.classList.remove('flex'); }
        // close on backdrop click
        // FIX (Iter-002): this used to query [role="dialog"] and bind a click
        // listener directly to each modal found at DOMContentLoaded time.
        // DOMContentLoaded fires once per real page load — Turbo Drive swaps
        // in new pages (with new modals) without ever firing it again, so
        // any modal on a Turbo-navigated page had no backdrop-click or
        // Escape-to-close behavior. Switched to delegated listeners on
        // `document` (bound once, guarded) which work for modals present now
        // or added to any future page.
        if (!window.__exospaceModalHandlersInit) {
            window.__exospaceModalHandlersInit = true;
            document.addEventListener('click', e => {
                const m = e.target.closest('[role="dialog"]');
                if (m && e.target === m) closeModal(m.id);
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') document.querySelectorAll('[role="dialog"]').forEach(m => closeModal(m.id));
            });
        }
        // alias for existing calls
        function showUpgradeModal(){ openModal('upgrade-modal'); }
        </script>
        <script nonce="@nonce">
        // ── CSP-safe image fallback ────────────────────────────────────────────
        // Replaces inline `onerror="this.style.display='none'"` handlers that
        // CSP blocks. Any <img> tagged with class `venue-thumb-img` (or any
        // img carrying `data-fallback-hide`) that fails to load is hidden so
        // the CSS gradient / placeholder sibling shows through.
        // FIX (Iter-002): this used to bind an 'error' listener directly to
        // each matching <img> found at DOMContentLoaded time. DOMContentLoaded
        // only fires once (Turbo Drive swaps <body> on later navigations
        // without reloading the document), so images on every page after the
        // first never got this handler at all — broken thumbnails stayed
        // visible as browser broken-image icons instead of being hidden.
        // 'error' doesn't bubble, so we listen on `document` with
        // capture=true instead: bound once, survives every Turbo navigation,
        // and covers images added at any point in the future.
        if (!window.__exospaceImgFallbackInit) {
            window.__exospaceImgFallbackInit = true;
            const hide = (img) => {
                img.style.visibility = 'hidden';
                img.setAttribute('aria-hidden', 'true');
            };
            const scanForCached404s = () => {
                document.querySelectorAll('img.venue-thumb-img, img[data-fallback-hide]').forEach(img => {
                    // If the browser already tried and failed before our listener
                    // attached (cached 404), check complete/naturalWidth.
                    if (img.complete && img.naturalWidth === 0) hide(img);
                });
            };
            document.addEventListener('error', (e) => {
                const img = e.target;
                if (img.tagName === 'IMG' && img.matches('.venue-thumb-img, [data-fallback-hide]')) hide(img);
            }, true);
            document.addEventListener('turbo:load', scanForCached404s);
            scanForCached404s();
        }

        // ── CSP-safe logout links ─────────────────────────────────────────────
        // The navigation has <a href="/logout" onclick="event.preventDefault();
        //   this.closest('form').submit();">Sign out</a> wrapped in a <form>.
        // CSP blocks the inline onclick. We replace it with a delegated
        // listener: any element with [data-logout-link] inside a <form> will
        // submit that form instead of navigating.
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-logout-link]').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    const form = el.closest('form');
                    if (form) form.submit();
                });
            });

            // ── CSP-safe confirm-on-submit forms ──────────────────────────────
            // Replaces inline onsubmit="return confirm('...')" with a delegated
            // listener. Any form carrying data-confirm="..." will prompt.
            document.querySelectorAll('form[data-confirm]').forEach(form => {
                form.addEventListener('submit', (e) => {
                    if (!window.confirm(form.dataset.confirm)) {
                        e.preventDefault();
                    }
                });
            });

            // ── CSP-safe confirm-on-click buttons/links ───────────────────────
            document.querySelectorAll('[data-confirm-click]').forEach(el => {
                el.addEventListener('click', (e) => {
                    if (!window.confirm(el.dataset.confirmClick)) {
                        e.preventDefault();
                    }
                });
            });

            // ── CSP-safe delegated action handlers ────────────────────────────
            // Replaces inline onclick="fn(arg)" / onchange="fn(this)" /
            // oninput="fn(this, event)" with declarative attributes:
            //
            //   <button data-click="deleteImage" data-arg="42">Delete</button>
            //   <button data-click="dashboardShare" data-args='["https://...", "Title"]'>Share</button>
            //   <input data-change="uploadAudioFile">
            //   <input data-input="syncCurtainColor">
            //
            // The handler resolves window[fn] and calls it.
            //   - data-arg (string):  fn(arg, event)
            //   - data-args (JSON):   fn(...args, event)
            //   - neither:            fn(el, event)
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
        });
        </script>
        {{-- M-19: In-app feedback widget (floating button on all admin pages) --}}
        @include('components.feedback-widget')

        {{-- ITERATION-3 (AUDIT-P1-3.2): ⌘K command palette. --}}
        {{-- Triggered by ⌘K (Mac) or Ctrl+K (Windows/Linux) or "/" when not in
             an input. Progressive enhancement — no impact when JS fails.
             Disable via FEATURE_FLAG_COMMAND_PALETTE=false in .env. --}}
        @if(\App\Services\FeatureFlag::isEnabled('command_palette'))
            <x-command-palette />
        @endif
    </body>
</html>