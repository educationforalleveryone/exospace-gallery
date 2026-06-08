<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                    @if($team)
                        {{ $team->name }} &mdash; Dashboard
                    @else
                        Dashboard
                    @endif
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    Welcome back, <span class="text-gray-300 font-medium">{{ $user->name }}</span>
                    @if($team)
                        &nbsp;&middot;&nbsp;
                        <span class="text-gray-500">
                            {{ ucfirst($user->teamRole($team)) }} &middot; Team workspace
                        </span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                @if($user->canCreateGallery() || $team)
                    <a href="{{ route('admin.galleries.create') }}"
                       class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        New Gallery
                    </a>
                @else
                    <button onclick="document.getElementById('upgrade-modal').style.display='flex'"
                            class="inline-flex items-center gap-2 bg-gray-700 hover:bg-gray-600 border border-gray-600 text-gray-300 font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                        <svg class="w-4 h-4 text-purple-400" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        Upgrade to Create More
                    </button>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- ── Flash ─────────────────────────────────────────────────────── --}}
            @if(session('status'))
                <div class="p-4 bg-green-900/50 border border-green-700 text-green-200 rounded-xl text-sm">
                    {{ session('status') }}
                </div>
            @endif

            {{-- ── Onboarding Banner (first-time users only) ─────────────────── --}}
            @if(!$onboardingComplete && !$team)
            <div class="bg-gradient-to-r from-purple-900/30 to-indigo-900/30 border border-purple-500/30 rounded-xl p-5"
                 x-data="{ dismissed: localStorage.getItem('exospace_onboard_dismissed') === '1' }"
                 x-show="!dismissed"
                 x-cloak>
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex-1 space-y-3">
                        <p class="text-sm font-semibold text-purple-300">Get started — 3 steps to your first exhibition</p>
                        <div class="flex flex-wrap gap-4 text-sm">
                            {{-- Step 1 --}}
                            <div class="flex items-center gap-2 text-green-400">
                                <div class="w-5 h-5 rounded-full bg-green-500/20 border border-green-500 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                </div>
                                <span class="line-through text-green-400/70">Create account</span>
                            </div>
                            {{-- Step 2 --}}
                            <div class="flex items-center gap-2 {{ $galleriesCount > 0 ? 'text-green-400' : 'text-gray-100' }}">
                                @if($galleriesCount > 0)
                                    <div class="w-5 h-5 rounded-full bg-green-500/20 border border-green-500 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    </div>
                                    <span class="line-through text-green-400/70">Create first gallery</span>
                                @else
                                    <div class="w-5 h-5 rounded-full bg-purple-600 border border-purple-400 flex items-center justify-center flex-shrink-0 animate-pulse">
                                        <span class="text-white text-xs font-bold">2</span>
                                    </div>
                                    <span class="font-semibold">Create first gallery</span>
                                    <span class="text-purple-400 text-xs">← now</span>
                                @endif
                            </div>
                            {{-- Step 3 --}}
                            <div class="flex items-center gap-2 {{ $totalViews > 0 ? 'text-green-400' : ($galleriesCount > 0 ? 'text-gray-100' : 'text-gray-500') }}">
                                @if($totalViews > 0)
                                    <div class="w-5 h-5 rounded-full bg-green-500/20 border border-green-500 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    </div>
                                    <span class="line-through text-green-400/70">Share & get first view</span>
                                @elseif($galleriesCount > 0)
                                    <div class="w-5 h-5 rounded-full bg-purple-600 border border-purple-400 flex items-center justify-center flex-shrink-0 animate-pulse">
                                        <span class="text-white text-xs font-bold">3</span>
                                    </div>
                                    <span class="font-semibold">Share & get first view</span>
                                    <span class="text-purple-400 text-xs">← now</span>
                                @else
                                    <div class="w-5 h-5 rounded-full bg-gray-700 border border-gray-600 flex items-center justify-center flex-shrink-0">
                                        <span class="text-gray-400 text-xs font-bold">3</span>
                                    </div>
                                    <span>Share & get first view</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        @if($galleriesCount === 0)
                            <a href="{{ route('admin.galleries.create') }}"
                               class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition">
                                Create Gallery →
                            </a>
                        @endif
                        <button @click="localStorage.setItem('exospace_onboard_dismissed','1'); dismissed=true"
                                class="text-gray-500 hover:text-gray-300 transition p-1.5 rounded-lg hover:bg-gray-700"
                                title="Dismiss">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            @endif

            {{-- ── Stat Cards ────────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                <x-dashboard.stat-card
                    label="Total Galleries"
                    :value="$galleriesCount"
                    icon="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                    color="purple"
                    :href="route('admin.galleries.index')"
                >
                    @if($galleriesCount > 0)
                        <span class="text-xs text-gray-500">
                            <span class="text-green-400 font-medium">{{ $activeCount }}</span> live
                            &middot;
                            <span class="text-gray-500">{{ $draftCount }}</span> draft
                        </span>
                    @endif
                </x-dashboard.stat-card>

                <x-dashboard.stat-card
                    label="Total Views"
                    :value="number_format($totalViews)"
                    icon="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                    color="indigo"
                >
                    @if($totalViews > 0)
                        <span class="text-xs text-gray-500">all time</span>
                    @endif
                </x-dashboard.stat-card>

                <x-dashboard.stat-card
                    label="Live Galleries"
                    :value="$activeCount"
                    icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                    color="green"
                >
                    @if($galleriesCount > 0)
                        <span class="text-xs {{ $activeCount === $galleriesCount ? 'text-green-400' : 'text-gray-500' }}">
                            of {{ $galleriesCount }}
                        </span>
                    @endif
                </x-dashboard.stat-card>

                {{-- Quota card — only shown for personal context --}}
                @if(!$team)
                <x-dashboard.stat-card
                    label="Gallery Quota"
                    :value="$personalGalleriesCount . ' / ' . $user->max_galleries"
                    icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                    :color="$galleryQuotaPercent >= 90 ? 'amber' : 'blue'"
                    :sub="$user->isPro() ? 'Pro plan' : 'Free plan'"
                    :subColor="$user->isPro() ? 'green' : 'gray'"
                >
                    @if($galleryQuotaPercent >= 90 && !$user->isPro())
                        <span class="text-xs text-amber-400 font-medium">Near limit</span>
                    @endif
                </x-dashboard.stat-card>
                @else
                {{-- Team context: show member count instead --}}
                <x-dashboard.stat-card
                    label="Team Members"
                    :value="$team->members()->count()"
                    icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
                    color="blue"
                    :href="route('admin.teams.show', $team)"
                >
                    <span class="text-xs text-gray-500">{{ ucfirst($user->teamRole($team)) }}</span>
                </x-dashboard.stat-card>
                @endif

            </div>

            {{-- ── Middle row: Activity + Quick Actions ─────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Views sparkline --}}
                <div class="lg:col-span-2">
                    <x-dashboard.card title="Activity">
                        @if($totalViews > 0)
                            <x-dashboard.sparkline :data="$viewsChart" label="Views — last 7 days"/>
                        @else
                            <div class="flex flex-col items-center justify-center py-6 text-center">
                                <svg class="w-10 h-10 text-gray-700 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                <p class="text-sm text-gray-500">No views yet. Share your gallery to see activity here.</p>
                            </div>
                        @endif
                    </x-dashboard.card>
                </div>

                {{-- Quick Actions --}}
                <x-dashboard.card title="Quick Actions">
                    <div class="grid grid-cols-2 gap-3">
                        <x-dashboard.quick-action
                            :href="route('admin.galleries.create')"
                            icon="M12 4v16m8-8H4"
                            label="New Gallery"
                            description="Start fresh"
                            color="purple"
                            :disabled="!$user->canCreateGallery() && !$team"
                            :onclick="!$user->canCreateGallery() && !$team ? 'document.getElementById(\'upgrade-modal\').style.display=\'flex\'' : null"
                        />
                        <x-dashboard.quick-action
                            :href="route('admin.galleries.index')"
                            icon="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                            label="All Galleries"
                            description="Manage existing"
                            color="blue"
                        />
                        <x-dashboard.quick-action
                            :href="route('admin.teams.index')"
                            icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
                            label="Teams"
                            description="Collaborate"
                            color="green"
                        />
                        @if($user->isPro())
                            <x-dashboard.quick-action
                                :href="route('profile.edit')"
                                icon="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                label="Profile"
                                description="Account settings"
                                color="amber"
                            />
                        @else
                            <x-dashboard.quick-action
                                href="/pricing"
                                icon="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"
                                label="Upgrade"
                                description="Unlock more"
                                color="amber"
                            />
                        @endif
                    </div>
                </x-dashboard.card>

            </div>

            {{-- ── Bottom row: Recent Galleries + Top Gallery ────────────────── --}}
            @if($galleriesCount > 0)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Recent Galleries list --}}
                <div class="lg:col-span-2">
                    <x-dashboard.card
                        title="Recent Galleries"
                        :action="$galleriesCount > 5 ? ['label' => 'View all →', 'href' => route('admin.galleries.index')] : null"
                        :padding="false"
                    >
                        <div class="divide-y divide-gray-700/60">
                            @forelse($recentGalleries as $gallery)
                            <div class="flex items-center gap-4 px-6 py-4 hover:bg-gray-700/30 transition group">
                                {{-- Thumbnail or placeholder --}}
                                @php $cover = $gallery->images()->first(); @endphp
                                <div class="w-12 h-12 rounded-lg overflow-hidden flex-shrink-0 bg-gray-700">
                                    @if($cover)
                                        <img src="{{ asset($cover->path) }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <h4 class="text-sm font-semibold text-gray-100 truncate">{{ $gallery->title }}</h4>
                                        @if($gallery->is_active)
                                            <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full bg-green-400" title="Live"></span>
                                        @else
                                            <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full bg-gray-500" title="Draft"></span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-gray-500">
                                        <span>{{ $gallery->images_count }} images</span>
                                        <span>&middot;</span>
                                        <span>{{ number_format($gallery->view_count) }} views</span>
                                        <span>&middot;</span>
                                        <span>{{ $gallery->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition flex-shrink-0">
                                    <a href="{{ route('gallery.view', $gallery->slug) }}"
                                       target="_blank"
                                       class="text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-3 py-1.5 rounded-lg transition"
                                       title="View live">
                                        View
                                    </a>
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}"
                                       class="text-xs bg-purple-700/40 hover:bg-purple-700/70 text-purple-300 px-3 py-1.5 rounded-lg transition"
                                       title="Edit gallery">
                                        Edit
                                    </a>
                                </div>
                            </div>
                            @empty
                            <div class="px-6 py-8 text-center text-sm text-gray-500">No galleries yet.</div>
                            @endforelse
                        </div>
                    </x-dashboard.card>
                </div>

                {{-- Top Gallery highlight + Quota --}}
                <div class="space-y-6">

                    {{-- Top gallery --}}
                    @if($topGallery)
                    <x-dashboard.card title="Top Gallery">
                        @php $topCover = $topGallery->images()->first(); @endphp
                        @if($topCover)
                            <div class="aspect-video rounded-lg overflow-hidden mb-4 -mt-1">
                                <img src="{{ asset($topCover->path) }}" alt="{{ $topGallery->title }}"
                                     class="w-full h-full object-cover">
                            </div>
                        @endif
                        <h4 class="font-semibold text-gray-100 mb-1 truncate">{{ $topGallery->title }}</h4>
                        <div class="flex items-center gap-3 text-xs text-gray-500 mb-4">
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                {{ number_format($topGallery->view_count) }} views
                            </span>
                            <span class="{{ $topGallery->is_active ? 'text-green-400' : 'text-gray-500' }}">
                                {{ $topGallery->is_active ? 'Live' : 'Draft' }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <a href="{{ route('gallery.view', $topGallery->slug) }}" target="_blank"
                               class="text-center text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 py-2 rounded-lg transition">
                                View
                            </a>
                            <a href="{{ route('admin.galleries.analytics', $topGallery) }}"
                               class="text-center text-xs bg-indigo-700/40 hover:bg-indigo-700/60 text-indigo-300 py-2 rounded-lg transition">
                                Analytics
                            </a>
                        </div>
                    </x-dashboard.card>
                    @endif

                    {{-- Quota bar (personal context only) --}}
                    @if(!$team)
                    <x-dashboard.card>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm font-medium text-gray-300">Gallery Quota</span>
                            @if($user->isPro())
                                <span class="text-xs bg-purple-600/20 text-purple-300 border border-purple-600/30 px-2 py-0.5 rounded-full">Pro</span>
                            @else
                                <span class="text-xs bg-gray-700 text-gray-400 px-2 py-0.5 rounded-full">Free</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                            <span>{{ $personalGalleriesCount }} used</span>
                            <span>{{ $user->max_galleries }} max</span>
                        </div>
                        <div class="w-full bg-gray-700 h-2 rounded-full overflow-hidden mb-3">
                            <div class="h-2 rounded-full transition-all duration-500 {{ $galleryQuotaPercent >= 90 ? 'bg-gradient-to-r from-amber-500 to-red-500' : 'bg-gradient-to-r from-purple-500 to-indigo-500' }}"
                                 style="width: {{ $galleryQuotaPercent }}%"></div>
                        </div>
                        @if(!$user->isPro())
                            <a href="/pricing"
                               class="block text-center bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-xs font-semibold py-2 rounded-lg transition mt-1">
                                Upgrade to Pro
                            </a>
                        @endif
                    </x-dashboard.card>
                    @endif

                </div>

            </div>
            @else
            {{-- ── Empty state: no galleries yet ─────────────────────────────── --}}
            <x-dashboard.card>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="bg-gradient-to-br from-purple-600/20 to-indigo-600/20 w-20 h-20 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-purple-500/20">
                        <svg class="w-10 h-10 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-100 mb-2">
                        @if($team) No galleries in {{ $team->name }} yet @else Create your first gallery @endif
                    </h3>
                    <p class="text-gray-400 text-sm mb-6 max-w-sm">
                        @if($team)
                            This team workspace has no galleries. Create one to share stunning 3D exhibitions with your team.
                        @else
                            Transform your images into immersive 3D exhibitions. It only takes a few minutes.
                        @endif
                    </p>
                    <a href="{{ route('admin.galleries.create') }}"
                       class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold px-7 py-3 rounded-xl transition transform hover:scale-105">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Create Gallery
                    </a>
                </div>
            </x-dashboard.card>
            @endif

        </div>
    </div>

    {{-- ── Upgrade Modal ────────────────────────────────────────────────── --}}
    <div id="upgrade-modal"
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4"
         style="display: none;">
        <div class="bg-gradient-to-br from-gray-800 to-gray-900 border border-purple-500/30 rounded-2xl max-w-md w-full shadow-2xl relative overflow-hidden">
            <button onclick="document.getElementById('upgrade-modal').style.display='none'"
                    class="absolute top-4 right-4 text-gray-400 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <div class="p-8 text-center">
                <div class="bg-purple-600/20 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-purple-400" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">You've reached your gallery limit</h3>
                <p class="text-gray-400 text-sm mb-6">Upgrade to Pro to create unlimited 3D exhibitions and unlock all premium features.</p>
                <div class="space-y-3">
                    <a href="/pricing"
                       class="block w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-3 rounded-xl transition">
                        View Pricing Plans
                    </a>
                    <button onclick="document.getElementById('upgrade-modal').style.display='none'"
                            class="block w-full bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold py-3 rounded-xl transition">
                        Maybe Later
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── One-time welcome modal (new users with zero galleries) ─────────── --}}
    @if($galleriesCount === 0 && !$team)
    <div x-data="{ show: !localStorage.getItem('exospace_welcomed') }"
         x-show="show"
         x-cloak
         @click.self="localStorage.setItem('exospace_welcomed','1'); show=false"
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <div @click.stop
             class="bg-gray-800 border border-purple-500/30 rounded-2xl max-w-lg w-full shadow-2xl overflow-hidden"
             x-transition:enter="transition ease-out duration-300 delay-75"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-8 py-6 text-center">
                <div class="w-14 h-14 bg-white/20 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white">Welcome to Exospace!</h2>
                <p class="text-purple-100 text-sm mt-1">Your 3D exhibition platform is ready.</p>
            </div>
            <div class="p-8">
                <ul class="space-y-3 mb-6">
                    <li class="flex items-start gap-3 text-sm">
                        <div class="w-5 h-5 rounded-full bg-purple-600/20 border border-purple-500/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-3 h-3 text-purple-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-100">{{ $user->max_galleries }} galleries</span>
                            <span class="text-gray-400"> included with your plan</span>
                        </div>
                    </li>
                    <li class="flex items-start gap-3 text-sm">
                        <div class="w-5 h-5 rounded-full bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-3 h-3 text-indigo-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-100">{{ $user->max_images }} images per gallery</span>
                            <span class="text-gray-400"> to showcase your work</span>
                        </div>
                    </li>
                    <li class="flex items-start gap-3 text-sm">
                        <div class="w-5 h-5 rounded-full bg-blue-600/20 border border-blue-500/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-3 h-3 text-blue-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-100">Immersive 3D viewer</span>
                            <span class="text-gray-400"> — walk through like a real museum</span>
                        </div>
                    </li>
                </ul>
                <div class="flex gap-3">
                    <a href="{{ route('admin.galleries.create') }}"
                       @click="localStorage.setItem('exospace_welcomed','1'); show=false"
                       class="flex-1 text-center bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-3 rounded-xl transition">
                        Create First Gallery 🚀
                    </a>
                    <button @click="localStorage.setItem('exospace_welcomed','1'); show=false"
                            class="px-5 bg-gray-700 hover:bg-gray-600 text-gray-300 font-semibold rounded-xl transition text-sm">
                        Explore
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</x-app-layout>
