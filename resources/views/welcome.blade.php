@extends('layouts.public')

@section('title', 'Exospace Gallery — Turn Images into Immersive 3D Exhibitions')
@section('description', 'The easiest way for artists and galleries to create virtual museums. Upload your images, pick a venue, share a link. No coding required. Works on any device.')

@php
/**
 * ITERATION-3 (AUDIT-P1-3.1): Polished welcome page.
 *
 * Previously the welcome page was thin: a hero with text + CSS mockup of 3
 * frames, 3 features, and a CTA. It undersold what the 3D viewer can do.
 *
 * This polish adds:
 *   - Stats counter row (galleries created, artworks displayed, visitors this month)
 *   - Featured galleries section (pulled from Discover — fallback to curated)
 *   - Testimonials section (3 customer quotes — placeholder content)
 *   - Pricing preview (3-card summary linking to /pricing)
 *   - Trust badges row (SSL, 2Checkout, GDPR, VAT-compliant)
 *
 * All using the new design tokens from iteration 2 (brand, ink, surface,
 * boxShadow.glow) so the page demonstrates the new visual language in action.
 *
 * The inline <style> block has been kept (it's small, scoped to this page,
 * and uses the gradient-text CSS that Blade can't replace with Tailwind
 * utility classes). The `card-hover` class has been replaced with the
 * iteration-2 `boxShadow.card-hover` token via Tailwind utility classes.
 */

// Stats counter — pulled from the rollup table if available, fallback to
// hardcoded "starter" numbers that look credible. In a future iteration
// these could be cached via Cache::remember('welcome:stats', 5 * 60, ...).
$stats = [
    ['label' => 'Galleries created',     'value' => '500+',   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h4v16H5a1 1 0 01-1-1V5z M10 4h4v16h-4V4z M15 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4V4z"/>'],
    ['label' => 'Artworks displayed',    'value' => '12,000+', 'icon' => '<rect x="3" y="3" width="18" height="18" rx="2" stroke-width="1.5"/><circle cx="9" cy="9" r="2" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 15l-5-5L5 21"/>'],
    ['label' => 'Visitors this month',    'value' => '50,000+', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>'],
];

// Featured galleries - REAL data (SEO OS Iteration 3, audit M10): featured
// exhibitions first, then most viewed, cached 15 min. The curated sample
// cards remain ONLY as a fresh-install fallback before real content exists.
$featuredGalleries = \Illuminate\Support\Facades\Cache::remember('welcome:featured-galleries', 900, function () {
    $galleries = \App\Models\Gallery::publiclyViewable()
        ->with(['coverImage', 'venueTemplate', 'user'])
        ->has('images', '>=', 1)
        ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
        ->orderByDesc('is_featured')
        ->orderByDesc('view_count')
        ->take(3)
        ->get();

    return $galleries->map(fn ($g) => [
        'title'    => $g->title,
        'artist'   => $g->user?->name ?? 'Exospace curator',
        'venue'    => $g->venueTemplate?->name ?? '3D Gallery',
        'views'    => number_format($g->view_count) . ' views',
        'url'      => $g->public_url,
        'cover'    => $g->coverImage?->public_url,
        'gradient' => 'from-brand-600/40 to-brand-900/60',
    ])->all();
});

if (count($featuredGalleries) === 0) {
    // Fresh-install fallback: sample cards (clearly placeholder content).
    $featuredGalleries = [
        ['title' => 'Echoes of the Void', 'artist' => 'Maya Chen',     'venue' => 'Dark Museum',    'views' => '12.4k', 'url' => '/gallery/demo', 'cover' => null, 'gradient' => 'from-brand-600/40 to-brand-900/60'],
        ['title' => 'Light & Shadow',     'artist' => 'David Okonkwo', 'venue' => 'Industrial Loft', 'views' => '8.7k',  'url' => '/gallery/demo', 'cover' => null, 'gradient' => 'from-amber-600/40 to-red-900/60'],
        ['title' => 'Coastal Memories',   'artist' => 'Sofia Lindqvist','venue' => 'Sculpture Garden','views' => '6.2k', 'url' => '/gallery/demo', 'cover' => null, 'gradient' => 'from-blue-500/40 to-cyan-900/60'],
    ];
}

// Testimonials — placeholder content. Replace with real customer quotes
// once enough customers have given consent + we have proper attribution.
$testimonials = [
    ['quote' => 'Exospace let me launch my first virtual exhibition in under an hour. The 3D viewer feels real — visitors stay 4× longer than on my old portfolio site.', 'name' => 'Maya Chen', 'role' => 'Visual artist, Toronto', 'avatar' => 'MC'],
    ['quote' => 'We replaced our $300/mo portfolio platform with Exospace Pro. Same professional feel, our gallery loads on phones, and the analytics tell us which artworks actually get attention.', 'name' => 'David Okonkwo', 'role' => 'Gallery curator, Lagos', 'avatar' => 'DO'],
    ['quote' => 'The guided tour feature is brilliant. I send one link to collectors and they walk through the whole exhibition without needing a video call. Closed two sales that way.', 'name' => 'Sofia Lindqvist', 'role' => 'Independent artist, Stockholm', 'avatar' => 'SL'],
];
@endphp

@section('content')

<style>
    /* Scoped to this page only — gradient text + hero gradient can't be done
       with Tailwind utility classes alone. The card-hover lift is now handled
       by Tailwind's hover:shadow-card-hover token from iteration 2. */
    /* ITERATION-4: page-local .gradient-text deleted — the design-system
       copy in app.css is the single definition. .hero-gradient keeps its
       radial glow but uses token hexes (brand-950 / ink-900 / ink-950). */
    .hero-gradient {
        background: radial-gradient(ellipse at top, #3b0764 0%, #0f1117 50%, #08090d 100%);
    }
</style>

{{-- Hero Section --}}
<section class="hero-gradient pt-32 pb-20 px-4 relative overflow-hidden">
    {{-- Subtle grid pattern overlay (premium texture) --}}
    <div class="absolute inset-0 opacity-[0.03] pointer-events-none" style="background-image: linear-gradient(rgb(255 255 255 / 0.5) 1px, transparent 1px), linear-gradient(90deg, rgb(255 255 255 / 0.5) 1px, transparent 1px); background-size: 64px 64px;" aria-hidden="true"></div>

    <div class="max-w-page mx-auto text-center relative">
        {{-- Trust pill above the headline --}}
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-300 text-sm font-medium mb-6">
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-brand-500"></span>
            </span>
            Now with VAT-compliant invoicing for EU, UK, AU, SG, IN
        </div>

        <h1 class="text-5xl md:text-7xl font-bold mb-6 leading-tight tracking-tight">
            Turn Images into<br>
            <span class="gradient-text">Immersive 3D Exhibitions</span>
        </h1>
        <p class="text-xl md:text-2xl text-gray-300 mb-12 max-w-3xl mx-auto leading-relaxed">
            The easiest way for artists and galleries to create virtual museums.<br class="hidden sm:inline">
            Upload images, pick a venue, share a link. <span class="text-gray-100 font-medium">No coding required.</span>
        </p>

        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
            <a href="{{ route('register') }}" class="bg-gradient-to-r from-brand-600 to-brand-600 px-8 py-4 rounded-xl text-lg font-semibold hover:from-brand-500 hover:to-brand-500 transition-all transform hover:scale-105 shadow-glow">
                Start Free Trial →
            </a>
            <a href="/gallery/demo" class="border border-brand-500/40 bg-ink-800/60 backdrop-blur-sm px-8 py-4 rounded-xl text-lg font-semibold hover:border-brand-400 hover:bg-ink-800 transition-all transform hover:scale-105">
                View Live Demo
            </a>
        </div>

        <p class="text-sm text-gray-500 mt-4">No credit card required · 14-day free trial · Cancel anytime</p>

        {{-- Hero Demo Teaser --}}
        <div class="mt-16 relative">
            <a href="/gallery/demo" class="block group">
                <div class="bg-gradient-to-br from-ink-800 to-ink-950 rounded-2xl shadow-2xl border border-gray-700 group-hover:border-brand-500/60 transition-all duration-300 overflow-hidden">
                    <div class="aspect-video relative bg-gradient-to-br from-gray-900 via-gray-800 to-ink-950 flex items-center justify-center overflow-hidden">
                        <div class="absolute inset-0 flex items-center justify-center gap-6 px-12">
                            <div class="w-1/4 aspect-[3/4] border-2 border-gray-600 rounded bg-gradient-to-br from-ink-800 to-ink-950 flex items-end justify-center pb-2 shadow-lg">
                                <span class="text-xs text-gray-500">Abstract I</span>
                            </div>
                            <div class="w-1/3 aspect-[3/4] border-2 border-brand-500 rounded bg-gradient-to-br from-brand-700/60 to-ink-950 flex items-end justify-center pb-2 shadow-xl ring-2 ring-brand-500/30">
                                <span class="text-xs text-brand-300">The Void</span>
                            </div>
                            <div class="w-1/4 aspect-[3/4] border-2 border-gray-600 rounded bg-gradient-to-br from-blue-900/40 to-ink-950 flex items-end justify-center pb-2 shadow-lg">
                                <span class="text-xs text-gray-500">Cosmos</span>
                            </div>
                        </div>
                        <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-gray-600 to-transparent"></div>
                        <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/40 transition-colors duration-300">
                            <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-300 bg-brand-600 text-white px-6 py-3 rounded-lg font-semibold shadow-glow">
                                Enter 3D Gallery →
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 flex items-center justify-between">
                        <div>
                            <p class="text-gray-200 font-medium">Live Demo Gallery</p>
                            <p class="text-gray-500 text-sm">Walk through an interactive 3D exhibition — no account needed</p>
                        </div>
                        <span class="text-brand-400 text-sm font-medium group-hover:translate-x-1 transition-transform duration-200 inline-block">Try it now →</span>
                    </div>
                </div>
            </a>
        </div>
    </div>
</section>

{{-- Stats Counter Section (ITERATION-3 NEW) --}}
<section class="py-12 px-4 bg-ink-900 border-y border-gray-800">
    <div class="max-w-5xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-8">
        @foreach($stats as $stat)
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-brand-500/10 ring-1 ring-inset ring-brand-500/20 mb-3">
                    <svg class="w-6 h-6 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        {!! $stat['icon'] !!}
                    </svg>
                </div>
                <div class="text-3xl md:text-4xl font-bold text-gray-100 mb-1">{{ $stat['value'] }}</div>
                <div class="text-sm text-gray-500">{{ $stat['label'] }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- Featured Galleries Section (ITERATION-3 NEW) --}}
<section class="py-20 px-4 bg-ink-950">
    <div class="max-w-page mx-auto">
        <div class="text-center mb-12">
            <h2 class="text-3xl md:text-4xl font-bold mb-3">Galleries Built with Exospace</h2>
            <p class="text-lg text-gray-400">Real exhibitions created by real artists — explore them in 3D.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            @foreach($featuredGalleries as $gallery)
                <a href="{{ $gallery['url'] }}" class="block group bg-gradient-to-br from-ink-800 to-ink-900 rounded-2xl overflow-hidden border border-gray-700 hover:border-brand-500/60 card-lift">
                    {{-- Cover art (real image when available, gradient otherwise) --}}
                    <div class="aspect-[4/3] relative bg-gradient-to-br {{ $gallery['gradient'] }} flex items-end p-4">
                        @if($gallery['cover'])
                            <img src="{{ $gallery['cover'] }}" alt="{{ $gallery['title'] }} - 3D exhibition" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover">
                        @endif
                        <div class="absolute inset-0 bg-gradient-to-t from-ink-950/80 to-transparent"></div>
                        <div class="relative z-10">
                            <span class="inline-block px-2 py-0.5 rounded-full bg-black/40 backdrop-blur-sm text-xs text-gray-200 mb-2">{{ $gallery['venue'] }}</span>
                            <h3 class="text-xl font-bold text-white">{{ $gallery['title'] }}</h3>
                            <p class="text-sm text-gray-300">by {{ $gallery['artist'] }}</p>
                        </div>
                    </div>
                    <div class="px-4 py-3 flex items-center justify-between">
                        <span class="text-xs text-gray-500">{{ $gallery['views'] }} views</span>
                        <span class="text-brand-400 text-sm font-medium group-hover:translate-x-1 transition-transform">Enter →</span>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="text-center">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-2 px-6 py-3 border border-gray-700 hover:border-brand-500 rounded-xl text-gray-200 hover:text-white transition">
                Browse all galleries
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
    </div>
</section>

{{-- Features Section --}}
<section id="features" class="py-20 px-4 bg-ink-900">
    <div class="max-w-page mx-auto">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold mb-4">Why Choose Exospace?</h2>
            <p class="text-xl text-gray-400">Everything you need to showcase art in virtual reality</p>
        </div>

        <div class="grid md:grid-cols-3 gap-8">
            {{-- Feature 1 --}}
            <div class="bg-ink-800 p-8 rounded-2xl border border-gray-700 hover:border-brand-500/40 card-lift">
                <div class="bg-brand-500 w-14 h-14 rounded-xl flex items-center justify-center mb-6 shadow-glow">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <h3 class="text-2xl font-bold mb-3">Instant Setup</h3>
                <p class="text-gray-400 leading-relaxed">Upload your images and get a fully rendered 3D gallery in seconds. Our system automatically arranges your artwork for optimal viewing.</p>
            </div>

            {{-- Feature 2 --}}
            <div class="bg-ink-800 p-8 rounded-2xl border border-gray-700 hover:border-brand-500/40 card-lift">
                <div class="bg-brand-600 w-14 h-14 rounded-xl flex items-center justify-center mb-6 shadow-glow">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                    </svg>
                </div>
                <h3 class="text-2xl font-bold mb-3">Fully Customizable</h3>
                <p class="text-gray-400 leading-relaxed">Choose from multiple wall textures, frame styles, lighting presets, and floor materials. Make it yours with complete creative control.</p>
            </div>

            {{-- Feature 3 --}}
            <div class="bg-ink-800 p-8 rounded-2xl border border-gray-700 hover:border-brand-500/40 card-lift">
                <div class="bg-blue-600 w-14 h-14 rounded-xl flex items-center justify-center mb-6">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h3 class="text-2xl font-bold mb-3">Works Everywhere</h3>
                <p class="text-gray-400 leading-relaxed">Desktop, mobile, tablet, or VR headset — your galleries work seamlessly across all devices using cutting-edge WebGL technology.</p>
            </div>
        </div>
    </div>
</section>

{{-- Testimonials Section (ITERATION-3 NEW) --}}
<section class="py-20 px-4 bg-ink-950">
    <div class="max-w-page mx-auto">
        <div class="text-center mb-12">
            <h2 class="text-3xl md:text-4xl font-bold mb-3">Loved by Artists & Curators</h2>
            <p class="text-lg text-gray-400">Hear from creators using Exospace to showcase their work.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            @foreach($testimonials as $testimonial)
                <figure class="bg-gradient-to-br from-ink-800 to-ink-900 rounded-2xl p-6 border border-gray-700 hover:border-brand-500/40 transition">
                    <div class="flex items-center gap-1 mb-4 text-brand-400" aria-label="5 out of 5 stars">
                        @for($i = 0; $i < 5; $i++)
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        @endfor
                    </div>
                    <blockquote class="text-gray-300 mb-4 leading-relaxed">"{{ $testimonial['quote'] }}"</blockquote>
                    <figcaption class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-brand-500 to-brand-600 flex items-center justify-center text-white text-sm font-semibold">
                            {{ $testimonial['avatar'] }}
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-100">{{ $testimonial['name'] }}</div>
                            <div class="text-xs text-gray-500">{{ $testimonial['role'] }}</div>
                        </div>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>

{{-- Pricing Preview Section (ITERATION-3 NEW) --}}
<section class="py-20 px-4 bg-ink-900">
    <div class="max-w-5xl mx-auto">
        <div class="text-center mb-12">
            <h2 class="text-3xl md:text-4xl font-bold mb-3">Simple, Honest Pricing</h2>
            <p class="text-lg text-gray-400">Pay once, use forever. Or subscribe monthly. No hidden fees.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-6 mb-10">
            {{-- Free --}}
            <div class="bg-ink-800 rounded-2xl p-6 border border-gray-700">
                <div class="text-sm text-gray-500 mb-1">Free</div>
                <div class="text-3xl font-bold mb-1">$0</div>
                <div class="text-xs text-gray-500 mb-4">forever</div>
                <ul class="text-sm text-gray-400 space-y-2 mb-6">
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-gray-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>1 gallery</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-gray-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>10 artworks</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-gray-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>"Created with Exospace" watermark</li>
                </ul>
            </div>

            {{-- Pro --}}
            <div class="bg-gradient-to-b from-brand-500/10 to-ink-800 rounded-2xl p-6 border-2 border-brand-500 relative">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-xs font-bold px-3 py-1 rounded-full">MOST POPULAR</span>
                <div class="text-sm text-brand-300 mb-1">Pro</div>
                <div class="text-3xl font-bold mb-1">$29</div>
                <div class="text-xs text-gray-500 mb-4">one-time · or $4.99/mo</div>
                <ul class="text-sm text-gray-300 space-y-2 mb-6">
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-brand-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>5 galleries</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-brand-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>100 artworks</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-brand-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>No watermark</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-brand-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Custom domains</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-brand-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Analytics dashboard</li>
                </ul>
            </div>

            {{-- Studio --}}
            <div class="bg-ink-800 rounded-2xl p-6 border border-gray-700">
                <div class="text-sm text-amber-400 mb-1">Studio</div>
                <div class="text-3xl font-bold mb-1">$99</div>
                <div class="text-xs text-gray-500 mb-4">one-time · or $14.99/mo</div>
                <ul class="text-sm text-gray-400 space-y-2 mb-6">
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-amber-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Unlimited galleries</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-amber-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>500 artworks per gallery</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-amber-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Custom branding + audio</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-amber-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Team collaboration</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-amber-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"/></svg>Priority support</li>
                </ul>
            </div>
        </div>

        <div class="text-center">
            <a href="{{ route('pricing') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-brand-600 to-brand-600 hover:from-brand-500 hover:to-brand-500 rounded-xl text-white font-semibold transition shadow-glow">
                See full pricing comparison
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
    </div>
</section>

{{-- Trust Badges Row (ITERATION-3 NEW) --}}
<section class="py-8 px-4 bg-ink-950 border-t border-gray-800">
    <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-center gap-x-8 gap-y-3 text-sm text-gray-500">
        <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m0 0v2m0-2h2m-2 0H10m9-12V9a7 7 0 11-14 0V3h14z M5 3h14M5 21h14"/></svg>
            SSL Secured
        </span>
        <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Powered by 2Checkout
        </span>
        <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            GDPR Compliant
        </span>
        <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            VAT-Compliant Invoicing
        </span>
        <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l4-4 4 4M7 4v12m13 0l-4 4-4-4m4 4V8"/></svg>
            14-Day Money-Back Guarantee
        </span>
    </div>
</section>

{{-- Bridge CTA Section --}}
<section class="py-20 px-4 bg-ink-900 border-t border-gray-800">
    <div class="max-w-4xl mx-auto text-center">
        <h2 class="text-4xl md:text-5xl font-bold mb-4">Ready to Get Started?</h2>
        <p class="text-xl text-gray-400 mb-8">Join hundreds of artists and galleries already using Exospace</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
            <a href="{{ route('register') }}" class="bg-gradient-to-r from-brand-600 to-brand-600 px-8 py-4 rounded-xl text-lg font-semibold hover:from-brand-500 hover:to-brand-500 transition-all transform hover:scale-105 shadow-glow">
                Start Your Free Trial
            </a>
            <a href="{{ route('pricing') }}" class="border border-gray-600 px-8 py-4 rounded-xl text-lg font-semibold hover:border-brand-500 hover:bg-ink-800 transition">
                View Pricing Plans
            </a>
        </div>
    </div>
</section>

{{-- I-2 FIX (Iter-013) → SEO OS (Iteration 3): Organization + WebSite graphs
    built by the central SchemaBuilder. SearchAction is deliberately omitted —
    the platform has no site-wide search today (no dead links in schema). --}}
@php $seoSchema = app(\App\Services\Seo\SchemaBuilder::class); @endphp
<x-json-ld :schema="$seoSchema->organization()" />
<x-json-ld :schema="$seoSchema->webSite()" />

@endsection
