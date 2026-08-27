<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') — OpsCenter</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen antialiased">

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<header class="bg-slate-900/80 border-b border-slate-800 backdrop-blur sticky top-0 z-20">
    <div class="max-w-7xl mx-auto px-6 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-cyan-600 flex items-center justify-center font-bold text-slate-950">O</div>
            <div>
                <div class="font-semibold leading-tight">OpsCenter</div>
                <div class="text-[11px] text-slate-400 leading-tight">Operations Control Plane</div>
            </div>
        </div>
        <nav class="flex flex-wrap items-center gap-1 text-sm">
            <a href="{{ route('ops.overview') }}"      class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.overview') ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Overview</a>
            <a href="{{ route('ops.applications') }}"  class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.applications') ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Applications</a>
            <a href="{{ route('ops.incidents.index') }}" class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.incidents*') ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Incidents</a>
            <a href="{{ route('ops.events') }}"        class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.events*') ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Errors &amp; Events</a>
            <a href="{{ route('ops.diagnostics.index') }}" class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.diagnostics*') ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Diagnostics</a>
            {{-- Iteration 5: operator-only surfaces — viewers get 403 at the route level; the nav simply never shows the doors. --}}
            @if(auth()->user()?->is_super_admin)
                <a href="{{ route('ops.actions.index') }}" class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.actions*') ? 'bg-amber-600/20 text-amber-300 border border-amber-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Actions</a>
                <a href="{{ route('ops.credentials.index') }}" class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.credentials*') ? 'bg-sky-600/20 text-sky-300 border border-sky-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Credentials</a>
                <a href="{{ route('ops.access.index') }}" class="px-3 py-1.5 rounded-md {{ request()->routeIs('ops.access*') ? 'bg-indigo-600/20 text-indigo-300 border border-indigo-700/40' : 'text-slate-300 hover:bg-slate-800' }}">Access</a>
            @endif
        </nav>
        <div class="flex items-center gap-2">
            @unless(auth()->user()?->is_super_admin)
                @if(\App\Ops\Support\OpsAccessContext::level(auth()->user()) === 'operator')
                    <span class="text-[9px] px-1.5 py-0.5 rounded bg-amber-950/60 text-amber-300 border border-amber-700/40 font-bold" title="Operator access — you can view everything and run the read-only diagnostics. Lifecycle actions, the Actions hub, credentials and access management remain super-admin-only.">OPERATOR</span>
                @else
                    <span class="text-[9px] px-1.5 py-0.5 rounded bg-cyan-950/60 text-cyan-300 border border-cyan-700/40 font-bold" title="Read-only access — lifecycle actions, diagnostics runs, actions, credentials and access management are super-admin-only">VIEWER</span>
                @endif
            @endunless
            @if(auth()->user()?->is_super_admin)
                <a href="{{ route('super.index') }}" class="px-3 py-1.5 text-sm rounded-md text-slate-300 hover:bg-slate-800">Master Control</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="px-3 py-1.5 text-sm rounded-md bg-slate-800 hover:bg-slate-700 text-slate-300">Sign out</button>
            </form>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-6">
    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-700/50 bg-emerald-950/40 px-4 py-3 text-emerald-300 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-700/50 bg-red-950/40 px-4 py-3 text-red-300 text-sm">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>

<footer class="max-w-7xl mx-auto px-6 py-8 text-[11px] text-slate-600 border-t border-slate-800/60 mt-8">
    OpsCenter aggregates Coolify, Docker/Laravel logs, Sentry-side errors, backups, queues and health checks.
    Coolify remains the deployment plane. — <span class="font-mono">docs/OPS_DISCOVERY_AUDIT.md</span>
</footer>
</body>
</html>
