{{--
    Gallery events page (SEO OS Iteration 2): moved from x-guest-layout
    (which emitted noindex,nofollow) to the public layout with proper
    title/description/canonical. Indexable only when events exist —
    controller sets noindex for empty event calendars.
--}}
@extends('layouts.public')

@section('content')
    <div class="bg-gradient-to-br from-gray-900 via-purple-950/30 to-gray-900 border-b border-gray-800">
            <div class="max-w-5xl mx-auto px-4 py-12">
                <p class="text-purple-400 text-xs font-semibold tracking-widest uppercase mb-2">Events</p>
                <h1 class="text-3xl md:text-4xl font-extrabold text-white">{{ $gallery->title }} — Events</h1>
                <p class="text-gray-400 mt-2">Upcoming events, openings, and artist talks</p>
                <a href="{{ $gallery->public_url }}" class="inline-flex items-center gap-2 mt-4 text-sm text-purple-400 hover:text-purple-300 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Enter 3D gallery
                </a>
            </div>
        </div>

    <div class="max-w-5xl mx-auto px-4 py-10">
        <div class="mb-8">
            <x-breadcrumbs :crumbs="$breadcrumbs" />
        </div>


        @if(session('status'))
            <div class="mb-6 p-4 rounded-lg bg-green-900/20 border border-green-700/30 text-green-300 text-sm">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-6 p-4 rounded-lg bg-red-900/20 border border-red-700/30 text-red-300 text-sm">{{ session('error') }}</div>
        @endif

        {{-- Upcoming events --}}
        @if($upcoming->count() > 0)
            <h2 class="text-gray-200 font-semibold text-lg mb-4">Upcoming</h2>
            <div class="space-y-4 mb-12">
                @foreach($upcoming as $event)
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                        <div class="flex items-start justify-between gap-4 mb-3">
                            <div>
                                <span class="inline-block text-xs px-2 py-0.5 rounded-full bg-purple-900/40 text-purple-300 border border-purple-700/40 mb-2">{{ $event->typeLabel() }}</span>
                                <h3 class="text-gray-100 text-xl font-bold">{{ $event->title }}</h3>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <div class="text-2xl font-bold text-purple-400">{{ $event->starts_at->format('j') }}</div>
                                <div class="text-xs text-gray-500 uppercase tracking-wider">{{ $event->starts_at->format('M Y') }}</div>
                            </div>
                        </div>

                        <p class="text-gray-400 text-sm mb-3">{{ $event->starts_at->format('l, F j \a\t g:i A') }}@if($event->ends_at) – {{ $event->ends_at->format('g:i A') }}@endif</p>

                        @if($event->description)
                            <p class="text-gray-300 text-sm leading-relaxed mb-3 whitespace-pre-line">{{ $event->description }}</p>
                        @endif

                        @if($event->location_name)
                            <p class="text-gray-400 text-sm mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                {{ $event->location_name }}
                            </p>
                        @endif

                        {{-- RSVP form --}}
                        @if($event->is_active && $event->isUpcoming())
                            @if($event->isAtCapacity())
                                <div class="mt-4 p-3 rounded-lg bg-yellow-900/20 border border-yellow-700/30 text-yellow-300 text-sm">
                                    This event has reached capacity. RSVP is closed.
                                </div>
                            @else
                                <form method="POST" action="{{ route('gallery.events.rsvp', [$gallery->slug, $event]) }}" class="mt-4 bg-gray-900/50 rounded-lg p-4 border border-gray-700">
                                    @csrf
                                    <p class="text-sm text-gray-300 font-medium mb-3">RSVP to attend:</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <input type="text" name="name" placeholder="Your name" required
                                               class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                                        <input type="email" name="email" placeholder="Your email" required
                                               class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    </div>
                                    {{-- P3-19: Cloudflare Turnstile captcha (invisible when enabled) --}}
                                    @if(app('App\Services\TurnstileService')->isEnabled())
                                        <div class="cf-turnstile mt-3" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                                    @endif
                                    <button type="submit" class="mt-3 px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">
                                        RSVP
                                    </button>
                                    @if($event->capacity)
                                        <p class="text-xs text-gray-500 mt-2">{{ $event->spotsRemaining() }} spot{{ $event->spotsRemaining() === 1 ? '' : 's' }} remaining</p>
                                    @endif
                                </form>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-16">
                <svg class="w-16 h-16 mx-auto mb-3 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p class="text-gray-400">No upcoming events scheduled.</p>
                <p class="text-gray-600 text-sm mt-1">Check back soon or follow the gallery for updates.</p>
            </div>
        @endif

        {{-- Past events --}}
        @if($past->count() > 0)
            <h2 class="text-gray-200 font-semibold text-lg mb-4">Past events</h2>
            <div class="space-y-3">
                @foreach($past as $event)
                    <div class="bg-gray-800/50 border border-gray-700/50 rounded-lg p-4 opacity-70">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-700/50 text-gray-400 mr-2">{{ $event->typeLabel() }}</span>
                                <span class="text-gray-300 font-medium">{{ $event->title }}</span>
                            </div>
                            <span class="text-xs text-gray-500">{{ $event->starts_at->format('M j, Y') }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
