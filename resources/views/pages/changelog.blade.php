@extends('layouts.public')

@section('title', 'Changelog — Exospace 3D Gallery')
@section('description', 'See what\'s new in Exospace — feature releases, improvements, and bug fixes.')

@section('content')
<div style="max-width: 800px; margin: 0 auto; padding: 4rem 1.5rem;">

    {{-- Header --}}
    <div style="text-align: center; margin-bottom: 3rem;">
        <p style="font-size: 0.75rem; color: #a78bfa; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Changelog</p>
        <h1 style="font-size: 2.5rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem;">What's New</h1>
        <p style="font-size: 1rem; color: #94a3b8;">New features, improvements, and fixes — shipped regularly.</p>
    </div>

    {{-- Timeline --}}
    <div style="position: relative; padding-left: 2rem;">
        {{-- Vertical line --}}
        <div style="position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: linear-gradient(180deg, rgba(139,92,246,0.3) 0%, rgba(139,92,246,0.05) 100%);"></div>

        @foreach($releases as $release)
        <div style="position: relative; margin-bottom: 3rem;">
            {{-- Dot --}}
            <div style="position: absolute; left: -2rem; top: 0.25rem; width: 16px; height: 16px; border-radius: 50%; background: #8b5cf6; border: 3px solid #0f1117; box-shadow: 0 0 12px rgba(139,92,246,0.4);"></div>

            {{-- Version + date --}}
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
                <span style="font-size: 0.85rem; font-weight: 700; color: #a78bfa; background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.3); padding: 2px 10px; border-radius: 999px;">{{ $release['version'] }}</span>
                <span style="font-size: 0.8rem; color: #64748b;">{{ \Carbon\Carbon::parse($release['date'])->format('M j, Y') }}</span>
            </div>

            {{-- Title --}}
            <h2 style="font-size: 1.4rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.75rem;">{{ $release['title'] }}</h2>

            {{-- Highlights badges --}}
            @if(!empty($release['highlights']))
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">
                @foreach($release['highlights'] as $highlight)
                    <span style="font-size: 0.72rem; font-weight: 600; color: #6ee7b7; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); padding: 3px 10px; border-radius: 6px;">
                        ✦ {{ $highlight }}
                    </span>
                @endforeach
            </div>
            @endif

            {{-- Features --}}
            @if(!empty($release['features']))
            <div style="margin-bottom: 1rem;">
                <h3 style="font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">🚀 New Features</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    @foreach($release['features'] as $feature)
                    <li style="font-size: 0.88rem; color: #cbd5e1; line-height: 1.7; padding-left: 1.25rem; position: relative; margin-bottom: 0.25rem;">
                        <span style="position: absolute; left: 0; color: #8b5cf6;">+</span>
                        {{ $feature }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Improvements --}}
            @if(!empty($release['improvements']))
            <div style="margin-bottom: 1rem;">
                <h3 style="font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">⚡ Improvements</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    @foreach($release['improvements'] as $improvement)
                    <li style="font-size: 0.88rem; color: #cbd5e1; line-height: 1.7; padding-left: 1.25rem; position: relative; margin-bottom: 0.25rem;">
                        <span style="position: absolute; left: 0; color: #3b82f6;">↑</span>
                        {{ $improvement }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Fixes --}}
            @if(!empty($release['fixes']))
            <div style="margin-bottom: 0.5rem;">
                <h3 style="font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">🐛 Bug Fixes</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    @foreach($release['fixes'] as $fix)
                    <li style="font-size: 0.88rem; color: #cbd5e1; line-height: 1.7; padding-left: 1.25rem; position: relative; margin-bottom: 0.25rem;">
                        <span style="position: absolute; left: 0; color: #10b981;">✓</span>
                        {{ $fix }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
        @endforeach
    </div>

    {{-- RSS hint --}}
    <div style="text-align: center; margin-top: 2rem;">
        <p style="font-size: 0.8rem; color: #64748b;">
            Want updates? <a href="/feed.xml" style="color: #8b5cf6; text-decoration: underline;">Subscribe to our RSS feed</a> or follow us on social media.
        </p>
    </div>
</div>
@endsection
