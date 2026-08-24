{{-- Block: faq — authored questions/answers. Also feeds FAQPage schema
     (SeoPageRenderer collects the same items). --}}
<section class="max-w-3xl mx-auto px-4 py-14">
    @if(!empty($data['heading']))
        <h2 class="text-2xl md:text-3xl font-bold text-white mb-8">{{ $data['heading'] }}</h2>
    @endif
    <div class="space-y-4">
        @foreach(($data['items'] ?? []) as $item)
            <details class="group bg-gray-800/60 border border-gray-700/50 rounded-xl p-5">
                <summary class="text-gray-100 font-medium cursor-pointer list-none flex items-center justify-between gap-4">
                    {{ $item['question'] ?? '' }}
                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <p class="text-gray-400 text-sm leading-relaxed mt-3">{{ $item['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </div>
</section>
