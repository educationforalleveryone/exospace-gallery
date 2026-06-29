<x-app-layout>
    <x-slot name="header">
        @php
            $activeTeam = auth()->user()->currentTeam();
            $canEdit = !$activeTeam || $activeTeam->canEdit(auth()->user());
        @endphp
        <div class="flex justify-between items-center gap-4">
            <div>
                @if($activeTeam)
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="w-6 h-6 rounded-md bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">{{ strtoupper(substr($activeTeam->name, 0, 1)) }}</span>
                        <h2 class="font-semibold text-xl text-gray-100 leading-tight">{{ $activeTeam->name }}</h2>
                    </div>
                    <p class="text-xs text-gray-500 flex items-center gap-1.5">
                        <span class="capitalize text-{{ $canEdit ? 'indigo' : 'gray' }}-400">{{ ucfirst(auth()->user()->teamRole($activeTeam)) }}</span>
                        <span class="text-gray-700">·</span>
                        <span>Team workspace</span>
                        <span class="text-gray-700">·</span>
                        <a href="{{ route('admin.teams.show', $activeTeam) }}" class="text-gray-500 hover:text-purple-400 transition">Manage team →</a>
                    </p>
                @else
                    <h2 class="font-semibold text-xl text-gray-100 leading-tight">My Galleries</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Personal workspace</p>
                @endif
            </div>
            @if($canEdit && (auth()->user()->canCreateGallery() || $activeTeam))
                <a href="{{ route('admin.galleries.create') }}{{ $activeTeam ? '?team=' . $activeTeam->id : '' }}"
                   class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-5 rounded-lg transition inline-flex items-center gap-2 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Gallery
                </a>
            @elseif(!$canEdit)
                <span class="inline-flex items-center gap-1.5 text-xs bg-gray-700/60 border border-gray-600 text-gray-400 px-3 py-1.5 rounded-lg">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    View only
                </span>
            @else
                <button onclick="openModal('upgrade-modal')"
                        class="bg-gradient-to-r from-purple-600/80 to-indigo-600/80 hover:from-purple-600 hover:to-indigo-600 text-white font-semibold py-2 px-5 rounded-lg transition inline-flex items-center cursor-pointer gap-2 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                    Upgrade for more
                </button>
            @endif
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('upgrade'))
                <script>document.addEventListener('DOMContentLoaded', () => openModal('upgrade-modal'));</script>
            @endif

            @if(!auth()->user()->canCreateGallery() && !$activeTeam)
            <div class="mb-6 flex items-center gap-3 bg-purple-950/50 border border-purple-600/40 rounded-xl px-4 py-3">
                <svg class="w-4 h-4 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="flex-1 text-sm text-purple-200">You're on the Free plan — 1 gallery maximum. Upgrade to Pro for unlimited galleries and more image slots.</p>
                <a href="/pricing" class="flex-shrink-0 text-xs font-semibold bg-purple-600 hover:bg-purple-500 text-white px-3 py-1.5 rounded-lg transition whitespace-nowrap">Upgrade — $29</a>
            </div>
            @endif

            @if($galleries->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($galleries as $gallery)
                        @php
                            $coverImage = $gallery->images()->first();
                        @endphp
                        <div class="bg-gray-800 border border-gray-700/80 rounded-xl overflow-hidden hover:border-purple-500/40 hover:shadow-lg hover:shadow-purple-900/10 transition-all duration-200 group card-lift">
                            
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
                                <div class="absolute top-3 right-3 flex flex-col items-end gap-1.5">
                                    @if($gallery->is_active)
                                        <span class="bg-green-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Live</span>
                                    @else
                                        <span class="bg-gray-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Draft</span>
                                    @endif
                                </div>
                                <!-- Venue Badge -->
                                @if($gallery->venueTemplate)
                                <div class="absolute top-3 left-3">
                                    <span class="bg-black/60 backdrop-blur-sm text-purple-300 text-xs px-2.5 py-1 rounded-full font-medium border border-purple-500/30">
                                        {{ match($gallery->venueTemplate->slug) {
                                            'white-cube'       => '',
                                            'industrial-loft'  => '',
                                            'dark-museum'      => '',
                                            'zen-gallery'      => '',
                                            'luxury-penthouse' => '',
                                            'cyber-gallery'    => '',
                                            'sculpture-garden' => '',
                                            'infinite-void'    => '',
                                            default            => ''
                                        } }}{{ $gallery->venueTemplate->name }}
                                    </span>
                                </div>
                                @endif
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
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}" target="_blank" 
                                       class="bg-gray-700 hover:bg-gray-600 text-center text-gray-100 font-medium py-2 px-3 rounded-lg transition text-sm">
                                        View
                                    </a>
                                    <button onclick="shareGallery('{{ route('gallery.view', $gallery->slug) }}', '{{ $gallery->title }}')"
                                       class="bg-blue-600 hover:bg-blue-700 text-center text-white font-medium py-2 px-3 rounded-lg transition text-sm">
                                        Share
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}"
                                       class="bg-purple-600 hover:bg-purple-700 text-center text-white font-medium py-2 px-3 rounded-lg transition text-sm">
                                        Edit
                                    </a>
                                    @if($canEdit)
                                    <button onclick="confirmDelete({{ $gallery->id }}, '{{ addslashes($gallery->title) }}')"
                                       class="bg-red-700/80 hover:bg-red-600 text-center text-white font-medium py-2 px-3 rounded-lg transition text-sm">
                                        Delete
                                    </button>
                                    @endif
                                </div>
                                @if($canEdit)
                                <form action="{{ route('admin.galleries.duplicate', $gallery) }}" method="POST" class="mt-2"
                                      onsubmit="return confirm('Duplicate this gallery? A copy with all images will be created.');">
                                    @csrf
                                    <button type="submit"
                                            class="w-full bg-gray-700 hover:bg-gray-600 text-center text-gray-200 font-medium py-2 px-3 rounded-lg transition text-sm flex items-center justify-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        Duplicate
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                
                <div class="mt-6">
                    {{ $galleries->links() }}
                </div>
            @else
                <!-- PREMIUM EMPTY STATE: First-Time User Onboarding -->
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
                                Choose a Venue. <span class="bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">Open Your Exhibition.</span>
                            </h2>
                            <p class="text-lg text-gray-400 max-w-2xl mx-auto">
                                Pick from distinct 3D spaces — White Cube, Industrial Loft, Dark Museum and more. Each venue has its own architecture, scale, and atmosphere.
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
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Choose Your Venue</h3>
                                        <p class="text-sm text-gray-400">Pick the space that fits your work — each venue has a distinct architecture</p>
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
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Hang Your Artworks</h3>
                                        <p class="text-sm text-gray-400">Upload up to {{ auth()->user()->max_images }} images — placed automatically on the walls</p>
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
                                        <h3 class="text-lg font-bold text-gray-100 mb-2">Walk In. Share It.</h3>
                                        <p class="text-sm text-gray-400">Enter your venue in 3D and send the link to anyone in the world</p>
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
                                    Free plan includes {{ auth()->user()->max_galleries }} galleries with {{ auth()->user()->max_images }} images each
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

            {{-- Quick-share shortcuts: QR code + embed snippet --}}
            <div class="grid grid-cols-2 gap-3 mt-4">
                <a href="#" onclick="openQrCode(); return false;"
                   class="bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm font-medium py-2.5 px-4 rounded-lg transition text-center flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h.01M4 20h4M12 8a4 4 0 100-8 4 4 0 000 8zm8-4a4 4 0 11-8 0 4 4 0 018 0zM4 4h4v4H4V4z"/></svg>
                    QR Code
                </a>
                <a href="#" onclick="copyEmbedSnippet(); return false;"
                   class="bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm font-medium py-2.5 px-4 rounded-lg transition text-center flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    Embed code
                </a>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-xl bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-100">Delete Gallery</h3>
                    <p class="text-sm text-gray-400">This action cannot be undone.</p>
                </div>
            </div>
            <p class="text-gray-300 mb-6">Are you sure you want to permanently delete <span id="delete-gallery-name" class="font-semibold text-white"></span>? All images and analytics data will be lost.</p>
            <form id="delete-form" method="POST">
                @csrf
                @method('DELETE')
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="document.getElementById('delete-modal').style.display='none'"
                            class="bg-gray-700 hover:bg-gray-600 text-gray-200 font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                        Cancel
                    </button>
                    <button type="submit"
                            class="bg-red-600 hover:bg-red-500 text-white font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                        Yes, Delete Gallery
                    </button>
                </div>
            </form>
        </div>
    </div>

    <x-upgrade-modal />

    <script>
        function confirmDelete(galleryId, galleryTitle) {
            document.getElementById('delete-gallery-name').textContent = galleryTitle;
            document.getElementById('delete-form').action = '/admin/galleries/' + galleryId;
            document.getElementById('delete-modal').style.display = 'flex';
        }

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
                document.getElementById('delete-modal').style.display = 'none';
            }
        });

        // Open QR code in new tab — the route returns a PNG
        function openQrCode() {
            const url = document.getElementById('share-url').value;
            // Extract the slug from the share URL
            const match = url.match(/\/gallery\/([^\/?#]+)/);
            if (!match) return;
            const slug = match[1];
            window.open('/gallery/' + slug + '/qr', '_blank', 'width=640,height=640');
        }

        // Copy an iframe embed snippet for embedding the gallery elsewhere
        function copyEmbedSnippet() {
            const url = document.getElementById('share-url').value + '?embed=1';
            const snippet = `<iframe src="${url}" width="1024" height="640" style="border:0;max-width:100%;" allow="fullscreen; autoplay" loading="lazy" title="Exospace 3D Gallery"></iframe>`;
            navigator.clipboard.writeText(snippet).then(() => {
                const btn = event.target.closest('a');
                const original = btn.innerHTML;
                btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copied!';
                setTimeout(() => { btn.innerHTML = original; }, 2000);
            }).catch(() => alert('Could not copy. Here is the snippet:\n\n' + snippet));
        }
    </script>

</x-app-layout>