<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Venue Templates" :description="'Data-driven 3D exhibition environments · '.$venues->total().' total'" :back="route('super.index')" backLabel="Master Control">
            <x-slot:actions>
                <a href="{{ route('super.venues.create') }}" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New venue
                </a>
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell">

        @if(session('status'))
            <div class="mb-4 text-sm text-emerald-400 bg-emerald-900/20 border border-emerald-700/30 rounded-lg px-4 py-3">{{ session('status') }}</div>
        @endif

        {{-- Filters --}}
        <form method="GET" class="mb-5 flex flex-wrap items-center gap-3">
            <input type="text" name="q" value="{{ request('q') }}"
                   placeholder="Search venues…"
                   class="input-base w-64">

            <select name="category" class="input-base">
                <option value="">All categories</option>
                @foreach($categories as $key => $label)
                    <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm font-medium transition">Filter</button>
            @if(request('q') || request('category'))
                <a href="{{ route('super.venues.index') }}" class="text-sm text-gray-400 hover:text-gray-200 transition">Clear</a>
            @endif
        </form>

        {{-- Table --}}
        <div class="table-wrap">
            <table class="table-base min-w-[720px]">
                <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Preview</th>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Category</th>
                        <th class="px-4 py-3 text-left">Plan</th>
                        <th class="px-4 py-3 text-left">Capacity</th>
                        <th class="px-4 py-3 text-left">Galleries</th>
                        <th class="px-4 py-3 text-left">Views</th>
                        <th class="px-4 py-3 text-left">Assets</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700/50">
                    @forelse($venues as $venue)
                        <tr class="{{ $venue->is_active ? '' : 'opacity-50' }} {{ $venue->is_draft ? 'bg-amber-900/5' : '' }}">
                            {{-- Thumbnail / preview --}}
                            <td class="px-4 py-3">
                                <div class="w-16 h-12 rounded overflow-hidden bg-gray-900 border border-gray-700 flex items-center justify-center">
                                    @if($venue->thumbnail_url)
                                        <img src="{{ $venue->thumbnail_url }}" alt="{{ $venue->name }}" class="w-full h-full object-cover">
                                    @else
                                        <span class="text-gray-600 text-xs">no img</span>
                                    @endif
                                </div>
                            </td>

                            {{-- Name + slug --}}
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-200">{{ $venue->name }}</span>
                                    @if($venue->is_featured)
                                        <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.539 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    @endif
                                    @if($venue->is_draft)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-amber-900/40 text-amber-400">DRAFT</span>
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500 mt-0.5">{{ $venue->slug }} · v{{ $venue->version }}</div>
                                @if($venue->tags && count($venue->tags))
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach(array_slice($venue->tags, 0, 3) as $tag)
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-gray-700/50 text-gray-400">#{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            {{-- Category --}}
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700/50 text-gray-300">{{ $venue->categoryLabel() }}</span>
                            </td>

                            {{-- Plan --}}
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ match($venue->plan_required) { 'free' => 'bg-emerald-900/40 text-emerald-400', 'pro' => 'bg-brand-900/40 text-brand-400', default => 'bg-amber-900/40 text-amber-400' } }}">
                                    {{ ucfirst($venue->plan_required) }}
                                </span>
                            </td>

                            {{-- Capacity --}}
                            <td class="px-4 py-3 text-gray-400 text-xs">{{ $venue->capacityLabel() }}</td>

                            {{-- Galleries using this venue --}}
                            <td class="px-4 py-3 text-gray-400">{{ $venue->galleries_count }}</td>

                            {{-- Views --}}
                            <td class="px-4 py-3 text-gray-400">{{ number_format($venue->view_count) }}</td>

                            {{-- Asset badges --}}
                            <td class="px-4 py-3">
                                <div class="flex gap-1 flex-wrap">
                                    @if($venue->preview_model_path)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-blue-900/40 text-blue-400" title="3D preview model attached">3D</span>
                                    @endif
                                    @if($venue->hdri_path)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-brand-900/40 text-brand-400" title="Custom HDRI">HDRI</span>
                                    @endif
                                    @if($venue->decorations && count($venue->decorations))
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-emerald-900/40 text-emerald-400" title="{{ count($venue->decorations) }} decoration props">{{ count($venue->decorations) }} props</span>
                                    @endif
                                    @if($venue->lighting_fixtures && count($venue->lighting_fixtures))
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-amber-900/40 text-amber-400" title="{{ count($venue->lighting_fixtures) }} custom light fixtures">{{ count($venue->lighting_fixtures) }} lights</span>
                                    @endif
                                    @if($venue->default_audio_path)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-pink-900/40 text-pink-400" title="Default ambient audio">audio</span>
                                    @endif
                                </div>
                            </td>

                            {{-- Status toggle --}}
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('super.venues.toggle', $venue) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs {{ $venue->is_active ? 'text-emerald-400 hover:text-red-400' : 'text-gray-500 hover:text-emerald-400' }} transition">
                                        {{ $venue->is_active ? '● Active' : '○ Inactive' }}
                                    </button>
                                </form>
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <a href="{{ route('super.venues.edit', $venue) }}" class="text-xs text-brand-400 hover:text-brand-300 transition">Edit</a>
                                    <form method="POST" action="{{ route('super.venues.toggle-featured', $venue) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <button class="text-xs {{ $venue->is_featured ? 'text-amber-400 hover:text-gray-500' : 'text-gray-500 hover:text-amber-400' }} transition" title="Toggle featured">
                                            {{ $venue->is_featured ? '★ Featured' : '☆ Feature' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('super.venues.destroy', $venue) }}"
                                          data-confirm="Delete venue &quot;{{ $venue->name }}&quot;? Galleries using it will fall back to the default venue."
                                          class="inline">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-500 hover:text-red-400 transition">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                No venue templates found. <a href="{{ route('super.venues.create') }}" class="text-brand-400 hover:text-brand-300">Create your first one</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $venues->withQueryString()->links() }}
        </div>

        {{-- Helper card --}}
        <div class="mt-6 bg-gray-800/50 border border-gray-700/50 rounded-xl p-5 text-sm text-gray-400">
            <h3 class="text-gray-200 font-semibold mb-2">How venue templates work</h3>
            <ul class="space-y-1 list-disc list-inside">
                <li>The <code class="text-brand-400">visual_config</code>, <code class="text-brand-400">material_config</code>, <code class="text-brand-400">decorations</code>, and <code class="text-brand-400">lighting_fixtures</code> JSON fields are the single source of truth for how the 3D viewer renders a venue.</li>
                <li>3D preview models (GLB), HDRI environment maps, and default ambient audio can be uploaded per-venue.</li>
                <li>The 3D viewer reads the JSON config via the <code class="text-brand-400">VenueConfigExporter</code> service. The legacy JS switch in <code class="text-brand-400">view.blade.php</code> is kept as a fallback for backward compatibility.</li>
                <li>Galleries using a deleted venue fall back to the default "white-cube" venue automatically.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
