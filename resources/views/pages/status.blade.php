@extends('layouts.public')

@section('title', 'System Status — Exospace')
@section('description', 'Real-time system status for Exospace 3D Gallery.')

@section('content')
<div class="max-w-3xl mx-auto px-6 py-16">

    {{-- Header --}}
    {{-- ITERATION-4: fully inline-styled markup rewritten on the shared kit —
         .status language for the banner, .card + <x-status-badge> for the
         subsystem rows, utilities elsewhere. The page-local JS hover hack for
         the back link is replaced by a hover: utility (and it was
         DOMContentLoaded-bound, so it silently died after Turbo visits). --}}
    <div class="text-center mb-12">
        @if($allHealthy)
            <div class="status status-healthy mb-6">
                <span class="status-dot"></span>
                All Systems Operational
            </div>
        @else
            <div class="status status-warning mb-6">
                <span class="status-dot"></span>
                Partial Degradation
            </div>
        @endif

        <h1 class="text-4xl font-extrabold text-gray-100 mb-2">System Status</h1>
        <p class="text-base text-gray-400">Exospace Gallery — real-time service health</p>
        <p class="text-xs text-gray-500 mt-4">
            Last checked: {{ \Carbon\Carbon::parse($checkedAt)->diffForHumans() }} (cached for 60 seconds)
        </p>
    </div>

    {{-- Subsystem cards --}}
    <div class="grid gap-4">
        @php
            $labels = [
                'database' => 'Database',
                'cache'    => 'Cache (Redis)',
                'queue'    => 'Queue (Jobs)',
                'storage'  => 'File Storage',
            ];
            $icons = [
                'database' => 'M4 7V4a1 1 0 011-1h14a1 1 0 011 1v3M4 7v10a1 1 0 001 1h14a1 1 0 001-1V7M4 7h16M8 11h8M8 15h8',
                'cache'    => 'M13 10V3L4 14h7v7l9-11h-7z',
                'queue'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
                'storage'  => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
            ];
            // ITERATION-4: the hex $statusStyles map (and whole-card color
            // tinting) is replaced by the shared status vocabulary from
            // iteration 2 — dot + word chips, never color alone.
            $statusMap = [
                'operational' => ['state' => 'healthy',  'label' => 'Operational'],
                'degraded'    => ['state' => 'warning',  'label' => 'Degraded'],
                'down'        => ['state' => 'critical', 'label' => 'Down'],
            ];
        @endphp

        @foreach($checks as $key => $status)
            @php $st = $statusMap[$status] ?? $statusMap['down']; @endphp
            <div class="card flex items-center justify-between px-6 py-5">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$key] ?? '' }}"/></svg>
                    <span class="text-base font-semibold text-gray-200">{{ $labels[$key] ?? ucfirst($key) }}</span>
                </div>
                <x-status-badge :state="$st['state']" :label="$st['label']" />
            </div>
        @endforeach

    </div>

    {{-- Info --}}
    <div class="card mt-12 p-6">
        <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">About This Page</h3>
        <p class="text-[13px] text-gray-500 leading-relaxed">
            This page shows the real-time health of Exospace's core subsystems. The status is cached for 60 seconds — refresh the page to get the latest check.
            For programmatic monitoring, use the <a href="/health" class="text-brand-400 underline">JSON health endpoint</a>.
            If you're experiencing issues not reflected here, please <a href="/contact" class="text-brand-400 underline">contact support</a>.
        </p>
    </div>

    {{-- Back link --}}
    <div class="text-center mt-8">
        <a href="/" class="text-sm text-gray-500 hover:text-brand-400 transition-colors">
            ← Back to Exospace
        </a>
    </div>
</div>
@endsection