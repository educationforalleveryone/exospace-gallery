{{-- Block: venues — LIVE venue templates (real data). --}}
<section class="max-w-6xl mx-auto px-4 py-14">
    <h2 class="text-2xl md:text-3xl font-bold text-white mb-3 text-center">{{ $data['heading'] ?? 'Venue templates' }}</h2>
    @if(!empty($data['subtitle']))
        <p class="text-gray-400 text-center mb-10 max-w-2xl mx-auto">{{ $data['subtitle'] }}</p>
    @endif
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-5">
        @foreach(($context['items'] ?? []) as $venue)
            <a href="{{ route('venues.show', $venue->slug) }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl p-5 hover:border-brand-500 transition text-center">
                <h3 class="text-gray-100 font-semibold group-hover:text-brand-300 transition">{{ $venue->name }}</h3>
                <p class="text-gray-500 text-xs mt-1">{{ $venue->public_galleries_count }} live exhibitions</p>
            </a>
        @endforeach
    </div>
    <p class="text-center mt-8">
        <a href="{{ route('venues.index') }}" class="text-brand-400 hover:text-brand-300 transition text-sm">Browse all venue templates →</a>
    </p>
</section>
