<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">Featured Exhibitions</h2>
            <p class="text-sm text-gray-400 mt-0.5">Curate which galleries appear at the top of <a href="{{ route('discover') }}" target="_blank" class="text-purple-400 hover:underline">/discover</a></p>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 text-sm text-green-400 bg-green-900/20 border border-green-700/30 rounded-lg px-4 py-3">{{ session('status') }}</div>
        @endif

        <form method="GET" class="mb-5">
            <input type="text" name="q" value="{{ request('q') }}"
                   placeholder="Search by gallery or curator name…"
                   class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm w-full max-w-md focus:border-purple-500 focus:ring-purple-500">
        </form>

        <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
            <table class="w-full text-sm text-gray-300">
                <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Cover</th>
                        <th class="px-4 py-3 text-left">Gallery</th>
                        <th class="px-4 py-3 text-left">Curator</th>
                        <th class="px-4 py-3 text-left">Venue</th>
                        <th class="px-4 py-3 text-left">Artworks</th>
                        <th class="px-4 py-3 text-left">Views</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700/50">
                    @forelse($galleries as $gallery)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="w-12 h-12 rounded overflow-hidden bg-gray-900 border border-gray-700">
                                    @if($gallery->coverImage)
                                        <img src="{{ asset($gallery->coverImage->path) }}" alt="" class="w-full h-full object-cover">
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-200">{{ $gallery->title }}</div>
                                <div class="text-xs text-gray-500 mt-0.5">{{ $gallery->slug }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ $gallery->user->name }}</td>
                            <td class="px-4 py-3">
                                @if($gallery->venueTemplate)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700/50 text-gray-300">{{ $gallery->venueTemplate->name }}</span>
                                @else
                                    <span class="text-xs text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ $gallery->images_count }}</td>
                            <td class="px-4 py-3 text-gray-400">{{ number_format($gallery->view_count) }}</td>
                            <td class="px-4 py-3">
                                @if($gallery->is_featured)
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-amber-900/40 text-amber-400 border border-amber-700/40">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.539 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                        Featured
                                    </span>
                                @else
                                    <span class="text-xs text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('super.featured.toggle', $gallery) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs {{ $gallery->is_featured ? 'text-amber-400 hover:text-gray-500' : 'text-gray-500 hover:text-amber-400' }} transition font-medium">
                                        {{ $gallery->is_featured ? '★ Unfeature' : '☆ Feature' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-12 text-center text-gray-500">No galleries found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $galleries->withQueryString()->links() }}</div>
    </div>
</x-app-layout>
