<x-app-layout>
    <x-slot name="header">
        @php
            $activeTeam = auth()->user()->currentTeam();
            $canEdit = !$activeTeam || $activeTeam->canEdit(auth()->user());
        @endphp
        <x-page-header>
            <x-slot:heading>
                @if($activeTeam)
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="w-6 h-6 rounded-md bg-brand-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0" aria-hidden="true">{{ strtoupper(substr($activeTeam->name, 0, 1)) }}</span>
                        <h1 class="page-title break-words">{{ $activeTeam->name }}</h1>
                    </div>
                @else
                    <h1 class="page-title">My Galleries</h1>
                @endif
            </x-slot:heading>
            @if($activeTeam)
                <x-slot:description>
                    <span class="capitalize text-{{ $canEdit ? 'brand' : 'gray' }}-400">{{ ucfirst(auth()->user()->teamRole($activeTeam)) }}</span>
                    <span class="text-gray-700">·</span>
                    <span>Team workspace</span>
                    <span class="text-gray-700">·</span>
                    <a href="{{ route('admin.teams.show', $activeTeam) }}" class="text-gray-500 hover:text-brand-400 transition">Manage team →</a>
                </x-slot:description>
            @else
                <x-slot:description>Personal workspace</x-slot:description>
            @endif
            <x-slot:actions>
                @if($canEdit && (auth()->user()->canCreateGallery() || $activeTeam))
                    <a href="{{ route('admin.galleries.create') }}{{ $activeTeam ? '?team=' . $activeTeam->id : '' }}"
                       class="btn btn-primary shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New Gallery
                    </a>
                @elseif(!$canEdit)
                    <span class="inline-flex items-center gap-1.5 text-xs bg-gray-700/60 border border-gray-600 text-gray-400 px-3 py-1.5 rounded-lg shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        View only
                    </span>
                @else
                    <button data-click="openModal" data-arg="upgrade-modal"
                            class="btn btn-primary flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                        Upgrade for more
                    </button>
                @endif
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell">

            @if(session('upgrade'))
                <script nonce="@nonce">document.addEventListener('DOMContentLoaded', () => openModal('upgrade-modal'));</script>
            @endif

            @if(!auth()->user()->canCreateGallery() && !$activeTeam)
            <div class="mb-6 alert alert-brand">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="flex-1">You're on the Free plan — 1 gallery maximum. Upgrade to Pro for {{ config('plans.limits.pro.max_galleries') }} galleries and more image slots.</p>
                <a href="/pricing" class="btn btn-sm btn-primary shrink-0 whitespace-nowrap">Upgrade — $29</a>
            </div>
            @endif

            @if($galleries->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($galleries as $gallery)
                        @php
                            $coverImage = $gallery->images()->first();
                        @endphp
                        <div class="card overflow-hidden group">
                            
                            <!-- Cover Image -->
                            <div class="relative aspect-video bg-gradient-to-br from-brand-900/20 to-brand-900/20 overflow-hidden">
                                @if($coverImage)
                                    <img src="{{ $coverImage->public_url }}"
                                         srcset="{{ $coverImage->srcset }}"
                                         sizes="(max-width: 768px) 50vw, (max-width: 1200px) 33vw, 25vw"
                                         alt="{{ $gallery->title }}"
                                         loading="lazy" decoding="async"
                                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900/90 via-gray-900/20 to-transparent"></div>
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <svg class="w-20 h-20 text-brand-500/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900/90 via-gray-900/20 to-transparent"></div>
                                @endif
                                
                                <!-- Status Badge -->
                                <div class="absolute top-3 right-3 flex flex-col items-end gap-1.5">
                                    @if($gallery->is_active)
                                        <span class="bg-emerald-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Live</span>
                                    @else
                                        <span class="bg-gray-500/90 backdrop-blur-sm text-white text-xs px-2.5 py-1 rounded-full font-medium">Draft</span>
                                    @endif
                                </div>
                                <!-- Venue Badge -->
                                @if($gallery->venueTemplate)
                                <div class="absolute top-3 left-3">
                                    <span class="bg-black/60 backdrop-blur-sm text-brand-300 text-xs px-2.5 py-1 rounded-full font-medium border border-brand-500/30">
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
                                <h2 class="text-base font-semibold text-gray-100 mb-2 line-clamp-1">{{ $gallery->title }}</h2>
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
                                {{-- ITERATION-2: draft cards offer Preview + Publish (the public
                                     URL 404s while draft, so View/Share are meaningless). --}}
                                @if($gallery->is_active)
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}" target="_blank" class="btn btn-secondary">
                                        View
                                    </a>
                                    <button data-click="shareGallery" data-args='[{{ json_encode([route('gallery.view', $gallery->slug), $gallery->title]) }}]' class="btn btn-secondary">
                                        Share
                                    </button>
                                </div>
                                @elseif($canEdit)
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <a href="{{ route('admin.galleries.preview', $gallery) }}" target="_blank" class="btn btn-secondary">
                                        Preview
                                    </a>
                                    @if($gallery->images->isNotEmpty())
                                    <form action="{{ route('admin.galleries.publish', $gallery) }}" method="POST"
                                          data-busy data-busy-label="Publishing…">
                                        @csrf
                                        <button type="submit" class="btn btn-primary w-full">
                                            Publish
                                        </button>
                                    </form>
                                    @else
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}#image-upload-dropzone"
                                       title="Upload at least one artwork to publish"
                                       aria-disabled="true"
                                       class="btn btn-secondary opacity-60 cursor-not-allowed">
                                        Publish
                                    </a>
                                    @endif
                                </div>
                                @endif
                                <div class="grid grid-cols-2 gap-2">
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}" class="btn btn-primary">
                                        Edit
                                    </a>
                                    @if($canEdit)
                                    <button data-click="confirmDelete" data-args='[{{ $gallery->id }}, {{ json_encode($gallery->title) }}]' class="btn btn-danger">
                                        Delete
                                    </button>
                                    @endif
                                </div>
                                @if($canEdit)
                                <form action="{{ route('admin.galleries.duplicate', $gallery) }}" method="POST" class="mt-2"
                                      data-confirm="Duplicate this gallery? A copy with all images will be created."
                                      data-confirm-button="Duplicate" data-confirm-danger="false">
                                    @csrf
                                    <button type="submit"
                                            class="btn btn-sm btn-secondary w-full">
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
                <!-- PREMIUM EMPTY STATE: First-Time User Onboarding.
                     ITERATION-9: tamed to the design language — one gradient
                     (the header band), no pulsing blur glow, no shadow-2xl,
                     card/well recipes instead of nested off-system surfaces,
                     h2 no longer outweighs the page title. -->
                <div class="max-w-4xl mx-auto">
                    <!-- Main Hero Card -->
                    <div class="card overflow-hidden">

                        <!-- Header Section -->
                        <div class="bg-gradient-to-r from-brand-600/10 to-brand-600/10 py-10 px-6 text-center border-b border-gray-700/50">
                            <!-- 3D Cube -->
                            <div class="relative inline-block mb-6">
                                <div class="bg-brand-600 w-20 h-20 rounded-2xl flex items-center justify-center">
                                    <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                            </div>

                            <h2 class="text-2xl font-semibold tracking-tight text-gray-100 mb-3">
                                Choose a Venue. <span class="gradient-text">Open Your Exhibition.</span>
                            </h2>
                            <p class="text-base text-gray-400 max-w-2xl mx-auto">
                                Pick from distinct 3D spaces — White Cube, Industrial Loft, Dark Museum and more. Each venue has its own architecture, scale, and atmosphere.
                            </p>
                        </div>

                        <!-- 3-Step Blueprint -->
                        <div class="p-6 md:p-8">
                            <div class="grid md:grid-cols-3 gap-6 mb-8">

                                <!-- Step 1: Name Your Gallery -->
                                <div class="well p-6 text-center">
                                    <div class="bg-brand-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-brand-500/30">
                                        <span class="text-xl font-semibold text-brand-400 text-numeric">1</span>
                                    </div>
                                    <div class="mb-3">
                                        <svg class="w-10 h-10 mx-auto text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="modal-title mb-2">Choose Your Venue</h3>
                                    <p class="text-sm text-gray-400">Pick the space that fits your work — each venue has a distinct architecture</p>
                                </div>

                                <!-- Step 2: Upload Images -->
                                <div class="well p-6 text-center">
                                    <div class="bg-brand-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-brand-500/30">
                                        <span class="text-xl font-semibold text-brand-400 text-numeric">2</span>
                                    </div>
                                    <div class="mb-3">
                                        <svg class="w-10 h-10 mx-auto text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="modal-title mb-2">Hang Your Artworks</h3>
                                    <p class="text-sm text-gray-400">Upload up to {{ auth()->user()->max_images }} images — placed automatically on the walls</p>
                                </div>

                                <!-- Step 3: Enter 3D Space -->
                                <div class="well p-6 text-center">
                                    <div class="bg-blue-600/20 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 border border-blue-500/30">
                                        <span class="text-xl font-semibold text-blue-400 text-numeric">3</span>
                                    </div>
                                    <div class="mb-3">
                                        <svg class="w-10 h-10 mx-auto text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5"></path>
                                        </svg>
                                    </div>
                                    <h3 class="modal-title mb-2">Walk In. Share It.</h3>
                                    <p class="text-sm text-gray-400">Enter your venue in 3D and send the link to anyone in the world</p>
                                </div>

                            </div>

                            <!-- CTA Section -->
                            <div class="text-center">
                                <a href="{{ route('admin.galleries.create') }}"
                                   class="btn btn-primary btn-lg">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <span>Start Building Now</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                </a>

                                <p class="mt-6 text-sm text-gray-500">
                                    Free plan includes {{ auth()->user()->max_galleries }} galleries with {{ auth()->user()->max_images }} images each
                                </p>
                            </div>
                        </div>

                    </div>

                    <!-- Additional Tips -->
                    <div class="mt-8 alert alert-info items-start">
                        <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div>
                            <p class="font-semibold mb-1">💡 Pro Tip</p>
                            <p class="opacity-90">For best results, use high-quality images (1920x1080 or larger) in JPG or PNG format. Each gallery can showcase your work in stunning 3D detail!</p>
                        </div>
                    </div>
                </div>
            @endif
    </div>

    <!-- Share Modal -->
    <div id="share-modal" role="dialog" aria-modal="true" aria-labelledby="share-modal-title"
         style="display:none;"
         class="fixed inset-0 z-[60] items-center justify-center overflow-y-auto p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-gray-800 border border-gray-600/50 rounded-xl p-8 max-w-lg w-full shadow-modal">
            <div class="flex items-center justify-between mb-6">
                <h3 id="share-modal-title" class="modal-title">Share Gallery</h3>
                <button data-click="closeModalById" data-arg="share-modal" class="modal-close" aria-label="Close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <p class="text-gray-400 mb-4" id="share-title">Gallery Name</p>
            
            <div class="bg-gray-900 border border-gray-700 rounded-lg p-4 mb-4">
                <div class="flex items-center justify-between gap-3">
                    <input type="text" id="share-url" readonly class="bg-transparent text-gray-300 flex-1 outline-none text-sm" value="">
                    <button data-click="copyShareUrl" class="btn btn-primary flex-shrink-0">
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
                <a href="#" data-click="openQrCode"
                   class="btn btn-secondary w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h.01M4 20h4M12 8a4 4 0 100-8 4 4 0 000 8zm8-4a4 4 0 11-8 0 4 4 0 018 0zM4 4h4v4H4V4z"/></svg>
                    QR Code
                </a>
                <a href="#" data-click="copyEmbedSnippet"
                   class="btn btn-secondary w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    Embed code
                </a>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title"
         style="display:none;"
         class="fixed inset-0 z-[60] items-center justify-center overflow-y-auto p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-gray-800 border border-gray-600/50 rounded-xl p-8 max-w-md w-full mx-4 shadow-modal">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-xl bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div>
                    <h3 id="delete-modal-title" class="modal-title">Delete Gallery</h3>
                    <p class="text-sm text-gray-400">This action cannot be undone.</p>
                </div>
            </div>
            <p class="text-gray-300 mb-6">Are you sure you want to permanently delete <span id="delete-gallery-name" class="font-semibold text-white"></span>? All images and analytics data will be lost.</p>
            <form id="delete-form" method="POST">
                @csrf
                @method('DELETE')
                <div class="flex gap-3 justify-end">
                    <button type="button" data-click="closeModalById" data-arg="delete-modal"
                            class="btn btn-secondary">
                        Cancel
                    </button>
                    <button type="submit"
                            class="btn btn-danger">
                        Yes, Delete Gallery
                    </button>
                </div>
            </form>
        </div>
    </div>

    <x-upgrade-modal />

    <script nonce="@nonce">
        function confirmDelete(galleryId, galleryTitle) {
            document.getElementById('delete-gallery-name').textContent = galleryTitle;
            document.getElementById('delete-form').action = '/admin/galleries/' + galleryId;
            openModal('delete-modal');
        }

        function shareGallery(url, title) {
            document.getElementById('share-url').value = url;
            document.getElementById('share-title').textContent = title;
            document.getElementById('copy-btn-text').textContent = 'Copy';
            openModal('share-modal');
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

        // ITERATION-3: Escape / backdrop click / Tab trap / scroll lock for
        // both modals are handled by the shared modal system in app.js —
        // the page-local Escape sweeper + backdrop helper were removed
        // (they also stacked a listener on every Turbo visit).

        // Open QR code in new tab — the route returns a PNG
        function openQrCode(el, e) {
            if (e && e.preventDefault) e.preventDefault();
            const url = document.getElementById('share-url').value;
            // Extract the slug from the share URL
            const match = url.match(/\/gallery\/([^\/?#]+)/);
            if (!match) return;
            const slug = match[1];
            window.open('/gallery/' + slug + '/qr', '_blank', 'width=640,height=640');
        }

        // Copy an iframe embed snippet for embedding the gallery elsewhere
        function copyEmbedSnippet(el, e) {
            if (e && e.preventDefault) e.preventDefault();
            const url = document.getElementById('share-url').value + '?embed=1';
            const snippet = `<iframe src="${url}" width="1024" height="640" style="border:0;max-width:100%;" allow="fullscreen; autoplay" loading="lazy" title="Exospace 3D Gallery"></iframe>`;
            navigator.clipboard.writeText(snippet).then(() => {
                const btn = el;
                const original = btn.innerHTML;
                btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copied!';
                setTimeout(() => { btn.innerHTML = original; }, 2000);
            }).catch(() => {
                // Clipboard unavailable — surface the snippet instead of failing silently.
                window.toast('Clipboard unavailable — copy the URL from the field above', 'error');
            });
        }
    </script>

</x-app-layout>