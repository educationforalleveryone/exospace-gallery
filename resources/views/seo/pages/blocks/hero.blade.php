{{-- Block: hero — headline + subtitle + optional CTA. Escaped output only. --}}
<section class="bg-gradient-to-br from-gray-900 via-brand-950/30 to-gray-900 border-b border-gray-800">
    <div class="max-w-5xl mx-auto px-4 py-20 text-center">
        @if(!empty($data['eyebrow']))
            <p class="text-brand-400 text-sm font-semibold tracking-widest uppercase mb-4">{{ $data['eyebrow'] }}</p>
        @endif
        <h1 class="text-4xl md:text-6xl font-extrabold text-white mb-6 leading-tight">{{ $data['title'] ?? '' }}</h1>
        @if(!empty($data['subtitle']))
            <p class="text-lg md:text-xl text-gray-400 max-w-3xl mx-auto leading-relaxed">{{ $data['subtitle'] }}</p>
        @endif
        @if(!empty($data['cta_text']) && !empty($data['cta_url']))
            <a href="{{ $data['cta_url'] }}" class="inline-flex items-center gap-2 mt-8 px-6 py-3 rounded-xl bg-gradient-to-r from-brand-600 to-brand-600 hover:from-brand-500 hover:to-brand-500 text-white font-semibold transition">
                {{ $data['cta_text'] }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        @endif
    </div>
</section>
