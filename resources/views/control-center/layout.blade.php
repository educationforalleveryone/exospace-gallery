{{-- Control Center layout — intentionally self-contained (no app nav coupling).
    CSP-safe: no inline JS; polling via meta refresh on live pages only. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Testing Control Center') · Exospace</title>
    {{-- ITERATION-1: same Inter webfont as the rest of the product. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans min-h-screen bg-slate-950 text-slate-100 antialiased">
<!-- ITERATION-9: skip link — parity with the app/public layouts -->
<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:font-semibold">
    Skip to content
</a>
<header class="sticky top-0 z-40 border-b border-slate-800 bg-slate-900/60 backdrop-blur">
    <div class="mx-auto flex max-w-page items-center justify-between px-4 sm:px-6 lg:px-8 py-4">
        <a href="{{ route('control-center.overview') }}" class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-lg bg-brand-600 font-black">Q</span>
            <div>
                <div class="font-semibold tracking-wide">TESTING CONTROL CENTER</div>
                <div class="text-xs text-slate-500">Exospace · Release Operations</div>
            </div>
        </a>
        <nav class="flex flex-wrap items-center gap-2 text-sm" aria-label="Control Center sections">
            <a href="{{ route('control-center.overview') }}"
               class="rounded-md px-3 py-1.5 transition-colors duration-150 {{ request()->routeIs('control-center.overview') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white' }}">Overview</a>
            <a href="{{ route('control-center.flaky') }}"
               class="rounded-md px-3 py-1.5 transition-colors duration-150 {{ request()->routeIs('control-center.flaky') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white' }}">Reliability</a>
            <a href="{{ route('control-center.runs') }}"
               class="rounded-md px-3 py-1.5 transition-colors duration-150 {{ request()->routeIs('control-center.runs','control-center.run.show') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white' }}">Runs</a>
            <a href="/" class="btn btn-sm btn-ops-ghost">← App</a>
        </nav>
    </div>
</header>

<main id="main-content" class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
    @if (session('info'))
        <div class="mb-4 rounded-lg border border-sky-800 bg-sky-950/60 px-4 py-3 text-sm text-sky-200">{{ session('info') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-lg border border-amber-800 bg-amber-950/60 px-4 py-3 text-sm text-amber-200">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
    @endif

    @yield('content')
</main>

<footer class="mx-auto max-w-page px-4 sm:px-6 lg:px-8 pb-10 text-xs text-slate-600">
    Production safety is enforced at every layer: destructive suites can never target exospace.gallery.
</footer>
</body>
</html>
