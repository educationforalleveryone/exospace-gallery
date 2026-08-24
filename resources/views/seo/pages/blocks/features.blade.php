{{-- Block: features — heading + grid of feature cards. --}}
<section class="max-w-6xl mx-auto px-4 py-14">
    @if(!empty($data['heading']))
        <h2 class="text-2xl md:text-3xl font-bold text-white mb-10 text-center">{{ $data['heading'] }}</h2>
    @endif
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach(($data['items'] ?? []) as $item)
            <div class="bg-gray-800/60 border border-gray-700/50 rounded-xl p-6">
                <h3 class="text-gray-100 font-semibold mb-2">{{ $item['title'] ?? '' }}</h3>
                <p class="text-gray-400 text-sm leading-relaxed">{{ $item['text'] ?? '' }}</p>
            </div>
        @endforeach
    </div>
</section>
