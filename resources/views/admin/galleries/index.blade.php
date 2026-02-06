<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                {{ __('My Galleries') }}
            </h2>
            @if(auth()->user()->canCreateGallery())
                <a href="{{ route('admin.galleries.create') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition inline-flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    New Gallery
                </a>
            @else
                <button onclick="document.getElementById('upgrade-modal').style.display='flex'" class="bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold py-2 px-6 rounded-lg transition inline-flex items-center cursor-pointer border border-gray-600">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    New Gallery
                    <span class="ml-2 text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full">Pro</span>
                </button>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if(session('status'))
                <div class="mb-6 p-4 bg-green-900 border border-green-700 text-green-200 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            @if(session('upgrade'))
                <script>document.addEventListener('DOMContentLoaded', () => document.getElementById('upgrade-modal').style.display='flex');</script>
            @endif

            @if($galleries->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($galleries as $gallery)
                        @php
                            $coverImage = $gallery->images()->first();
                        @endphp
                        <div class="bg-gray-800 border border-gray-700 rounded-lg overflow-hidden hover:border-purple-500 transition shadow-lg group">
                            
                            <!-- Cover Image -->
                            <div class="relative aspect-video bg-gradient-to-br from-purple-900/20 to-indigo-900/20 overflow-hidden">
                                @if($coverImage)
                                    <img src="{{ asset($coverImage->path) }}" 
                                         alt="{{ $gallery->title }}"
                                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900/90 via-gray-900/20 to-transparent"></div>
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <svg class="w-20 h-20 text-purple-500/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900/90 via-gray-900/20 to-transparent"></div>
                                @endif
                                
                                <!-- Status Badge -->
                                <div class="absolute top-3 right-3">
                                    @if($gallery->is_active)
                                        <span class="bg-green-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Live</span>
                                    @else
                                        <span class="bg-gray-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Draft</span>
                                    @endif
                                </div>
                            </div>
                            
                            <!-- Content -->
                            <div class="p-6">
                                <h3 class="text-xl font-bold text-gray-100 mb-2 line-clamp-1">{{ $gallery->title }}</h3>
                                <p class="text-sm text-gray-400 mb-4 line-clamp-2 min-h-[2.5rem]">
                                    {{ $gallery->description ?: 'No description' }}
                                </p>
                                
                                <!-- Stats -->
                                <div class="flex items-center space-x-4 text-sm text-gray-500 mb-4 pb-4 border-b border-gray-700">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        {{ $gallery->images->count() }}
                                    </span>
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        {{ number_format($gallery->view_count) }}
                                    </span>
                                </div>
                                
                                <!-- Actions -->
                                <div class="grid grid-cols-3 gap-2">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}" target="_blank" 
                                       class="bg-gray-700 hover:bg-gray-600 text-center text-gray-100 font-medium py-2 px-3 rounded-lg transition text-sm">
                                        View
                                    </a>
                                    <button onclick="shareGallery('{{ route('gallery.view', $gallery->slug) }}', '{{ $gallery->title }}')"
                                       class="bg-blue-600 hover:bg-blue-700 text-center text-white font-medium py-2 px-3 rounded-lg transition text-sm">
                                        Share
                                    </button>
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}" 
                                       class="bg-purple-600 hover:bg-purple-700 text-center text-white font-medium py-2 px-3 rounded-lg transition text-sm">
                                        Edit
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                
                <div class="mt-6">
                    {{ $galleries->links() }}
                </div>
            @else
                <div class="bg-gray-800 border border-gray-700 rounded-lg p-12 text-center">
                    <div class="mb-6">
                        <svg class="w-20 h-20 mx-auto text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-300 mb-2">No galleries yet</h3>
                    <p class="text-gray-500 mb-6">Create your first 3D gallery to get started</p>
                    <a href="{{ route('admin.galleries.create') }}" class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 px-8 rounded-lg transition">
                        Create Your First Gallery
                    </a>
                </div>
            @endif

        </div>
    </div>

    <!-- Share Modal -->
    <div id="share-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-8 max-w-lg w-full mx-4">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-bold text-gray-100">Share Gallery</h3>
                <button onclick="document.getElementById('share-modal').style.display='none'" class="text-gray-400 hover:text-gray-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <p class="text-gray-400 mb-4" id="share-title">Gallery Name</p>
            
            <div class="bg-gray-900 border border-gray-700 rounded-lg p-4 mb-4">
                <div class="flex items-center justify-between gap-3">
                    <input type="text" id="share-url" readonly class="bg-transparent text-gray-300 flex-1 outline-none text-sm" value="">
                    <button onclick="copyShareUrl()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition text-sm font-medium flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        <span id="copy-btn-text">Copy</span>
                    </button>
                </div>
            </div>
            
            <div class="bg-blue-900/20 border border-blue-700/30 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-blue-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-sm text-blue-300">Share this link with anyone to let them explore your 3D gallery in their browser.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Upgrade Modal -->
    <div id="upgrade-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-8 max-w-md w-full mx-4 text-center">
            <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center mx-auto mb-5">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-gray-100 mb-2">Upgrade to Pro</h3>
            <p class="text-gray-400 text-sm mb-1">
                You've reached the gallery limit on your <span class="text-gray-300 font-semibold">Free</span> plan.
            </p>
            <p class="text-gray-500 text-sm mb-6">
                Upgrade to <span class="text-purple-400 font-semibold">Pro ($29)</span> for unlimited galleries and watermark removal.
            </p>
            <div class="flex gap-3 justify-center">
                <a href="/pricing" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition text-sm">
                    See Plans
                </a>
                <button onclick="document.getElementById('upgrade-modal').style.display='none'" class="bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold py-2 px-5 rounded-lg transition text-sm">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        function shareGallery(url, title) {
            document.getElementById('share-url').value = url;
            document.getElementById('share-title').textContent = title;
            document.getElementById('share-modal').style.display = 'flex';
            document.getElementById('copy-btn-text').textContent = 'Copy';
        }

        function copyShareUrl() {
            const urlInput = document.getElementById('share-url');
            const btnText = document.getElementById('copy-btn-text');
            
            navigator.clipboard.writeText(urlInput.value).then(() => {
                btnText.textContent = 'Copied!';
                setTimeout(() => {
                    btnText.textContent = 'Copy';
                }, 2000);
            }).catch(err => {
                // Fallback for older browsers
                urlInput.select();
                document.execCommand('copy');
                btnText.textContent = 'Copied!';
                setTimeout(() => {
                    btnText.textContent = 'Copy';
                }, 2000);
            });
        }

        // Close modals on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.getElementById('share-modal').style.display = 'none';
                document.getElementById('upgrade-modal').style.display = 'none';
            }
        });
    </script>

</x-app-layout>