{{-- Control Center layout — intentionally self-contained (no app nav coupling).
    CSP-safe: no inline JS; polling via meta refresh on live pages only. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Testing Control Center') · Exospace</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-200 antialiased">
<header class="border-b border-slate-800 bg-slate-900/60">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <a href="{{ route('control-center.overview') }}" class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-lg bg-violet-600 font-black">Q</span>
            <div>
                <div class="font-semibold tracking-wide">TESTING CONTROL CENTER</div>
                <div class="text-xs text-slate-500">Exospace · Release Operations</div>
            </div>
        </a>
        <nav class="flex gap-2 text-sm">
            <a href="{{ route('control-center.overview') }}"
               class="rounded-md px-3 py-1.5 {{ request()->routeIs('control-center.overview') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white' }}">Overview</a>
            <a href="{{ route('control-center.runs') }}"
               class="rounded-md px-3 py-1.5 {{ request()->routeIs('control-center.runs','control-center.run.show') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white' }}">Runs</a>
            <a href="/" class="ml-2 rounded-md border border-slate-700 px-3 py-1.5 text-slate-300 hover:border-slate-500">← App</a>
        </nav>
    </div>
</header>

<main class="mx-auto max-w-7xl px-6 py-8">
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

<footer class="mx-auto max-w-7xl px-6 pb-10 text-xs text-slate-600">
    Production safety is enforced at every layer: destructive suites can never target exospace.gallery.
</footer>
</body>
</html>
