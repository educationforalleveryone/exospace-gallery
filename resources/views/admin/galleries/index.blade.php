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
                <!-- ✨ PREMIUM EMPTY STATE: First-Time User Onboarding -->
                <div class="max-w-4xl mx-auto">
                    <!-- Main Hero Card -->
                    <div class="bg-gradient-to-br from-gray-800 via-gray-800 to-purple-900/20 border border-gray-700 rounded-2xl overflow-hidden shadow-2xl">
                        
                        <!-- Animated Header Section -->
                        <div class="relative bg-gradient-to-r from-purple-600/10 to-indigo-600/10 p-12 text-center border-b border-gray-700/50">
                            <!-- Floating 3D Cube Animation -->
                            <div class="relative inline-block mb-6">
                                <div class="absolute inset-0 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-2xl blur-2xl opacity-20 animate-pulse"></div>
                                <div class="relative bg-gradient-to-br from-purple-600 to-indigo-600 w-24 h-24 rounded-2xl flex items-center justify-center transform hover:rotate-12 transition-transform duration-500">
                                    <svg class="w-14 h-14 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                            </div>
                            
                            <h2 class="text-3xl md:text-4xl font-bold text-gray-100 mb-3">
                                Create Your First <span class="bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">3D Masterpiece</span>
                            </h2>
                            <p class="text-lg text-gray-400 max-w-2xl mx-auto">
                                Transform your images into an immersive virtual gallery in under 60 seconds
                            </p>
                        </div>
                        
                        <!-- 3-Step Blueprint -->
                        <div class="p-8 md:p-12">
                            <div class="grid md:grid-cols-3 gap-6 mb-10">
                                
                                <!-- Step 1: Name Your Gallery -->
                                <div class="relative group">
                                    <div class="absolute inset-0 bg-gradient-to-br from-purple-600/5 to-transparent rounded-xl opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                    <div class="relative bg-gray-900/50 border border-gray-700 rounded-xl p-6 text-center hover:border-purple-500/50 transition-all duration-300">
                                        <div class="bg-purple-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-purple-500/30">
                                            <span class="text-2xl font-bold text-purple-400">1</span>
                                        </div>
                                        <div class="mb-3">
                                            <svg class="w-10 h-10 mx-auto text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Name Your Exhibition</h3>
                                        <p class="text-sm text-gray-400">Give your gallery a memorable title and description</p>
                                    </div>
                                </div>
                                
                                <!-- Step 2: Upload Images -->
                                <div class="relative group">
                                    <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/5 to-transparent rounded-xl opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                    <div class="relative bg-gray-900/50 border border-gray-700 rounded-xl p-6 text-center hover:border-indigo-500/50 transition-all duration-300">
                                        <div class="bg-indigo-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-indigo-500/30">
                                            <span class="text-2xl font-bold text-indigo-400">2</span>
                                        </div>
                                        <div class="mb-3">
                                            <svg class="w-10 h-10 mx-auto text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Upload Your Art</h3>
                                        <p class="text-sm text-gray-400">Add up to {{ auth()->user()->max_images }} stunning images to your space</p>
                                    </div>
                                </div>
                                
                                <!-- Step 3: Enter 3D Space -->
                                <div class="relative group">
                                    <div class="absolute inset-0 bg-gradient-to-br from-blue-600/5 to-transparent rounded-xl opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                    <div class="relative bg-gray-900/50 border border-gray-700 rounded-xl p-6 text-center hover:border-blue-500/50 transition-all duration-300">
                                        <div class="bg-blue-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-blue-500/30">
                                            <span class="text-2xl font-bold text-blue-400">3</span>
                                        </div>
                                        <div class="mb-3">
                                            <svg class="w-10 h-10 mx-auto text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5"></path>
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Experience in 3D</h3>
                                        <p class="text-sm text-gray-400">Walk through your virtual gallery and share with the world</p>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- CTA Section -->
                            <div class="text-center">
                                <a href="{{ route('admin.galleries.create') }}" 
                                   class="inline-flex items-center gap-3 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-4 px-10 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-purple-500/50 text-lg group">
                                    <svg class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <span>Start Building Now</span>
                                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                </a>
                                
                                <p class="mt-6 text-sm text-gray-500">
                                    🎨 Free plan includes {{ auth()->user()->max_galleries }} galleries with {{ auth()->user()->max_images }} images each
                                </p>
                            </div>
                        </div>
                        
                    </div>
                    
                    <!-- Additional Tips Card -->
                    <div class="mt-8 bg-blue-900/10 border border-blue-700/30 rounded-xl p-6">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-blue-400 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-base font-semibold text-blue-300 mb-2">💡 Pro Tip</h4>
                                <p class="text-sm text-blue-200/80">For best results, use high-quality images (1920x1080 or larger) in JPG or PNG format. Each gallery can showcase your work in stunning 3D detail!</p>
                            </div>
                        </div>
                    </div>
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