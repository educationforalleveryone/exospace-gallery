{{-- Block: exhibitions — LIVE public exhibitions (real data, internal
     linking from landing pages into the content graph). --}}
<section class="max-w-6xl mx-auto px-4 py-14">
    <h2 class="text-2xl md:text-3xl font-bold text-white mb-3 text-center">{{ $data['heading'] ?? 'Live 3D exhibitions' }}</h2>
    @if(!empty($data['subtitle']))
        <p class="text-gray-400 text-center mb-10 max-w-2xl mx-auto">{{ $data['subtitle'] }}</p>
    @endif
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach(($context['items'] ?? []) as $gallery)
            <a href="{{ $gallery->public_url }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl overflow-hidden hover:border-brand-500 hover:-translate-y-1 transition-all duration-300">
                <div class="aspect-[4/3] bg-gray-900 overflow-hidden">
                    @if($gallery->coverImage)
                        <img src="{{ $gallery->coverImage->public_url }}" srcset="{{ $gallery->coverImage->srcset }}" sizes="(max-width: 768px) 100vw, 33vw" alt="{{ $gallery->title }}" loading="lazy" decoding="async" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                    @endif
                </div>
                <div class="p-4">
                    <h3 class="text-gray-100 font-semibold group-hover:text-brand-300 transition-colors">{{ $gallery->title }}</h3>
                    <p class="text-gray-500 text-xs mt-1">{{ number_format($gallery->view_count) }} views @if($gallery->venueTemplate) · {{ $gallery->venueTemplate->name }} @endif</p>
                </div>
            </a>
        @endforeach
    </div>
    <p class="text-center mt-8">
        <a href="{{ route('discover') }}" class="text-brand-400 hover:text-brand-300 transition text-sm">Browse all 3D exhibitions →</a>
    </p>
</section>
