<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">SEO Operations</h2>
                <p class="text-sm text-gray-400 mt-0.5">Health, overrides, redirects &amp; content pages</p>
            </div>
            <form method="POST" action="{{ route('super.seo.rebuild') }}">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition">
                    Rebuild SEO caches
                </button>
            </form>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 text-sm text-green-400 bg-green-900/20 border border-green-700/30 rounded-lg px-4 py-3">{{ session('status') }}</div>
        @endif

        {{-- Tabs --}}
        <nav class="flex gap-1 mb-6 border-b border-gray-700" aria-label="SEO sections">
            @foreach(['health' => 'Health', 'galleries' => 'Galleries', 'artists' => 'Artists', 'redirects' => 'Redirects', 'pages' => 'Content pages'] as $key => $label)
                <a href="{{ route('super.seo.index', ['tab' => $key]) }}"
                   class="px-4 py-2 text-sm rounded-t-lg transition {{ $tab === $key ? 'bg-gray-800 text-white border-b-2 border-purple-500' : 'text-gray-400 hover:text-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        {{-- ── Health tab ───────────────────────────────────────────────--}}
        @if($tab === 'health')
            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                @foreach([
                    ['Indexable galleries', $summary['indexable_galleries']],
                    ['Indexable artists', $summary['indexable_artists']],
                    ['Indexable artworks', $summary['indexable_artworks']],
                    ['Published SEO pages', $summary['published_seo_pages']],
                    ['Active redirects', $summary['active_redirects']],
                ] as [$label, $value])
                    <div class="bg-gray-800 rounded-xl border border-gray-700 p-5">
                        <p class="text-3xl font-bold text-white">{{ number_format($value) }}</p>
                        <p class="text-xs text-gray-500 mt-1 uppercase tracking-wider">{{ $label }}</p>
                    </div>
                @endforeach
            </div>

            <h3 class="text-gray-200 font-semibold mb-3">Issues</h3>
            @if(count($issues) === 0)
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 text-gray-400 text-sm">
                    No SEO quality issues found — every public entity meets its quality gate.
                </div>
            @else
                <div class="bg-gray-800 rounded-xl border border-gray-700 divide-y divide-gray-700/50">
                    @foreach($issues as $issue)
                        <div class="p-4 flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="text-xs px-2 py-0.5 rounded-full font-semibold {{ $issue['severity'] === 'warning' ? 'bg-yellow-900/40 text-yellow-300 border border-yellow-700/40' : 'bg-gray-700/40 text-gray-400 border border-gray-600/40' }}">
                                    {{ $issue['severity'] }}
                                </span>
                                <span class="text-gray-300 text-sm">{{ $issue['label'] }}</span>
                            </div>
                            <span class="text-gray-200 font-bold">{{ number_format($issue['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
            <p class="text-gray-600 text-xs mt-4">
                Data reflects the platform's own content (what crawlers can see). Search-engine performance lives in
                Google Search Console / Bing Webmaster Tools — see docs/MASTER_MANUAL_OPERATIONS.md.
            </p>
        @endif

        {{-- ── Galleries tab ─────────────────────────────────────────────--}}
        @if($tab === 'galleries')
            <form method="GET" class="mb-5 flex gap-3">
                <input type="hidden" name="tab" value="galleries">
                <input type="text" name="q" value="{{ $search }}" placeholder="Search title or slug…"
                       class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm flex-1 max-w-sm focus:border-purple-500 focus:ring-purple-500">
                <select name="filter" class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                    <option value="public" {{ $filter === 'public' ? 'selected' : '' }}>Public &amp; non-empty</option>
                    <option value="issues" {{ $filter === 'issues' ? 'selected' : '' }}>Missing description</option>
                    <option value="all" {{ $filter === 'all' ? 'selected' : '' }}>All</option>
                </select>
                <button type="submit" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-100 text-sm transition">Filter</button>
            </form>

            <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <table class="w-full text-sm text-gray-300">
                    <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Gallery</th>
                            <th class="px-4 py-3 text-left">State</th>
                            <th class="px-4 py-3 text-left">SEO</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @forelse($galleries as $gallery)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-200">{{ $gallery->title ?: '(untitled)' }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        /gallery/{{ $gallery->slug }} · {{ $gallery->images_count }} works
                                        @if($gallery->seoProfile?->title_override) · <span class="text-purple-400">custom title</span>@endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if(!$gallery->is_active)
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-red-900/40 text-red-300 border border-red-700/40">inactive</span>
                                    @elseif($gallery->hasPinProtection())
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700/40 text-gray-400 border border-gray-600/40">PIN</span>
                                    @elseif($gallery->images_count === 0)
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-900/40 text-yellow-300 border border-yellow-700/40">empty</span>
                                    @elseif(trim((string)$gallery->description) === '')
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-900/40 text-yellow-300 border border-yellow-700/40">no description</span>
                                    @else
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-900/40 text-green-300 border border-green-700/40">healthy</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ $gallery->seoProfile?->robots_directive ?: 'auto robots' }}
                                    @if($gallery->seoProfile?->sitemap_include !== null)
                                        · sitemap {{ $gallery->seoProfile->sitemap_include ? 'forced in' : 'forced out' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button"
                                            data-seo-toggle="gallery-{{ $gallery->id }}"
                                            class="text-purple-400 hover:text-purple-300 text-sm transition">
                                        Edit SEO
                                    </button>
                                </td>
                            </tr>
                            <tr class="hidden" id="seo-form-gallery-{{ $gallery->id }}">
                                <td colspan="4" class="px-4 py-4 bg-gray-900/60">
                                    <form method="POST" action="{{ route('super.seo.profile.update', ['type' => 'gallery', 'id' => $gallery->id]) }}" class="grid sm:grid-cols-2 gap-3">
                                        @csrf
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">Title override</label>
                                            <input type="text" name="title_override" value="{{ $gallery->seoProfile?->title_override }}"
                                                   maxlength="200" placeholder="Auto: {{ $gallery->title }} — 3D Virtual Exhibition"
                                                   class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">Robots directive</label>
                                            <input type="text" name="robots_directive" value="{{ $gallery->seoProfile?->robots_directive }}"
                                                   maxlength="100" placeholder="auto (index,follow)"
                                                   class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="block text-xs text-gray-500 mb-1">Description override</label>
                                            <textarea name="description_override" rows="2" maxlength="300"
                                                      placeholder="Auto: generated from gallery content"
                                                      class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">{{ $gallery->seoProfile?->description_override }}</textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">Canonical override</label>
                                            <input type="text" name="canonical_override" value="{{ $gallery->seoProfile?->canonical_override }}"
                                                   maxlength="500" placeholder="auto (public URL)"
                                                   class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                        </div>
                                        <div class="flex gap-3">
                                            <div class="flex-1">
                                                <label class="block text-xs text-gray-500 mb-1">Sitemap</label>
                                                <select name="sitemap_include" class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                                    <option value="">auto</option>
                                                    <option value="1" {{ $gallery->seoProfile?->sitemap_include === true ? 'selected' : '' }}>force include</option>
                                                    <option value="0" {{ $gallery->seoProfile?->sitemap_include === false ? 'selected' : '' }}>force exclude</option>
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="block text-xs text-gray-500 mb-1">Structured data</label>
                                                <select name="structured_data" class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                                    <option value="">auto</option>
                                                    <option value="1" {{ $gallery->seoProfile?->structured_data_enabled === true ? 'selected' : '' }}>enabled</option>
                                                    <option value="0" {{ $gallery->seoProfile?->structured_data_enabled === false ? 'selected' : '' }}>disabled</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="sm:col-span-2 flex justify-end gap-2">
                                            <button type="button" data-seo-toggle="gallery-{{ $gallery->id }}" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm transition">Cancel</button>
                                            <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No galleries match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $galleries->links() }}
        @endif

        {{-- ── Artists tab ────────────────────────────────────────────────--}}
        @if($tab === 'artists')
            <form method="GET" class="mb-5">
                <input type="hidden" name="tab" value="artists">
                <input type="text" name="q" value="{{ $search }}" placeholder="Search artist…"
                       class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm w-full max-w-md focus:border-purple-500 focus:ring-purple-500">
            </form>

            <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <table class="w-full text-sm text-gray-300">
                    <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Artist</th>
                            <th class="px-4 py-3 text-left">Public works</th>
                            <th class="px-4 py-3 text-left">SEO</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @forelse($artists as $artist)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-200">{{ $artist->name }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">/artist/{{ $artist->slug }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $artist->public_works_count }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ $artist->seoProfile?->robots_directive ?: 'auto robots' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" data-seo-toggle="artist-{{ $artist->id }}" class="text-purple-400 hover:text-purple-300 text-sm transition">Edit SEO</button>
                                </td>
                            </tr>
                            <tr class="hidden" id="seo-form-artist-{{ $artist->id }}">
                                <td colspan="4" class="px-4 py-4 bg-gray-900/60">
                                    <form method="POST" action="{{ route('super.seo.profile.update', ['type' => 'artist', 'id' => $artist->id]) }}" class="grid sm:grid-cols-2 gap-3">
                                        @csrf
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">Title override</label>
                                            <input type="text" name="title_override" value="{{ $artist->seoProfile?->title_override }}" maxlength="200"
                                                   placeholder="Auto: {{ $artist->name }} — Artist Profile & 3D Exhibitions"
                                                   class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">Robots directive</label>
                                            <input type="text" name="robots_directive" value="{{ $artist->seoProfile?->robots_directive }}" maxlength="100"
                                                   placeholder="auto (index,follow)"
                                                   class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="block text-xs text-gray-500 mb-1">Description override</label>
                                            <textarea name="description_override" rows="2" maxlength="300"
                                                      placeholder="Auto: generated from bio and works"
                                                      class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">{{ $artist->seoProfile?->description_override }}</textarea>
                                        </div>
                                        <div class="sm:col-span-2 flex justify-end gap-2">
                                            <button type="button" data-seo-toggle="artist-{{ $artist->id }}" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm transition">Cancel</button>
                                            <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No artists match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $artists->links() }}
        @endif

        {{-- ── Redirects tab ──────────────────────────────────────────────--}}
        @if($tab === 'redirects')
            <form method="POST" action="{{ route('super.seo.redirects.store') }}" class="mb-6 bg-gray-800 rounded-xl border border-gray-700 p-5 grid sm:grid-cols-4 gap-3 items-end">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">From path</label>
                    <input type="text" name="source_path" required placeholder="old-exhibition" value="{{ old('source_path') }}"
                           class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">To</label>
                    <input type="text" name="destination" required placeholder="/discover or https://…" value="{{ old('destination') }}"
                           class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status</label>
                    <select name="status_code" class="w-full rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm">
                        <option value="301">301 permanent</option>
                        <option value="302">302 temporary</option>
                        <option value="308">308 permanent (keep method)</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition">Add redirect</button>
            </form>

            <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <table class="w-full text-sm text-gray-300">
                    <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">From</th>
                            <th class="px-4 py-3 text-left">To</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Hits</th>
                            <th class="px-4 py-3 text-left">Active</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @forelse($redirects as $redirect)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-gray-200">/{{ $redirect->source_path }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ $redirect->destination }}</td>
                                <td class="px-4 py-3">{{ $redirect->status_code }}</td>
                                <td class="px-4 py-3">{{ number_format($redirect->hits) }}
                                    @if($redirect->last_hit_at)<span class="text-xs text-gray-600"> · {{ $redirect->last_hit_at->diffForHumans() }}</span>@endif
                                </td>
                                <td class="px-4 py-3">{{ $redirect->is_active ? '✓' : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('super.seo.redirects.destroy', $redirect) }}"
                                          data-confirm="Delete redirect /{{ $redirect->source_path }}?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-sm transition">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No redirects configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $redirects->links() }}
        @endif

        {{-- ── Content pages tab ──────────────────────────────────────────--}}
        @if($tab === 'pages')
            <p class="text-gray-500 text-sm mb-4">
                Create pages with <code class="text-gray-400">php artisan seo:make-page &#123;slug&#125;</code>. Full block editing via tinker.
            </p>
            <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <table class="w-full text-sm text-gray-300">
                    <thead class="bg-gray-900/50 text-xs text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Page</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50">
                        @forelse($seoPages as $page)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-200">{{ $page->title }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">{{ $page->public_url }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $page->type }}</td>
                                <td class="px-4 py-3">
                                    @if($page->status === 'published' && !$page->isScheduled())
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-900/40 text-green-300 border border-green-700/40">published</span>
                                    @elseif($page->isScheduled())
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-900/40 text-blue-300 border border-blue-700/40">scheduled {{ $page->published_at->format('M j') }}</span>
                                    @else
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700/40 text-gray-400 border border-gray-600/40">draft</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $page->updated_at->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('super.seo.pages.toggle', $page) }}">
                                        @csrf
                                        <button type="submit" class="text-purple-400 hover:text-purple-300 text-sm transition">
                                            {{ $page->status === 'published' ? 'Unpublish' : 'Publish' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No SEO pages yet — create one with <code>seo:make-page</code>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $seoPages->links() }}
        @endif
    </div>

    {{-- Toggle inline SEO forms (CSP-safe, no inline handlers) --}}
    <script nonce="@nonce">
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-seo-toggle]');
            if (!btn) return;
            const row = document.getElementById('seo-form-' + btn.dataset.seoToggle);
            if (row) row.classList.toggle('hidden');
        });
    </script>
</x-app-layout>
