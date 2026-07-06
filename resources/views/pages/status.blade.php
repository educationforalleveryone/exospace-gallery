@extends('layouts.public')

@section('title', 'System Status — Exospace')
@section('description', 'Real-time system status for Exospace 3D Gallery.')

@section('content')
<div style="max-width: 720px; margin: 0 auto; padding: 4rem 1.5rem;">

    {{-- Header --}}
    <div style="text-align: center; margin-bottom: 3rem;">
        @if($allHealthy)
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 999px; margin-bottom: 1.5rem;">
                <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>
                <span style="font-size: 0.85rem; color: #6ee7b7; font-weight: 600;">All Systems Operational</span>
            </div>
        @else
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 999px; margin-bottom: 1.5rem;">
                <span style="width: 8px; height: 8px; border-radius: 50%; background: #f59e0b;"></span>
                <span style="font-size: 0.85rem; color: #fcd34d; font-weight: 600;">Partial Degradation</span>
            </div>
        @endif

        <h1 style="font-size: 2.5rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem;">System Status</h1>
        <p style="font-size: 1rem; color: #94a3b8;">Exospace Gallery — real-time service health</p>
        <p style="font-size: 0.75rem; color: #64748b; margin-top: 1rem;">
            Last checked: {{ \Carbon\Carbon::parse($checkedAt)->diffForHumans() }} (cached for 60 seconds)
        </p>
    </div>

    {{-- Subsystem cards --}}
    <div style="display: grid; gap: 1rem;">

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
            $statusStyles = [
                'operational' => ['bg' => 'rgba(16, 185, 129, 0.1)', 'border' => 'rgba(16, 185, 129, 0.3)', 'text' => '#6ee7b7', 'label' => 'Operational', 'dot' => '#10b981'],
                'degraded'    => ['bg' => 'rgba(245, 158, 11, 0.1)', 'border' => 'rgba(245, 158, 11, 0.3)', 'text' => '#fcd34d', 'label' => 'Degraded', 'dot' => '#f59e0b'],
                'down'        => ['bg' => 'rgba(239, 68, 68, 0.1)', 'border' => 'rgba(239, 68, 68, 0.3)', 'text' => '#fca5a5', 'label' => 'Down', 'dot' => '#ef4444'],
            ];
        @endphp

        @foreach($checks as $key => $status)
            @php $style = $statusStyles[$status] ?? $statusStyles['down']; @endphp
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; background: {{ $style['bg'] }}; border: 1px solid {{ $style['border'] }}; border-radius: 12px;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <svg style="width: 20px; height: 20px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$key] ?? '' }}"/></svg>
                    <span style="font-size: 0.95rem; font-weight: 600; color: #e2e8f0;">{{ $labels[$key] ?? ucfirst($key) }}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $style['dot'] }};"></span>
                    <span style="font-size: 0.85rem; font-weight: 600; color: {{ $style['text'] }};">{{ $style['label'] }}</span>
                </div>
            </div>
        @endforeach

    </div>

    {{-- Info --}}
    <div style="margin-top: 3rem; padding: 1.5rem; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px;">
        <h3 style="font-size: 0.8rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">About This Page</h3>
        <p style="font-size: 0.82rem; color: #64748b; line-height: 1.7;">
            This page shows the real-time health of Exospace's core subsystems. The status is cached for 60 seconds — refresh the page to get the latest check.
            For programmatic monitoring, use the <a href="/health" style="color: #8b5cf6; text-decoration: underline;">JSON health endpoint</a>.
            If you're experiencing issues not reflected here, please <a href="/contact" style="color: #8b5cf6; text-decoration: underline;">contact support</a>.
        </p>
    </div>

    {{-- Back link --}}
    <div style="text-align: center; margin-top: 2rem;">
        <a href="/" style="font-size: 0.85rem; color: #64748b; text-decoration: none; transition: color 0.2s;"
           onmouseover="this.style.color='#8b5cf6'" onmouseout="this.style.color='#64748b'">
            ← Back to Exospace
        </a>
    </div>
</div>
@endsection
