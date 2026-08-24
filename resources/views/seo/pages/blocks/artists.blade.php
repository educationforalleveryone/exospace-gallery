{{-- Block: artists — LIVE artist profiles (real data). --}}
<section class="max-w-6xl mx-auto px-4 py-14">
    <h2 class="text-2xl md:text-3xl font-bold text-white mb-3 text-center">{{ $data['heading'] ?? 'Artists on Exospace' }}</h2>
    @if(!empty($data['subtitle']))
        <p class="text-gray-400 text-center mb-10 max-w-2xl mx-auto">{{ $data['subtitle'] }}</p>
    @endif
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-5">
        @foreach(($context['items'] ?? []) as $artist)
            <a href="{{ route('artist.profile', $artist->slug) }}" class="group text-center">
                <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-purple-900/40 to-gray-900 border border-gray-700 group-hover:border-purple-500 transition flex items-center justify-center overflow-hidden">
                    @if($artist->portrait_url)
                        <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name }}" loading="lazy" class="w-full h-full object-cover">
                    @else
                        <span class="text-gray-500 font-bold">{{ $artist->initials }}</span>
                    @endif
                </div>
                <p class="text-gray-300 text-sm font-medium mt-2 truncate group-hover:text-purple-300 transition">{{ $artist->name }}</p>
                <p class="text-gray-600 text-xs">{{ $artist->public_works_count }} works</p>
            </a>
        @endforeach
    </div>
    <p class="text-center mt-8">
        <a href="{{ route('artists.index') }}" class="text-purple-400 hover:text-purple-300 transition text-sm">Browse all artists →</a>
    </p>
</section>
