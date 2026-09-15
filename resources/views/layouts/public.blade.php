<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        // SEO OS: controllers may pass a SeoData value object.
        $seoData = $seoData ?? null;
    @endphp

    {{-- SEO meta tags --}}
    @include('partials.seo-head', ['seoData' => $seoData ?? null])

    @if(!empty($preloadImage))
    <link rel="preload" as="image" href="{{ $preloadImage }}">
    @endif

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Vite-built CSS + JS --}}
    @include('layouts.partials.monitoring-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Favicon + theme color + PWA manifest --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#0f1117">

    <link rel="alternate" type="application/rss+xml" title="Exospace — Featured 3D Exhibitions" href="{{ url('/feed.xml') }}">

</head>
<body class="font-sans antialiased bg-ink-900 text-gray-100 min-h-screen flex flex-col">
    {{-- Skip to content (accessibility) --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:font-semibold">
        Skip to main content
    </a>

    {{-- Public nav (simplified version of the admin nav) --}}
    <nav x-data="{ mobileMenuOpen: false }" class="border-b border-gray-800/60 bg-ink-900/95 backdrop-blur sticky top-0 z-40" aria-label="Main navigation">
        <div class="max-w-page mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="/" class="logo-text text-xl">
                        Exospace
                    </a>
                    <div class="hidden md:flex items-center gap-6">
                        <a href="/#features" @if(request()->fullUrlIs(url('/')))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Features</a>
                        <a href="{{ route('discover') }}" @if(request()->routeIs('discover.*'))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Discover</a>
                        <a href="{{ route('artists.index') }}" @if(request()->routeIs('artists.index.*'))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Artists</a>
                        <a href="{{ route('venues.index') }}" @if(request()->routeIs('venues.index.*'))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Venues</a>
                        <a href="{{ route('pricing') }}" @if(request()->routeIs('pricing.*'))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Pricing</a>
                        <a href="{{ route('contact') }}" @if(request()->routeIs('contact.*'))aria-current="page"@endif class="text-sm text-gray-300 hover:text-white transition">Contact</a>
                    </div>
                </div>
                <div class="hidden md:flex items-center gap-4">
                    @auth
                        <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-300 hover:text-white transition">Dashboard</a>
                        <a href="{{ route('billing.index') }}" class="text-sm text-gray-300 hover:text-white transition">Billing</a>
                        <form method="POST" action="{{ route('logout') }}" class="inline"
                              data-turbo="false" data-busy data-busy-label="Signing out…">
                            @csrf
                            <button type="submit" class="text-sm text-gray-300 hover:text-white transition">Log out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white transition">Log in</a>
                        <a href="{{ route('register') }}" class="btn btn-primary">
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
        <div x-show="mobileMenuOpen" x-cloak style="display: none;" id="mobile-public-nav" class="md:hidden border-t border-gray-800 bg-ink-900">
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
                    <form method="POST" action="{{ route('logout') }}" class="inline"
                          data-turbo="false" data-busy data-busy-label="Signing out…">
                        @csrf
                        <button type="submit" class="block py-2 text-sm text-gray-300 hover:text-white">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="block py-2 text-sm text-gray-300 hover:text-white">Log in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm w-full justify-center">Get Started</a>
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

    {{-- Unified toast component. --}}
    <x-toast />

    <script nonce="@nonce">
    if (!window.__exospacePublicDelegatesInit) {
        window.__exospacePublicDelegatesInit = true;

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
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // SW registration failed — silent no-op
            });
        });
    }
    </script>
</body>
</html>
