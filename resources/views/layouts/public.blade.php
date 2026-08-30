{{--
    Public layout for marketing / legal / discovery pages.

    (Task H09 / audit H34, H38, H39) — replaces the standalone HTML
    documents that used cdn.tailwindcss.com (a development-only CDN
    that ships a ~300 KB runtime compiler and has no SRI). Now all
    public pages use the same Vite-built CSS as the admin app, plus
    a shared nav + footer + SEO head.

    Usage (string mode — static pages):
        @extends('layouts.public')
        @section('title', 'Pricing — Exospace')
        @section('description', '...')
        @section('content')
            ... page content ...
        @endsection

    Usage (SEO OS mode — controllers build a SeoData object):
        return view('discover.index', ['seoData' => $seo, ...]);
    The <x-seo> component renders from the object when present; string
    sections act as the fallback. Controllers should prefer SeoData —
    it carries robots directives, prev/next, image metadata and JSON-LD.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        // SEO OS (Iteration 1): controllers may pass a SeoData value object.
        $seoData = $seoData ?? null;
    @endphp

    {{-- SEO meta tags (Task H13 → SEO OS v2) --}}
    {{-- ITERATION-1 FIX: when no section defines a canonical (pages built
         on the SeoData path don't define @section('canonical')), the empty
         string fell through as a literal ATTRIBUTE VALUE — the component
         received canonical-url="" as text, not a missing key. The isset
         guard on the component reads it correctly now, but emitting an
         empty attribute also leaked into output as data noise. Use @php to
         build the prop only when a section value exists. --}}
    {{-- ITERATION-1 FIX: the interpolated attribute trick
         ({{ $seoProps ? ... : '' }}) renders an EMPTY ATTRIBUTE LIST as
         literal whitespace, and more importantly the x-seo component
         received NO output at all when sections were defined but the
         component lacked @props — the whole tag silently rendered as
         nothing. Pass explicit props; the component now declares @props
         and emits a full meta layer including <title>. --}}
    {{-- ITERATION-1 FIX (SEO meta never rendered on public pages):
         (1) @yield() inside a component attribute passes a literal string,
         not the section content. (2) view()->yieldContent() during the
         layout render returns empty for sections defined by the child view
         (Blade injects section content later, at @yield points in the
         layout flow). The reliable pattern: render the meta layer with a
         plain @include that itself uses @yield at its own position in the
         layout, where section injection is already active. --}}
    @include('partials.seo-head', ['seoData' => $seoData ?? null])

    {{-- SEO OS (Iteration 7): LCP image preload hint from controllers
         (artwork page preloads its main image; artist page preloads the
         portrait) via $preloadImage. --}}
    @if(!empty($preloadImage))
    <link rel="preload" as="image" href="{{ $preloadImage }}">
    @endif

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Vite-built CSS + JS — replaces cdn.tailwindcss.com (Task H09) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Favicon + theme color + PWA manifest (Task H23) --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#0f1117">

    {{-- I-6 FIX (Iter-013): RSS auto-discovery link.
        RSS readers (Feedly, Inoreader, NetNewsWire) look for this <link>
        tag in the page <head> to auto-discover the feed. Without it, users
        have to manually paste the /feed.xml URL. The feed exists at
        /feed.xml (SitemapController::feed) but readers couldn't find it. --}}
    <link rel="alternate" type="application/rss+xml" title="Exospace — Featured 3D Exhibitions" href="{{ url('/feed.xml') }}">

    {{-- ITERATION-1: global focus ring, reduced-motion and smooth-scroll now live
         in resources/css/app.css (loaded below) so every layout shares them. --}}

</head>
<body class="font-sans antialiased bg-ink-900 text-gray-100 min-h-screen flex flex-col">
    {{-- Skip to content (accessibility) --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:font-semibold">
        Skip to main content
    </a>

    {{-- Public nav (simplified version of the admin nav) --}}
    <nav x-data="{ mobileMenuOpen: false }" class="border-b border-gray-800/60 bg-ink-900/95 backdrop-blur sticky top-0 z-40" aria-label="Main navigation">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="/" class="logo-text text-xl">
                        Exospace
                    </a>
                    <div class="hidden md:flex items-center gap-6">
                        <a href="/#features" class="text-sm text-gray-300 hover:text-white transition">Features</a>
                        <a href="{{ route('discover') }}" class="text-sm text-gray-300 hover:text-white transition">Discover</a>
                        <a href="{{ route('artists.index') }}" class="text-sm text-gray-300 hover:text-white transition">Artists</a>
                        <a href="{{ route('venues.index') }}" class="text-sm text-gray-300 hover:text-white transition">Venues</a>
                        <a href="{{ route('pricing') }}" class="text-sm text-gray-300 hover:text-white transition">Pricing</a>
                        <a href="{{ route('contact') }}" class="text-sm text-gray-300 hover:text-white transition">Contact</a>
                    </div>
                </div>
                <div class="hidden md:flex items-center gap-4">
                    @auth
                        <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-300 hover:text-white transition">Dashboard</a>
                        <a href="{{ route('billing.index') }}" class="text-sm text-gray-300 hover:text-white transition">Billing</a>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-sm text-gray-300 hover:text-white transition">Log out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white transition">Log in</a>
                        <a href="{{ route('register') }}" class="text-sm bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-semibold px-4 py-2 rounded-lg transition">
                            Get Started
                        </a>
                    @endauth
                </div>
                {{-- Mobile menu button --}}
                <button @click="mobileMenuOpen = !mobileMenuOpen"
                        class="md:hidden text-gray-400 hover:text-white"
                        :aria-expanded="mobileMenuOpen"
                        aria-controls="mobile-public-nav"
                        aria-label="Toggle menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        {{-- Mobile menu --}}
        <div x-show="mobileMenuOpen" x-cloak id="mobile-public-nav" class="md:hidden border-t border-gray-800 bg-ink-900">
            <div class="px-4 py-3 space-y-2">
                <a href="/#features" class="block py-2 text-sm text-gray-300 hover:text-white">Features</a>
                <a href="{{ route('discover') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Discover</a>
                <a href="{{ route('artists.index') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Artists</a>
                <a href="{{ route('venues.index') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Venues</a>
                <a href="{{ route('pricing') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Pricing</a>
                <a href="{{ route('contact') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Contact</a>
                <hr class="border-gray-800 my-2">
                @auth
                    <a href="{{ route('admin.dashboard') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Dashboard</a>
                    <a href="{{ route('billing.index') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Billing</a>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="block py-2 text-sm text-gray-300 hover:text-white">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Log in</a>
                    <a href="{{ route('register') }}" class="block py-2 text-sm text-purple-400 hover:text-purple-300">Get Started</a>
                @endauth
            </div>
        </div>
    </nav>

    {{-- Page content --}}
    <main id="main-content" class="flex-1">
        @yield('content')
    </main>

    {{-- Footer --}}
    @include('layouts.partials.footer')

    {{-- Cookie banner --}}
    @include('layouts.partials.cookie-banner')

    {{-- ITERATION-2 (AUDIT-P1-2.5): Unified toast component. --}}
    {{-- Previously the toast container + window.toast() function were
         inlined in BOTH app.blade.php and public.blade.php — the two
         copies had drifted. Now this is the single source of truth. --}}
    <x-toast />

    <script nonce="@nonce">
    function openModal(id)  { const m=document.getElementById(id); m.style.display='flex'; m.classList.add('flex');
        // A11Y-7: Focus the first focusable element in the modal
        const focusable = m.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable.length) focusable[0].focus();
        m._focusTrap = (e) => {
            if (e.key !== 'Tab') return;
            const f = m.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (f.length === 0) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        };
        m.addEventListener('keydown', m._focusTrap);
    }
    function closeModal(id) { const m=document.getElementById(id); m.style.display='none'; m.classList.remove('flex');
        if (m._focusTrap) { m.removeEventListener('keydown', m._focusTrap); }
    }
    document.addEventListener('DOMContentLoaded',()=>{
        document.querySelectorAll('[role="dialog"]').forEach(m=>{
            m.addEventListener('click', e=>{ if(e.target===m) closeModal(m.id); });
        });
        document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('[role="dialog"]').forEach(m=>closeModal(m.id)); });
    });

    // (Task H46 / audit MX5) — Register the PWA service worker for offline
    // gallery caching. Progressive enhancement — if registration fails,
    // the site works normally online.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // SW registration failed — silent no-op
            });
        });
    }

    // ── CSP-safe image fallback ────────────────────────────────────────────
    // Replaces inline `onerror="this.style.display='none'"` handlers that
    // CSP blocks. Any <img> tagged with class `venue-thumb-img` (or any
    // img carrying `data-fallback-hide`) that fails to load is hidden so
    // the CSS gradient / placeholder sibling shows through.
    document.addEventListener('DOMContentLoaded', () => {
        const hide = (img) => {
            img.style.visibility = 'hidden';
            img.setAttribute('aria-hidden', 'true');
        };
        document.querySelectorAll('img.venue-thumb-img, img[data-fallback-hide]').forEach(img => {
            if (img.complete && img.naturalWidth === 0) hide(img);
            img.addEventListener('error', () => hide(img));
        });
    });

    // ── CSP-safe delegated action handlers (mirrors layouts/app.blade.php) ──
    // Replaces inline onclick/onchange/oninput/onsubmit with declarative
    // data-* attributes. See layouts/app.blade.php for full docs.
    document.addEventListener('DOMContentLoaded', () => {
        // CSP-safe confirm-on-submit forms
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!window.confirm(form.dataset.confirm)) e.preventDefault();
            });
        });
        // CSP-safe confirm-on-click buttons/links
        document.querySelectorAll('[data-confirm-click]').forEach(el => {
            el.addEventListener('click', (e) => {
                if (!window.confirm(el.dataset.confirmClick)) e.preventDefault();
            });
        });
        // Delegated data-click / data-change / data-input / data-submit
        const delegate = (eventName, attr) => {
            document.addEventListener(eventName, (e) => {
                const el = e.target.closest(`[${attr}]`);
                if (!el) return;
                const fn = window[el.getAttribute(attr)];
                if (typeof fn !== 'function') return;
                if (el.dataset.args) {
                    try { fn.call(el, ...JSON.parse(el.dataset.args), e); }
                    catch (err) { console.warn('[data-action] invalid JSON args:', el.dataset.args, err); }
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
</body>
</html>
