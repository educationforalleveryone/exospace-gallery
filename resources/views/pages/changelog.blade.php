@extends('layouts.public')

@section('title', 'Changelog — Exospace 3D Gallery')
@section('description', 'See what\'s new in Exospace — feature releases, improvements, and bug fixes.')

@section('content')
{{-- ITERATION-4: inline-style markup rewritten with utilities + kit badges
     (.badge-brand for versions, .badge-success for highlights — 12px floor
     enforced), token colors for markers, no colored glow shadows. --}}
<div class="max-w-3xl mx-auto px-6 py-16">

    {{-- Header --}}
    <div class="text-center mb-12">
        <p class="text-xs text-brand-400 font-semibold uppercase tracking-wider mb-2">Changelog</p>
        <h1 class="text-4xl font-extrabold text-gray-100 mb-2">What's New</h1>
        <p class="text-base text-gray-400">New features, improvements, and fixes — shipped regularly.</p>
    </div>

    {{-- Timeline --}}
    <div class="relative pl-8">
        {{-- Vertical line --}}
        <div class="absolute left-[7px] top-0 bottom-0 w-0.5 bg-gradient-to-b from-brand-500/30 to-brand-500/5" aria-hidden="true"></div>

        @foreach($releases as $release)
        <div class="relative mb-12">
            {{-- Dot --}}
            <div class="absolute -left-8 top-1 w-4 h-4 rounded-full bg-brand-500 ring-4 ring-ink-900" aria-hidden="true"></div>

            {{-- Version + date --}}
            <div class="flex items-center gap-3 mb-2 flex-wrap">
                <span class="badge badge-brand">{{ $release['version'] }}</span>
                <span class="text-sm text-gray-500">{{ \Carbon\Carbon::parse($release['date'])->format('M j, Y') }}</span>
            </div>

            {{-- Title --}}
            <h2 class="text-2xl font-bold text-gray-100 mb-3">{{ $release['title'] }}</h2>

            {{-- Highlights badges --}}
            @if(!empty($release['highlights']))
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach($release['highlights'] as $highlight)
                    <span class="badge badge-success">✦ {{ $highlight }}</span>
                @endforeach
            </div>
            @endif

            {{-- Features --}}
            @if(!empty($release['features']))
            <div class="mb-4">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">🚀 New Features</h3>
                <ul class="list-none p-0 m-0">
                    @foreach($release['features'] as $feature)
                    <li class="relative text-sm text-gray-300 leading-relaxed pl-5 mb-1">
                        <span class="absolute left-0 text-brand-400" aria-hidden="true">+</span>
                        {{ $feature }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Improvements --}}
            @if(!empty($release['improvements']))
            <div class="mb-4">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">⚡ Improvements</h3>
                <ul class="list-none p-0 m-0">
                    @foreach($release['improvements'] as $improvement)
                    <li class="relative text-sm text-gray-300 leading-relaxed pl-5 mb-1">
                        <span class="absolute left-0 text-blue-400" aria-hidden="true">↑</span>
                        {{ $improvement }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Fixes --}}
            @if(!empty($release['fixes']))
            <div class="mb-2">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">🐛 Bug Fixes</h3>
                <ul class="list-none p-0 m-0">
                    @foreach($release['fixes'] as $fix)
                    <li class="relative text-sm text-gray-300 leading-relaxed pl-5 mb-1">
                        <span class="absolute left-0 text-emerald-400" aria-hidden="true">✓</span>
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
    <div class="text-center mt-8">
        <p class="text-sm text-gray-500">
            Want updates? <a href="/feed.xml" class="text-brand-400 underline">Subscribe to our RSS feed</a> or follow us on social media.
        </p>
    </div>
</div>
@endsection