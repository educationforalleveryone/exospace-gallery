<x-app-layout>

    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-100 leading-tight flex items-center gap-2.5">
                    @if($team)
                        <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white font-bold text-xs flex-shrink-0">{{ strtoupper(substr($team->name, 0, 1)) }}</span>
                        <span>{{ $team->name }}</span>
                    @else
                        Dashboard
                    @endif
                </h2>
                <p class="text-sm text-gray-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
                    <span>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }},</span>
                    <span class="text-gray-300 font-medium">{{ $user->name }}</span>
                    @if($team)
                        <span class="text-gray-700">·</span>
                        @php $dashRole = $user->teamRole($team); @endphp
                        <span class="text-{{ $dashRole === 'viewer' ? 'gray' : 'indigo' }}-400 capitalize">{{ ucfirst($dashRole) }}</span>
                        @if($dashRole === 'viewer')
                            <span class="text-gray-700">·</span>
                            <span class="text-gray-500 text-xs">View only</span>
                        @endif
                        <span class="text-gray-700">·</span>
                        <form action="{{ route('admin.teams.switch-personal') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-600 hover:text-gray-400 transition text-xs underline underline-offset-2">Personal workspace</button>
                        </form>
                    @else
                        <span class="mx-1.5 text-gray-700">·</span>
                        <span class="inline-flex items-center gap-1 capitalize {{ $user->plan === 'free' ? 'text-gray-500' : 'text-purple-400' }}">
                            @if($user->isPro())
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            @endif
                            {{ ucfirst($user->plan) }} plan
                        </span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                @if($user->canCreateGallery() || $team)
                    <a href="{{ route('admin.galleries.create') }}"
                       class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-semibold px-4 py-2 rounded-lg transition-all duration-150 text-sm shadow-lg shadow-purple-900/30">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        New Gallery
                    </a>
                @else
                    <button onclick="showUpgradeModal()"
                            class="inline-flex items-center gap-2 bg-gray-700 hover:bg-gray-600 border border-gray-600 text-gray-300 font-semibold px-4 py-2 rounded-lg transition text-sm">
                        <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        Upgrade
                    </button>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- ── Flash ─────────────────────────────────────────────────────── --}}
            @if(session('status'))
                <x-dashboard.alert-banner type="success" :text="session('status')"/>
            @endif

            {{-- ── Contextual Alerts ─────────────────────────────────────────── --}}
            @if(count($alerts))
            <div class="space-y-2">
                @foreach($alerts as $i => $alert)
                    <x-dashboard.alert-banner
                        :type="$alert['type']"
                        :text="$alert['text']"
                        :action="$alert['action'] ?? null"
                        :dismissKey="'alert_' . md5($alert['text'])"
                    />
                @endforeach
            </div>
            @endif

            {{-- ── Pending invitations notice ────────────────────────────────── --}}
            @if($pendingInvitations->isNotEmpty())
                <x-dashboard.alert-banner
                    type="info"
                    icon="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                    :text="$pendingInvitations->count() === 1
                        ? 'You have 1 pending team invitation waiting for a response.'
                        : 'You have ' . $pendingInvitations->count() . ' pending team invitations waiting.'"
                    :action="['label' => 'View Teams', 'href' => route('admin.teams.index')]"
                />
            @endif

            {{-- ── Share nudge: has live galleries but 0 total views ────────── --}}
            @if($hasUnsharedGallery ?? false)
            <div class="flex items-center gap-4 rounded-xl border border-indigo-500/20 bg-indigo-950/30 px-5 py-3.5">
                <svg class="w-5 h-5 text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                <p class="flex-1 text-sm text-indigo-200">
                    Your gallery is live — <span class="font-semibold">share it to get your first view.</span>
                    <span class="text-indigo-400 text-xs ml-1">Anyone with the link can explore it in 3D.</span>
                </p>
                @if($topGallery)
                <button onclick="dashboardShare('{{ route('gallery.view', $topGallery->slug) }}', '{{ addslashes($topGallery->title) }}')"
                        class="flex-shrink-0 inline-flex items-center gap-1.5 text-xs bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-3 py-2 rounded-lg transition">
                    Copy link
                </button>
                @endif
            </div>
            @endif

            {{-- ── Onboarding strip (zero-gallery users only) ────────────────── --}}
            @if($galleriesCount === 0)
            <div class="relative overflow-hidden rounded-xl border border-purple-500/25 bg-gradient-to-r from-purple-950/60 via-indigo-950/40 to-purple-950/60 p-5">
                <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(168,85,247,0.08),transparent_60%)]"></div>
                <div class="relative flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-purple-200 mb-2">
                            @if($isNewUser) Welcome! Get started in 3 steps @else Pick up where you left off @endif
                        </p>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            {{-- Step 1: done --}}
                            <div class="flex items-center gap-2 text-green-400 text-sm">
                                <div class="w-5 h-5 rounded-full bg-green-500/20 border border-green-500 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                </div>
                                <span class="line-through opacity-60">Create account</span>
                            </div>
                            {{-- Step 2 --}}
                            <div class="flex items-center gap-2 text-gray-100 text-sm font-semibold">
                                <div class="w-5 h-5 rounded-full bg-purple-600 border border-purple-400 flex items-center justify-center flex-shrink-0 animate-pulse">
                                    <span class="text-white" style="font-size:10px;font-weight:700">2</span>
                                </div>
                                Create a gallery
                            </div>
                            {{-- Step 3 --}}
                            <div class="flex items-center gap-2 text-gray-500 text-sm">
                                <div class="w-5 h-5 rounded-full bg-gray-700 border border-gray-600 flex items-center justify-center flex-shrink-0">
                                    <span class="text-gray-500" style="font-size:10px;font-weight:700">3</span>
                                </div>
                                Share &amp; get views
                            </div>
                        </div>
                    </div>
                    @if($isNewUser)
                    <p class="text-xs text-gray-500 mt-1 sm:hidden">Takes ~3 min. Upload images, pick a layout, share the link.</p>
                    @endif
                    <a href="{{ route('admin.galleries.create') }}"
                       class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-500 text-white font-semibold px-5 py-2.5 rounded-lg text-sm transition flex-shrink-0 shadow-lg shadow-purple-900/30">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        Create Gallery
                    </a>
                </div>
            </div>
            @endif

            {{-- ── Stat Cards ─────────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4" data-stat-grid>

                <x-dashboard.stat-card
                    label="Total Galleries"
                    :value="$galleriesCount"
                    icon="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                    color="purple"
                    :href="route('admin.galleries.index')"
                    :sub="$galleriesCount > 0 ? $activeCount . ' live · ' . $draftCount . ' draft' : 'No galleries yet'"
                    :subColor="$galleriesCount > 0 ? 'gray' : 'gray'"
                />

                <x-dashboard.stat-card
                    label="Views (7 days)"
                    :value="number_format($views7)"
                    icon="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                    color="indigo"
                    :trend="$viewsTrend"
                    trendLabel="vs prior 7d"
                    :badge="$viewsToday > 0 ? $viewsToday . ' today' : null"
                />

                <x-dashboard.stat-card
                    label="Live Galleries"
                    :value="$activeCount"
                    icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                    color="green"
                    :sub="$galleriesCount > 0 ? ($activeCount === $galleriesCount ? 'All published' : $draftCount . ' still draft') : null"
                    :subColor="$activeCount === $galleriesCount && $galleriesCount > 0 ? 'green' : 'gray'"
                    :href="route('admin.galleries.index')"
                />

                @if(!$team)
                {{-- Quota card --}}
                <x-dashboard.stat-card
                    label="Quota"
                    :value="$personalGalleriesCount . ' / ' . $user->max_galleries"
                    icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                    :color="$galleryQuotaPercent >= 90 ? 'amber' : ($galleryQuotaPercent >= 70 ? 'blue' : 'blue')"
                    :sub="$galleryQuotaPercent >= 90 && !$user->isPro() ? 'Near limit — upgrade?' : ucfirst($user->plan) . ' plan'"
                    :subColor="$galleryQuotaPercent >= 90 && !$user->isPro() ? 'amber' : 'gray'"
                />
                @else
                {{-- Team: member count --}}
                <x-dashboard.stat-card
                    label="Team Members"
                    :value="$team->members()->count()"
                    icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
                    color="blue"
                    :href="route('admin.teams.show', $team)"
                    :sub="ucfirst($user->teamRole($team)) . ' · ' . $team->name"
                />
                @endif

            </div>

            {{-- ── Main content grid ──────────────────────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                {{-- LEFT: Activity chart + Recent galleries ──────────────────── --}}
                <div class="lg:col-span-2 space-y-5">

                    {{-- Activity / Sparkline --}}
                    <x-dashboard.card>
                        @if($viewsChart->sum() > 0)
                            <x-dashboard.sparkline
                                :data="$viewsChart"
                                label="Views — last 7 days"
                                :today="$viewsToday"
                                :trend="$viewsTrend"
                                :href="$topGallery ? route('admin.galleries.analytics', $topGallery) : null"
                            />
                        @else
                            {{-- Empty state with CTA --}}
                            <div class="flex flex-col sm:flex-row items-center gap-5 py-2">
                                <div class="w-12 h-12 rounded-xl bg-gray-700/80 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 text-center sm:text-left">
                                    <p class="text-sm font-semibold text-gray-300 mb-0.5">No views recorded yet</p>
                                    <p class="text-xs text-gray-500">
                                        @if($galleriesCount === 0)
                                            Create your first gallery, then share it to start seeing activity here.
                                        @elseif($activeCount === 0)
                                            Publish a gallery and share the link — views will appear here within minutes.
                                        @else
                                            Share your gallery link to start getting visitors. Activity shows here in real time.
                                        @endif
                                    </p>
                                </div>
                                @if($activeCount > 0 && $topGallery)
                                    <button onclick="dashboardShare('{{ route('gallery.view', $topGallery->slug) }}', '{{ addslashes($topGallery->title) }}')"
                                            class="flex-shrink-0 inline-flex items-center gap-1.5 text-xs bg-purple-600/20 hover:bg-purple-600/40 text-purple-300 border border-purple-600/30 px-3 py-2 rounded-lg transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                                        Share gallery
                                    </button>
                                @endif
                            </div>
                        @endif
                    </x-dashboard.card>

                    {{-- Recent Galleries --}}
                    @if($galleriesCount > 0)
                    <x-dashboard.card
                        title="Recent Galleries"
                        :action="$galleriesCount > 6 ? ['label' => 'View all →', 'href' => route('admin.galleries.index')] : null"
                        :padding="false"
                    >
                        <div class="divide-y divide-gray-700/40">
                            @foreach($recentGalleries as $gallery)
                                <x-dashboard.gallery-row
                                    :gallery="$gallery"
                                    :stale="isset($staleLiveIds[$gallery->id])"
                                />
                            @endforeach
                        </div>

                        @if($galleriesCount > 6)
                        <div class="px-6 py-3 border-t border-gray-700/40">
                            <a href="{{ route('admin.galleries.index') }}"
                               class="text-xs text-gray-500 hover:text-purple-400 transition">
                                + {{ $galleriesCount - 6 }} more galleries →
                            </a>
                        </div>
                        @endif
                    </x-dashboard.card>
                    @else
                    {{-- Empty state --}}
                    <x-dashboard.card>
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-600/20 to-indigo-600/20 border border-purple-500/20 flex items-center justify-center mb-4">
                                <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <h3 class="text-base font-semibold text-gray-100 mb-1">
                                @if($team) No galleries in {{ $team->name }} yet @else No galleries yet @endif
                            </h3>
                            <p class="text-sm text-gray-500 mb-5 max-w-xs">
                                @if($team) Create a gallery for this team workspace. @else Turn your images into immersive 3D exhibitions. @endif
                            </p>
                            <a href="{{ route('admin.galleries.create') }}"
                               class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-semibold px-6 py-2.5 rounded-xl transition shadow-lg shadow-purple-900/30">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                Create Gallery
                            </a>
                        </div>
                    </x-dashboard.card>
                    @endif

                </div>

                {{-- RIGHT: Quick actions + Top gallery + Quota ────────────────── --}}
                <div class="space-y-5">

                    {{-- Quick Actions --}}
                    <x-dashboard.card title="Quick Actions">
                        <div class="grid grid-cols-2 gap-2.5">

                            @if($user->canCreateGallery() || $team)
                            <x-dashboard.quick-action
                                :href="route('admin.galleries.create')"
                                icon="M12 4v16m8-8H4"
                                label="New Gallery"
                                description="Start fresh"
                                color="purple"
                            />
                            @else
                            <x-dashboard.quick-action
                                href="/pricing"
                                icon="M13 10V3L4 14h7v7l9-11h-7z"
                                label="Upgrade"
                                description="Unlock more"
                                color="amber"
                                :onclick="null"
                            />
                            @endif

                            <x-dashboard.quick-action
                                :href="route('admin.galleries.index')"
                                icon="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                                label="Galleries"
                                description="Manage all"
                                color="blue"
                            />

                            <x-dashboard.quick-action
                                :href="route('admin.teams.index')"
                                icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
                                label="Teams"
                                description="Collaborate"
                                color="green"
                            />

                            @if($topGallery)
                            <x-dashboard.quick-action
                                :href="route('admin.galleries.analytics', $topGallery)"
                                icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                                label="Analytics"
                                description="Top gallery"
                                color="indigo"
                            />
                            @else
                            <x-dashboard.quick-action
                                :href="route('profile.edit')"
                                icon="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                label="Profile"
                                description="Settings"
                                color="indigo"
                            />
                            @endif

                        </div>
                    </x-dashboard.card>

                    {{-- Top gallery spotlight --}}
                    @if($topGallery)
                    <x-dashboard.card title="Top Gallery">
                        @php
                            $topCover = $topGallery->coverImage;
                        @endphp
                        @if($topCover)
                            <div class="aspect-video rounded-lg overflow-hidden -mt-1 mb-4 bg-gray-700">
                                <img src="{{ asset($topCover->path) }}"
                                     alt="{{ $topGallery->title }}"
                                     class="w-full h-full object-cover">
                            </div>
                        @endif
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <h4 class="font-semibold text-gray-100 text-sm truncate leading-snug">{{ $topGallery->title }}</h4>
                            <span class="flex-shrink-0 text-xs px-2 py-0.5 rounded-full {{ $topGallery->is_active ? 'bg-green-900/50 text-green-400 border border-green-800/50' : 'bg-gray-700 text-gray-500' }}">
                                {{ $topGallery->is_active ? 'Live' : 'Draft' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500 mb-4">
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                {{ number_format($topGallery->view_count) }} total views
                            </span>
                            <span>·</span>
                            <span>{{ $topGallery->images_count }} {{ Str::plural('image', $topGallery->images_count) }}</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <a href="{{ route('gallery.view', $topGallery->slug) }}" target="_blank"
                               class="flex items-center justify-center gap-1 text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 py-2 rounded-lg transition">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                View
                            </a>
                            <button onclick="dashboardShare('{{ route('gallery.view', $topGallery->slug) }}', '{{ addslashes($topGallery->title) }}')"
                                    class="flex items-center justify-center gap-1 text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 py-2 rounded-lg transition">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                                Share
                            </button>
                            <a href="{{ route('admin.galleries.analytics', $topGallery) }}"
                               class="flex items-center justify-center gap-1 text-xs bg-indigo-700/30 hover:bg-indigo-700/50 text-indigo-300 py-2 rounded-lg transition">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                Stats
                            </a>
                        </div>
                    </x-dashboard.card>
                    @endif

                    {{-- Quota / Plan card (personal context) --}}
                    @if(!$team)
                    <x-dashboard.card>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm font-semibold text-gray-200">Plan &amp; Quota</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $user->isPro() ? 'bg-purple-600/20 text-purple-300 border border-purple-600/30' : 'bg-gray-700 text-gray-400' }}">
                                {{ ucfirst($user->plan) }}
                            </span>
                        </div>

                        {{-- Quota bar --}}
                        <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                            <span>{{ $personalGalleriesCount }} of {{ $user->max_galleries }} galleries used</span>
                            <span class="{{ $galleryQuotaPercent >= 90 ? 'text-amber-400 font-semibold' : '' }}">{{ $galleryQuotaPercent }}%</span>
                        </div>
                        <div class="w-full bg-gray-700 h-1.5 rounded-full overflow-hidden mb-4">
                            <div class="h-1.5 rounded-full transition-all duration-700
                                {{ $galleryQuotaPercent >= 90 ? 'bg-gradient-to-r from-amber-500 to-red-500' : 'bg-gradient-to-r from-purple-500 to-indigo-500' }}"
                                 style="width:{{ $galleryQuotaPercent }}%">
                            </div>
                        </div>

                        @if($user->isPro())
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <svg class="w-3.5 h-3.5 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Unlimited galleries · {{ $user->max_images }} images per gallery</span>
                            </div>
                            @if($user->plan_expires_at)
                            <div class="mt-2 text-xs text-gray-600">
                                Renews {{ $user->plan_expires_at->format('M j, Y') }}
                            </div>
                            @endif
                        @else
                            <div class="space-y-2">
                                <p class="text-xs text-gray-500">Free plan includes {{ $user->max_galleries }} {{ Str::plural('gallery', $user->max_galleries) }} and {{ $user->max_images }} images each.</p>
                                <a href="/pricing"
                                   class="block text-center bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-semibold py-2.5 rounded-lg transition">
                                    Upgrade to Pro →
                                </a>
                            </div>
                        @endif
                    </x-dashboard.card>
                    @endif

                    {{-- Team context: switch workspace --}}
                    @if($team)
                    <x-dashboard.card title="Workspace">
                        <div class="space-y-2">
                            {{-- Current team --}}
                            <div class="flex items-center gap-3 bg-indigo-600/10 border border-indigo-600/20 rounded-lg p-3">
                                <div class="w-8 h-8 rounded-lg bg-indigo-600/30 flex items-center justify-center flex-shrink-0">
                                    <span class="text-indigo-300 font-bold text-sm">{{ substr($team->name, 0, 1) }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-gray-100 truncate">{{ $team->name }}</div>
                                    <div class="text-xs text-gray-500">{{ ucfirst($user->teamRole($team)) }} · Active</div>
                                </div>
                                <svg class="w-4 h-4 text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <a href="{{ route('admin.teams.index') }}"
                               class="block text-center text-xs text-gray-500 hover:text-purple-400 transition py-1">
                                Switch workspace →
                            </a>
                        </div>
                    </x-dashboard.card>
                    @endif

                </div>

            </div>{{-- end main grid --}}

        </div>
    </div>

    {{-- ── Share Modal (reuses the same pattern as galleries/index) ──────── --}}
    <div id="dashboard-share-modal"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:50;align-items:center;justify-content:center;backdrop-filter:blur(4px);"
         onclick="if(event.target===this)closeDashboardShare()">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-100">Share Gallery</h3>
                <button onclick="closeDashboardShare()" class="text-gray-400 hover:text-gray-200 transition p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-400 mb-3" id="ds-title"></p>
            <div class="flex items-center gap-2 bg-gray-900 border border-gray-700 rounded-lg px-3 py-2.5 mb-4">
                <input id="ds-url" type="text" readonly
                       class="bg-transparent text-gray-300 flex-1 outline-none text-sm font-mono min-w-0"/>
                <button onclick="copyDashboardUrl()"
                        class="flex-shrink-0 flex items-center gap-1.5 bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <span id="ds-copy-label">Copy</span>
                </button>
            </div>
            <p class="text-xs text-gray-600">Anyone with this link can view the 3D gallery in their browser.</p>
        </div>
    </div>

    {{-- ── Upgrade Modal ────────────────────────────────────────────────── --}}
    <div id="upgrade-modal"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:50;align-items:center;justify-content:center;backdrop-filter:blur(4px);"
         onclick="if(event.target===this)this.style.display='none'">
        <div class="bg-gray-800 border border-purple-500/30 rounded-2xl p-8 max-w-sm w-full mx-4 text-center shadow-2xl">
            <div class="w-14 h-14 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Gallery limit reached</h3>
            <p class="text-gray-400 text-sm mb-6">Upgrade to Pro for unlimited galleries, no watermarks, and full analytics.</p>
            <a href="/pricing" class="block w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-3 rounded-xl transition mb-2">
                View Plans
            </a>
            <button onclick="this.closest('#upgrade-modal').style.display='none'"
                    class="block w-full bg-gray-700 hover:bg-gray-600 text-gray-400 text-sm py-2.5 rounded-xl transition">
                Maybe later
            </button>
        </div>
    </div>

    {{-- ── First-visit welcome modal (only for brand-new users < 48h) ─────── --}}
    @if($isNewUser && !$team)
    <div x-data="{ show: !localStorage.getItem('exospace_welcomed') }"
         x-show="show" x-cloak
         @click.self="localStorage.setItem('exospace_welcomed','1'); show=false"
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <div @click.stop
             class="bg-gray-800 border border-purple-500/30 rounded-2xl max-w-md w-full shadow-2xl overflow-hidden"
             x-transition:enter="transition ease-out duration-200 delay-50"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0">
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-8 py-6 text-center">
                <div class="w-14 h-14 bg-white/20 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-white">Welcome to Exospace!</h2>
                <p class="text-purple-200 text-sm mt-1">Your 3D gallery platform is ready.</p>
            </div>
            <div class="p-7">
                <ul class="space-y-3 mb-6 text-sm">
                    @foreach([
                        ['color' => 'purple', 'title' => $user->max_galleries . ' galleries', 'desc' => 'on your ' . ucfirst($user->plan) . ' plan'],
                        ['color' => 'indigo', 'title' => $user->max_images . ' images', 'desc' => 'per gallery'],
                        ['color' => 'blue',   'title' => 'Immersive 3D viewer', 'desc' => 'walk through like a museum'],
                    ] as $feat)
                    <li class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-{{ $feat['color'] }}-600/20 border border-{{ $feat['color'] }}-500/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3.5 h-3.5 text-{{ $feat['color'] }}-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        </div>
                        <span><span class="font-semibold text-gray-100">{{ $feat['title'] }}</span> <span class="text-gray-400">{{ $feat['desc'] }}</span></span>
                    </li>
                    @endforeach
                </ul>
                <div class="flex gap-3">
                    <a href="{{ route('admin.galleries.create') }}"
                       @click="localStorage.setItem('exospace_welcomed','1'); show=false"
                       class="flex-1 text-center bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-3 rounded-xl transition">
                        Create First Gallery →
                    </a>
                    <button @click="localStorage.setItem('exospace_welcomed','1'); show=false"
                            class="px-5 bg-gray-700 hover:bg-gray-600 text-gray-400 font-medium rounded-xl transition text-sm">
                        Skip
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <script>
    // ── Share modal ──────────────────────────────────────────────────────────
    function dashboardShare(url, title) {
        document.getElementById('ds-url').value   = url;
        document.getElementById('ds-title').textContent = title;
        document.getElementById('ds-copy-label').textContent = 'Copy';
        document.getElementById('dashboard-share-modal').style.display = 'flex';
    }
    function closeDashboardShare() {
        document.getElementById('dashboard-share-modal').style.display = 'none';
    }
    function copyDashboardUrl() {
        const input = document.getElementById('ds-url');
        const label = document.getElementById('ds-copy-label');
        navigator.clipboard.writeText(input.value).then(() => {
            label.textContent = 'Copied!';
            setTimeout(() => { label.textContent = 'Copy'; }, 2000);
        }).catch(() => {
            input.select();
            document.execCommand('copy');
            label.textContent = 'Copied!';
            setTimeout(() => { label.textContent = 'Copy'; }, 2000);
        });
    }
    function showUpgradeModal() {
        document.getElementById('upgrade-modal').style.display = 'flex';
    }

    // ── ESC to close any modal ───────────────────────────────────────────────
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        ['dashboard-share-modal', 'upgrade-modal'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    });

    // ── Auto-refresh stat cards every 60s (near-real-time) ──────────────────
    // Only refresh when the tab is visible and user has galleries
    @if($galleriesCount > 0)
    (function () {
        let timer;
        function scheduleRefresh() {
            clearTimeout(timer);
            timer = setTimeout(() => {
                if (!document.hidden) {
                    fetch(window.location.href, { headers: { 'X-Refresh': '1' } })
                        .then(r => r.text())
                        .then(html => {
                            const parser  = new DOMParser();
                            const fresh   = parser.parseFromString(html, 'text/html');
                            // Swap only the stat grid
                            const sel = '[data-stat-grid]';
                            const cur = document.querySelector(sel);
                            const nxt = fresh.querySelector(sel);
                            if (cur && nxt) cur.innerHTML = nxt.innerHTML;
                        })
                        .catch(() => {})
                        .finally(scheduleRefresh);
                } else {
                    document.addEventListener('visibilitychange', function once() {
                        document.removeEventListener('visibilitychange', once);
                        scheduleRefresh();
                    });
                }
            }, 60000);
        }
        scheduleRefresh();
    })();
    @endif
    </script>

</x-app-layout>
