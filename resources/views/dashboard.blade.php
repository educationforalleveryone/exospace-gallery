<x-app-layout>
    
    @php
        $galleriesCount = Auth::user()->galleries()->count();
        $totalViews     = Auth::user()->galleries()->sum('view_count');
        $recentGalleries = Auth::user()->galleries()->latest()->take(5)->get();
        $onboardingComplete = ($galleriesCount > 0 && $totalViews > 0);
        $galleryPercent = Auth::user()->max_galleries > 0
            ? min(($galleriesCount / Auth::user()->max_galleries) * 100, 100) : 0;
        $isAtLimit = !Auth::user()->canCreateGallery();
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-bold text-lg text-gray-100 leading-tight tracking-tight">Dashboard</h2>
                <p class="text-xs text-gray-500 mt-0.5 hidden sm:block">
                    <kbd class="px-1 py-0.5 bg-gray-700 rounded text-gray-400 text-[10px] font-mono">G</kbd>
                    <kbd class="px-1 py-0.5 bg-gray-700 rounded text-gray-400 text-[10px] font-mono">N</kbd>
                    new gallery &nbsp;·&nbsp;
                    <kbd class="px-1 py-0.5 bg-gray-700 rounded text-gray-400 text-[10px] font-mono">G</kbd>
                    <kbd class="px-1 py-0.5 bg-gray-700 rounded text-gray-400 text-[10px] font-mono">L</kbd>
                    all galleries
                </p>
            </div>
            <a href="{{ route('admin.galleries.create') }}"
               aria-label="Create new gallery"
               class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 px-5 py-2.5 rounded-lg font-semibold text-sm hover:from-purple-500 hover:to-indigo-500 transition-all duration-200 hover:shadow-lg hover:shadow-purple-900/40 active:scale-95 focus-visible:ring-2 focus-visible:ring-purple-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                Create Gallery
            </a>
        </div>
    </x-slot>

    {{-- P2-14 FIX: Removed the welcome modal (System A). The inline "Getting
         Started" checklist below (System B) is the single source of truth
         for onboarding state. Both systems used the same localStorage key
         'exospace_onboarded' — dismissing either suppressed both, which was
         confusing. Now only the inline checklist controls onboarding display. --}}
    <div class="py-8 sm:py-10"
         x-data="{
            showUpgradeModal: false
         }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <!-- Greeting -->
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl sm:text-2xl font-bold text-gray-100 tracking-tight">
                        Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ Str::before(Auth::user()->name, ' ') }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        {{ $galleriesCount > 0 ? 'Here\'s your activity at a glance.' : 'Let\'s get your first gallery up.' }}
                    </p>
                </div>
                @if($galleriesCount > 0)
                <span class="hidden sm:inline-flex items-center gap-1.5 text-xs text-gray-500 bg-gray-800 border border-gray-700 px-3 py-1.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
                    Active
                </span>
                @endif
            </div>

            <!-- Onboarding Progress (new users only) -->
            @if(!$onboardingComplete)
            <div class="bg-gradient-to-r from-purple-900/20 to-indigo-900/20 border border-purple-500/25 rounded-xl p-5"
                 role="region" aria-label="Getting started checklist">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-purple-400 font-semibold uppercase tracking-wider mb-2">Getting Started</p>
                        <div class="space-y-2.5">
                            <!-- Step 1: Account -->
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-green-500/20 border border-green-500/60 flex items-center justify-center" aria-hidden="true">
                                    <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                </span>
                                <span class="text-gray-400 line-through">Create account</span>
                            </div>
                            <!-- Step 2: Gallery -->
                            <div class="flex items-center gap-3 text-sm">
                                @if($galleriesCount > 0)
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-green-500/20 border border-green-500/60 flex items-center justify-center" aria-hidden="true">
                                        <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    </span>
                                    <span class="text-gray-400 line-through">Create first gallery</span>
                                @else
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-purple-600 border border-purple-400 flex items-center justify-center animate-pulse" aria-current="step">
                                        <span class="text-white text-[10px] font-bold">2</span>
                                    </span>
                                    <span class="text-gray-100 font-medium">Create your first gallery</span>
                                    <span class="text-purple-400 text-xs">← next</span>
                                @endif
                            </div>
                            <!-- Step 3: Share -->
                            <div class="flex items-center gap-3 text-sm">
                                @if($totalViews > 0)
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-green-500/20 border border-green-500/60 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    </span>
                                    <span class="text-gray-400 line-through">Share & get first view</span>
                                @elseif($galleriesCount > 0)
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-purple-600 border border-purple-400 flex items-center justify-center animate-pulse" aria-current="step">
                                        <span class="text-white text-[10px] font-bold">3</span>
                                    </span>
                                    <span class="text-gray-100 font-medium">Share & get first view</span>
                                    <span class="text-purple-400 text-xs">← next</span>
                                @else
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-gray-700 border border-gray-600 flex items-center justify-center">
                                        <span class="text-gray-400 text-[10px] font-bold">3</span>
                                    </span>
                                    <span class="text-gray-500">Share your 3D exhibition</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <!-- CTA -->
                    @if(!$isAtLimit)
                        <a href="{{ route('admin.galleries.create') }}"
                           class="flex-shrink-0 inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-500 text-white font-semibold px-4 py-2.5 rounded-lg transition-all duration-200 text-sm active:scale-95 hover:shadow-lg hover:shadow-purple-900/40">
                            Continue
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    @else
                        <a href="/pricing"
                           class="flex-shrink-0 inline-flex items-center gap-2 bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-400 hover:to-amber-400 text-white font-semibold px-4 py-2.5 rounded-lg transition-all duration-200 text-sm active:scale-95">
                            Upgrade →
                        </a>
                    @endif
                </div>
            </div>
            @endif

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Total Galleries -->
                <div class="bg-gray-800 rounded-xl border border-gray-700/60 p-5 card-lift group" role="region" aria-label="Gallery count">
                    <div class="flex items-start justify-between mb-3">
                        <div class="bg-purple-600/15 w-10 h-10 rounded-lg flex items-center justify-center group-hover:bg-purple-600/25 transition-colors" aria-hidden="true">
                            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <a href="{{ route('admin.galleries.index') }}" class="text-xs text-gray-500 hover:text-purple-400 transition-colors opacity-0 group-hover:opacity-100" aria-label="View all galleries">View all →</a>
                    </div>
                    <div class="text-3xl font-bold text-gray-100 tabular-nums" aria-label="{{ $galleriesCount }} galleries">{{ $galleriesCount }}</div>
                    <div class="text-sm text-gray-400 mt-0.5">Galleries</div>
                    @if(Auth::user()->max_galleries > 0)
                    <div class="mt-3 w-full bg-gray-700/60 h-1 rounded-full overflow-hidden" role="progressbar" aria-valuenow="{{ round($galleryPercent) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Gallery quota used">
                        <div class="bg-gradient-to-r from-purple-500 to-indigo-500 h-1 rounded-full progress-fill" style="width: {{ $galleryPercent }}%"></div>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-1">{{ $galleriesCount }} of {{ Auth::user()->max_galleries }} used</p>
                    @endif
                </div>

                <!-- Total Views -->
                <div class="bg-gray-800 rounded-xl border border-gray-700/60 p-5 card-lift group" role="region" aria-label="Total views">
                    <div class="flex items-start justify-between mb-3">
                        <div class="bg-indigo-600/15 w-10 h-10 rounded-lg flex items-center justify-center group-hover:bg-indigo-600/25 transition-colors" aria-hidden="true">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="text-3xl font-bold text-gray-100 tabular-nums" aria-label="{{ number_format($totalViews) }} total views">{{ number_format($totalViews) }}</div>
                    <div class="text-sm text-gray-400 mt-0.5">Total Views</div>
                    @if($totalViews > 0)
                    <p class="text-[11px] text-green-400 mt-2 flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        People are viewing your work
                    </p>
                    @else
                    <p class="text-[11px] text-gray-500 mt-2">Share your gallery to get views</p>
                    @endif
                </div>

                <!-- Plan & Quota -->
                @php
                    $imgCount = Auth::user()->currentImageCount();
                    $imgMax = Auth::user()->max_images * max($galleriesCount, 1);
                    $galleryPct = $galleryPercent;
                    $nearLimit = $galleryPercent >= 80;
                @endphp
                <div class="bg-gray-800 rounded-xl border {{ $nearLimit ? 'border-orange-600/40' : 'border-gray-700/60' }} p-5 card-lift group" role="region" aria-label="Plan status">
                    <div class="flex items-start justify-between mb-3">
                        <div class="bg-blue-600/15 w-10 h-10 rounded-lg flex items-center justify-center group-hover:bg-blue-600/25 transition-colors" aria-hidden="true">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                        </div>
                        @if(Auth::user()->plan === 'free')
                            <span class="text-xs bg-gray-700 text-gray-400 px-2 py-0.5 rounded-full border border-gray-600">Free</span>
                        @elseif(Auth::user()->plan === 'pro')
                            <span class="text-xs bg-purple-600/20 text-purple-300 px-2 py-0.5 rounded-full border border-purple-500/30">Pro</span>
                        @else
                            <span class="text-xs bg-indigo-600/20 text-indigo-300 px-2 py-0.5 rounded-full border border-indigo-500/30">Studio</span>
                        @endif
                    </div>

                    <div class="text-sm font-semibold text-gray-200 mb-2.5">
                        @if(Auth::user()->plan === 'free') Free Plan @elseif(Auth::user()->plan === 'pro') Pro Plan @else Studio Plan @endif
                    </div>

                    {{-- Gallery quota bar --}}
                    <div class="space-y-1.5 mb-3">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-400">Galleries</span>
                            <span class="{{ $nearLimit ? 'text-orange-400 font-semibold' : 'text-gray-300' }}">
                                {{ $galleriesCount }}&thinsp;/&thinsp;{{ Auth::user()->max_galleries }}
                                @if($nearLimit && !$isAtLimit) <span class="text-orange-400">— almost full</span> @endif
                                @if($isAtLimit) <span class="text-red-400">— limit reached</span> @endif
                            </span>
                        </div>
                        <div class="w-full bg-gray-700/60 h-1.5 rounded-full overflow-hidden">
                            <div class="h-1.5 rounded-full transition-all duration-500 {{ $isAtLimit ? 'bg-red-500' : ($nearLimit ? 'bg-orange-400' : 'bg-gradient-to-r from-purple-500 to-indigo-500') }}"
                                 style="width: {{ $galleryPercent }}%"></div>
                        </div>
                    </div>

                    <div class="flex justify-between text-xs text-gray-400">
                        <span>Images per gallery</span>
                        <span class="text-gray-300 font-medium">{{ Auth::user()->max_images }}</span>
                    </div>

                    @if(Auth::user()->plan === 'free')
                    <div class="mt-4 pt-3 border-t border-gray-700/50">
                        @if($isAtLimit)
                        <a href="/pricing" class="flex items-center justify-center gap-1.5 w-full bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-400 hover:to-amber-400 text-white text-xs font-semibold py-2 rounded-lg transition-all duration-200 active:scale-95">
                            You've hit the limit — Upgrade
                        </a>
                        @else
                        <a href="/pricing" class="flex items-center justify-center gap-1.5 w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-semibold py-2 rounded-lg transition-all duration-200 active:scale-95">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            Upgrade to Pro
                        </a>
                        @endif
                    </div>
                    @endif
                </div>

            <!-- Recent Galleries or Empty State -->
            @if($galleriesCount > 0)
            <div class="bg-gray-800 rounded-xl border border-gray-700/60 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-700/60 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-200">Recent Galleries</h3>
                    <a href="{{ route('admin.galleries.index') }}" class="text-xs text-purple-400 hover:text-purple-300 transition-colors font-medium">View all →</a>
                </div>
                <ul role="list" class="divide-y divide-gray-700/40">
                    @foreach($recentGalleries as $gallery)
                    <li class="flex items-center gap-4 px-5 py-4 hover:bg-gray-700/30 transition-colors group">
                        <!-- Status dot -->
                        <span class="flex-shrink-0 w-2 h-2 rounded-full {{ $gallery->is_published ? 'bg-green-400' : 'bg-gray-500' }}"
                              data-tooltip="{{ $gallery->is_published ? 'Published' : 'Draft' }}"
                              aria-label="{{ $gallery->is_published ? 'Published' : 'Draft' }}"></span>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h4 class="text-sm font-semibold text-gray-100 truncate">{{ $gallery->title }}</h4>
                                @if($gallery->pin)
                                <span class="text-[10px] bg-yellow-900/40 text-yellow-400 border border-yellow-700/40 px-1.5 py-0.5 rounded font-medium" aria-label="PIN protected">PIN</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                <span>{{ $gallery->images()->count() }} image{{ $gallery->images()->count() !== 1 ? 's' : '' }}</span>
                                <span aria-label="{{ $gallery->view_count }} views">{{ number_format($gallery->view_count) }} views</span>
                                <span>{{ $gallery->created_at->diffForHumans() }}</span>
                            </div>
                        </div>

                        <!-- Actions (visible on hover on desktop, always on mobile) -->
                        <div class="flex items-center gap-2 flex-shrink-0 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                            <a href="{{ route('gallery.view', $gallery->slug) }}"
                               target="_blank" rel="noopener"
                               aria-label="View gallery {{ $gallery->title }} in new tab"
                               data-tooltip="View live"
                               class="text-xs px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded-lg transition-colors font-medium">
                                View
                            </a>
                            <a href="{{ route('admin.galleries.edit', $gallery) }}"
                               aria-label="Edit gallery {{ $gallery->title }}"
                               data-tooltip="Edit"
                               class="text-xs px-3 py-1.5 bg-purple-600/80 hover:bg-purple-600 text-white rounded-lg transition-colors font-medium">
                                Edit
                            </a>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>

            @else
            <!-- Empty State — clear call to action -->
            <div class="bg-gray-800 rounded-xl border border-dashed border-gray-600 p-10 text-center" role="region" aria-label="No galleries yet">
                <div class="mx-auto w-16 h-16 bg-purple-600/10 rounded-full flex items-center justify-center mb-4" aria-hidden="true">
                    <svg class="w-8 h-8 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-100 mb-2">No galleries yet</h3>
                <p class="text-sm text-gray-400 mb-6 max-w-xs mx-auto">Upload your artwork and turn it into an immersive 3D walkthrough in minutes.</p>
                <a href="{{ route('admin.galleries.create') }}"
                   class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 px-6 py-3 rounded-lg font-semibold transition-all duration-200 hover:shadow-lg hover:shadow-purple-900/40 active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    Create Your First Gallery
                </a>
            </div>
            @endif

        </div>

        {{-- P2-14: Welcome modal removed — inline checklist is the single
             onboarding source of truth. --}}

        <!-- Upgrade limit modal -->
        <div id="upgrade-modal"
             class="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 hidden items-center justify-center p-4"
             role="dialog" aria-modal="true" aria-labelledby="upgrade-heading">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl max-w-sm w-full shadow-2xl p-6 text-center relative">
                <button onclick="closeUpgradeModal()"
                        class="absolute top-3 right-3 text-gray-500 hover:text-gray-300 transition"
                        aria-label="Close">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div class="w-12 h-12 bg-purple-500/10 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>

                <h3 id="upgrade-heading" class="text-lg font-bold text-white mb-1">You've used your 1 gallery</h3>
                <p class="text-sm text-gray-400 mb-4">Pro unlocks unlimited galleries, more images, background music, and exhibition scheduling.</p>

                <div class="bg-gray-800 rounded-xl p-3 mb-5 text-left space-y-2">
                    <div class="flex items-center gap-2 text-xs text-gray-300">
                        <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        Unlimited galleries
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-300">
                        <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        50 images per gallery (free: 10)
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-300">
                        <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        Background music + exhibition scheduling
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-300">
                        <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        No watermark — $29 one-time
                    </div>
                </div>

                <div class="space-y-2">
                    <a href="/pricing" class="block w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-2.5 rounded-xl transition text-sm active:scale-95">
                        See Plans — from $29
                    </a>
                    <button onclick="closeUpgradeModal()"
                            class="block w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">
                        Not now
                    </button>
                </div>
            </div>
        </div>
        <script>
        function showUpgradeModal() {
            const m = document.getElementById('upgrade-modal');
            m.style.display = 'flex';
            m.classList.add('flex');
            m.addEventListener('keydown', e => { if (e.key === 'Escape') closeUpgradeModal(); });
        }
        function closeUpgradeModal() {
            const m = document.getElementById('upgrade-modal');
            m.style.display = 'none';
            m.classList.remove('flex');
        }
        document.getElementById('upgrade-modal').addEventListener('click', e => {
            if (e.target === e.currentTarget) closeUpgradeModal();
        });
        </script>

    </div>
</x-app-layout>