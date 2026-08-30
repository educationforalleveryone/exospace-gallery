<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$user->name.'\'s Galleries'"
                       :description="$user->email.' · '.strtoupper($user->plan).' Plan'"
                       :back="route('super.index')" backLabel="Master Control"/>
    </x-slot>

    <div class="page-shell">

    <!-- Galleries List -->
    <div class="pt-2">
        @if($galleries->count() === 0)
            <div class="empty-state card border-dashed">
                <h2 class="section-title mb-1">No galleries yet</h2>
                <p class="text-sm text-gray-500">This user hasn't created any galleries.</p>
            </div>
        @else
            <div class="grid gap-6">
                @foreach($galleries as $gallery)
                    <div class="card card-pad">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3 mb-2">
                                    <h3 class="text-base font-semibold break-words">{{ $gallery->title }}</h3>
                                    @if($gallery->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-danger">Inactive</span>
                                    @endif
                                </div>
                                
                                @if($gallery->description)
                                    <p class="text-gray-400 mb-4">{{ $gallery->description }}</p>
                                @endif

                                <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-400">
                                    <span>{{ $gallery->images_count }} images</span>
                                    <span>{{ number_format($gallery->view_count) }} views</span>
                                    <span>Created {{ $gallery->created_at->diffForHumans() }}</span>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}" 
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-secondary">
                                        View Gallery
                                    </a>
                                    
                                    <form method="POST" action="{{ route('super.toggleGallery', $gallery) }}" class="inline">
                                        @csrf
                                        <button type="submit" 
                                                class="btn btn-sm {{ $gallery->is_active ? 'btn-danger-ghost' : 'btn-primary' }}">
                                            {{ $gallery->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="shrink-0 sm:text-right text-sm text-gray-400">
                                <div>Wall: {{ ucfirst($gallery->wall_texture) }}</div>
                                <div>Frame: {{ ucfirst($gallery->frame_style) }}</div>
                                <div>Lighting: {{ ucfirst($gallery->lighting_preset) }}</div>
                            </div>
                        </div>

                        <!-- Gallery Images Preview -->
                        @if($gallery->images->count() > 0)
                            <div class="mt-6 pt-6 border-t border-gray-700">
                                <h4 class="eyebrow mb-3">Images</h4>
                                <div class="grid grid-cols-4 sm:grid-cols-6 lg:grid-cols-8 xl:grid-cols-12 gap-2">
                                    @foreach($gallery->images->take(12) as $image)
                                        <div class="aspect-square bg-gray-800 rounded overflow-hidden">
                                            <img src="{{ asset($image->path) }}" 
                                                 alt="{{ $image->title }}"
                                                 class="w-full h-full object-cover">
                                        </div>
                                    @endforeach
                                    @if($gallery->images->count() > 12)
                                        <div class="aspect-square bg-gray-800 rounded flex items-center justify-center text-gray-400">
                                            +{{ $gallery->images->count() - 12 }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    </div><!-- /.page-shell -->
</x-app-layout>