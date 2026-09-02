<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Edit: '.$venue->name" :back="route('super.venues.index')" backLabel="Venues">
            <x-slot:meta>
                @if($venue->isArchived())
                    <span class="badge badge-danger">Archived</span>
                @elseif($venue->is_draft)
                    <span class="badge badge-warning">Draft</span>
                @else
                    <span class="badge badge-success">Published</span>
                @endif
            </x-slot:meta>
            <x-slot:actions>
                @if($authoringEnabled && $venue->is_draft && !$venue->isArchived())
                    <form method="POST" action="{{ route('super.venues.publish', $venue) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Publish now
                        </button>
                    </form>
                @elseif($authoringEnabled && !$venue->is_draft)
                    <form method="POST" action="{{ route('super.venues.unpublish', $venue) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-secondary">Back to draft</button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell-mid">
        @if($venue->isArchived())
            <div class="mb-5 bg-amber-950/20 border border-amber-900/50 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3 justify-between">
                <div>
                    <p class="text-sm text-amber-300 font-semibold">This venue is archived</p>
                    <p class="text-xs text-amber-400/70">Hidden from every picker, public page and preview. Galleries already using it keep rendering normally.</p>
                </div>
                @if($authoringEnabled)
                    <form method="POST" action="{{ route('super.venues.unarchive', $venue) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-secondary whitespace-nowrap">Restore to selection</button>
                    </form>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('super.venues.update', $venue) }}" enctype="multipart/form-data" data-busy data-busy-label="Saving…">
            @csrf @method('PUT')

            @include('super-admin.venues._form-fields', ['venue' => $venue, 'categories' => $categories, 'layouts' => $layouts])

            <div class="flex justify-between gap-3 pt-2">
                <a href="{{ route('super.venues.index') }}"
                   class="btn btn-secondary">Back to venues</a>
                <button type="submit"
                        class="btn btn-primary">
                    Save changes
                </button>
            </div>
        </form>

        {{-- ── Iteration 5 "Authoring": live preview + snapshot rollback ── --}}
        <div class="mt-8 grid grid-cols-1 xl:grid-cols-3 gap-5">
            <div class="xl:col-span-2 bg-gray-800/60 border border-gray-700/60 rounded-xl p-5">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="text-gray-200 font-semibold text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        Live preview
                    </h2>
                    @if($previewsEnabled)
                        <a href="{{ route('venues.preview', $venue->slug) }}" target="_blank" rel="noopener" class="text-xs text-brand-400 hover:text-brand-300 transition">Open full ↗</a>
                    @endif
                </div>
                @if($previewsEnabled)
                    <iframe
                        src="{{ route('venues.preview', $venue->slug) }}"
                        title="Live 3D preview of {{ $venue->name }}"
                        class="w-full h-[440px] rounded-lg border border-gray-700/80 bg-gray-950"
                        loading="lazy"
                        sandbox="allow-scripts allow-same-origin allow-pointer-lock"></iframe>
                    <p class="text-xs text-gray-500 mt-2">The same walkable preview customers get (Iteration 1 runtime, sample exhibition). Saving this form reloads the page — and the preview with it.</p>
                @else
                    <p class="text-sm text-gray-500">Previews are disabled (venue_previews flag off).</p>
                @endif
            </div>

            <div class="bg-gray-800/60 border border-gray-700/60 rounded-xl p-5">
                <h2 class="text-gray-200 font-semibold text-sm mb-1 flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Snapshot history
                </h2>
                <p class="text-xs text-gray-500 mb-3">The state right before each of the last saves. Restoring one first snapshots the current state, so a rollback is itself reversible.</p>
                @if($authoringEnabled)
                    @forelse($snapshots as $snapshot)
                        <div class="flex items-center justify-between gap-3 py-2.5 border-t border-gray-700/50">
                            <div class="min-w-0">
                                <p class="text-xs text-gray-300 font-medium truncate">
                                    {{ $snapshot->label ?? 'before save' }}
                                    @if($snapshot->author)
                                        <span class="text-gray-600">· {{ $snapshot->author->name }}</span>
                                    @endif
                                </p>
                                <p class="text-[11px] text-gray-600">{{ $snapshot->created_at->format('Y-m-d H:i') }}</p>
                            </div>
                            <form method="POST" action="{{ route('super.venues.snapshots.restore', [$venue, $snapshot]) }}"
                                  data-confirm="Restore this snapshot? The current state is snapshotted first, so this can be undone." data-confirm-button="Restore">
                                @csrf
                                <button type="submit" class="text-xs text-brand-400 hover:text-brand-300 hover:bg-white/[0.06] px-2 py-1 rounded transition whitespace-nowrap">Restore</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-xs text-gray-600 border-t border-gray-700/50 pt-3">No snapshots yet — they appear here after your first save.</p>
                    @endforelse
                @else
                    <p class="text-xs text-gray-600 border-t border-gray-700/50 pt-3">Snapshot history is disabled (venue_authoring flag off).</p>
                @endif
            </div>
        </div>

        {{-- ── Archive zone (replaces the old delete zone) ── --}}
        <div class="mt-8 bg-red-950/20 border border-red-900/40 rounded-xl p-5">
            <h2 class="text-red-300 font-semibold text-sm mb-2">Retire this venue</h2>
            @if($venue->isArchived())
                <p class="text-xs text-red-400/70 mb-3">Already archived — restore it from the banner above or from the venues table.</p>
            @else
                <p class="text-xs text-red-400/70 mb-3">Archiving hides the venue from every picker, public page and preview. Galleries already using it are NOT affected — they keep rendering this venue. Nothing is permanently deleted; restore is one click.</p>
                <form method="POST" action="{{ route('super.venues.destroy', $venue) }}"
                      data-confirm="Archive venue &quot;{{ $venue->name }}&quot;? {{ $venue->galleries_count ?? $venue->galleries()->count() }} gallery(ies) currently use it — they keep working, but no new gallery can pick this venue." data-confirm-button="Archive">
                    @csrf @method('DELETE')
                    <input type="hidden" name="confirm_usage" value="1">
                    <button type="submit" class="btn btn-danger-ghost">
                        Archive this venue
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
