<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Events" :back="route('admin.galleries.edit', $gallery)" :backLabel="$gallery->title">
            <x-slot:actions>
                <a href="{{ route('admin.galleries.events.create', $gallery) }}" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New event
                </a>
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell-mid">

        @if($upcoming->count() > 0)
            <h2 class="text-gray-200 font-semibold mb-3">Upcoming ({{ $upcoming->count() }})</h2>
            <div class="space-y-3 mb-8">
                @foreach($upcoming as $event)
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-brand-900/40 text-brand-300 border border-brand-700/40">{{ $event->typeLabel() }}</span>
                                    @if(!$event->is_active)
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-700 text-gray-400">Draft</span>
                                    @endif
                                </div>
                                <h4 class="text-gray-100 font-semibold text-lg">{{ $event->title }}</h4>
                                <p class="text-gray-400 text-sm mt-1">{{ $event->starts_at->format('l, F j, Y \a\t g:i A') }}@if($event->ends_at) – {{ $event->ends_at->format('g:i A') }}@endif</p>
                                @if($event->location_name)
                                    <p class="text-gray-500 text-xs mt-1">{{ $event->location_name }}</p>
                                @endif
                                <div class="flex items-center gap-4 mt-3 text-sm">
                                    <a href="{{ route('admin.galleries.events.rsvps', [$gallery, $event]) }}" class="text-blue-400 hover:text-blue-300">
                                        {{ $event->rsvps->count() }} RSVP{{ $event->rsvps->count() === 1 ? '' : 's' }}
                                    </a>
                                    @if($event->capacity)
                                        <span class="text-gray-500">capacity {{ $event->capacity }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex gap-2 flex-shrink-0">
                                <a href="{{ route('admin.galleries.events.edit', [$gallery, $event]) }}" class="btn btn-sm btn-secondary">Edit</a>
                                <form method="POST" action="{{ route('admin.galleries.events.destroy', [$gallery, $event]) }}" data-confirm="Delete this event? All RSVPs will also be deleted." data-confirm-button="Delete">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger-ghost">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-12 bg-gray-800/50 rounded-xl border border-gray-700/50 mb-8">
                <p class="text-gray-400 mb-1">No upcoming events.</p>
                <p class="text-gray-500 text-sm mb-4">Host an opening reception, artist talk, or walkthrough.</p>
                <a href="{{ route('admin.galleries.events.create', $gallery) }}" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Create event
                </a>
            </div>
        @endif

        @if($past->count() > 0)
            <h2 class="text-gray-200 font-semibold mb-3">Past events</h2>
            <div class="space-y-2">
                @foreach($past as $event)
                    <div class="bg-gray-800/40 border border-gray-700/40 rounded-lg p-3 flex items-center justify-between opacity-75">
                        <div>
                            <span class="text-gray-300 font-medium text-sm">{{ $event->title }}</span>
                            <span class="text-gray-500 text-xs ml-2">{{ $event->starts_at->format('M j, Y') }}</span>
                        </div>
                        <a href="{{ route('admin.galleries.events.rsvps', [$gallery, $event]) }}" class="text-xs text-blue-400 hover:text-blue-300">{{ $event->rsvps->count() }} RSVPs →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
