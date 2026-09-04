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
        {{-- ITERATION-3: openModal/closeModal + the global modal system
             (backdrop click, Escape, Tab trap, scroll lock, focus restore)
             moved into resources/js/app.js so the admin and public layouts
             share ONE implementation. `showUpgradeModal` is kept as the
             historical alias used by dashboard CTAs. --}}
        <script nonce="@nonce">
        function showUpgradeModal(){ openModal('upgrade-modal'); }
        </script>
        <script nonce="@nonce">
        // ── CSP-safe global interaction delegates (ITERATION-3) ───────────────
        // CRITICAL FIX: logout links, data-confirm forms and data-confirm-click
        // buttons were previously bound PER-ELEMENT inside DOMContentLoaded.
        // DOMContentLoaded fires once per real page load — Turbo Drive swaps
        // <body> on later navigations without ever firing it again, so on
        // every Turbo-navigated page:
        //   • Sign out submitted via GET → 405 error
        //   • every data-confirm / data-confirm-click guard silently
        //     disappeared → destructive actions ran WITHOUT confirmation.
        // All handlers below are delegated on `document` (which persists
        // across Turbo navigations) inside one-time guards, so they work on
        // the first page AND on every Turbo-swapped page after it.
        //
        // data-confirm / data-confirm-click now route through the styled
        // window.exospaceConfirm() dialog instead of native window.confirm()
        // (one confirm mechanism, one visual language, double-submit guarded).
        if (!window.__exospaceDelegatesInit) {
            window.__exospaceDelegatesInit = true;

            // ── CSP-safe logout links ─────────────────────────────────────
            // <a href="/logout" data-logout-link> inside a <form> submits the
            // form instead of navigating (native GET would 405).
            document.addEventListener('click', (e) => {
                const el = e.target.closest('[data-logout-link]');
                if (!el) return;
                e.preventDefault();
                const form = el.closest('form');
                if (form) form.submit();
            });

            // ── Confirm-on-submit forms ───────────────────────────────────
            // <form data-confirm="Are you sure?">…</form>
            document.addEventListener('submit', (e) => {
                const form = e.target.closest?.('form[data-confirm]');
                if (!form || form.__exospaceConfirming) return;
                e.preventDefault();
                form.__exospaceConfirming = true;
                window.exospaceConfirm(e, form.getAttribute('data-confirm')).finally(() => {
                    form.__exospaceConfirming = false;
                });
            });

            // ── Confirm-on-click buttons/links ────────────────────────────
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

            // ── Delegated action handlers ─────────────────────────────────
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

            // ── CSP-safe image error fallback ───────────────────────────
            // <img data-onerror-hide> hides itself on load failure so a
            // styled fallback element underneath shows through. Replaces
            // inline onerror="this.style.display='none'" (blocked by CSP —
            // event-handler attributes aren't covered by the script nonce).
            // The 'error' event on <img> does NOT bubble, so this must be a
            // CAPTURING listener on the document, not the usual bubble-phase
            // delegate() helper above.
            document.addEventListener('error', (e) => {
                const el = e.target;
                if (el?.matches?.('[data-onerror-hide]')) el.style.display = 'none';
            }, true);
        }
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