{{-- Block: cta — final conversion band. --}}
<section class="max-w-5xl mx-auto px-4 py-16">
    <div class="bg-gradient-to-br from-purple-900/40 to-gray-900 border border-purple-800/40 rounded-2xl p-10 text-center">
        @if(!empty($data['title']))
            <h2 class="text-2xl md:text-3xl font-bold text-white mb-4">{{ $data['title'] }}</h2>
        @endif
        @if(!empty($data['text']))
            <p class="text-gray-300 max-w-2xl mx-auto mb-8 leading-relaxed">{{ $data['text'] }}</p>
        @endif
        @if(!empty($data['button_text']) && !empty($data['button_url']))
            <a href="{{ $data['button_url'] }}" class="inline-flex items-center gap-2 px-8 py-3 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-semibold transition">
                {{ $data['button_text'] }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        @endif
    </div>
</section>
